<?php

namespace Observe\Laravel;

use Closure;
use ErrorException;
use GuzzleHttp\Promise\Create;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Observe\Laravel\Transport\OutageAware;
use Observe\Laravel\Transport\Transport;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class Recorder
{
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private const MAX_BODY_BYTES = 65536;

    private const AMBIENT_MAX_ITEMS = 100;

    private const AMBIENT_MAX_SECONDS = 10;

    private const MAX_ENVELOPE_BYTES = 4_194_304;

    private const MAX_PLAIN_BYTES = 1_048_576;

    private const MAX_GZIP_BYTES = 921_600;

    private const OUT_OF_MEMORY = 'Allowed memory size of';

    private const HEADROOM_BYTES = 33_554_432;

    private const RESERVE_BYTES = 1_048_576;

    private ?Execution $execution = null;

    private Buffer $ambient;

    private ?Closure $attributeResolver = null;

    private ?Request $request = null;

    private bool $busy = false;

    private bool $fatalCaptured = false;

    private int $scopes = 0;

    private ?string $reserve = null;

    private static bool $raised = false;

    private array $secretPathValues = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Transport $transport,
        private readonly Sampler $sampler,
        private readonly Envelope $envelope,
        private readonly Stacktrace $stacktrace,
        private readonly Scrubber $scrubber,
    ) {
        $this->ambient = new Buffer;
    }

    public function attributes(Closure $resolver): void
    {
        $this->attributeResolver = $resolver;
    }

    public function context(): ?TraceContext
    {
        try {
            $x = $this->execution;

            return $x === null || $x->ended() ? null : $x->trace();
        } catch (Throwable) {
            return null;
        }
    }

    public function span(string $name, Closure $callback, array $attributes = []): mixed
    {
        $x = null;
        $key = 0;

        try {
            if ($this->execution !== null && ! $this->execution->ended()) {
                $x = $this->execution;
                $key = --$this->scopes;
                $x->open('custom', Values::truncate($name, Values::MAX_NAME_BYTES * Values::CAPTURE), $key, Values::attributes($attributes, Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE), microtime(true));
            }
        } catch (Throwable) {
            $x = null;
        }

        $failed = true;

        try {
            $result = $callback();
            $failed = false;

            return $result;
        } finally {
            try {
                $x?->close($key, microtime(true), $failed);
            } catch (Throwable) {
            }
        }
    }

    public function beginRequest(Request $request): void
    {
        $this->guard(function () use ($request) {
            $root = $this->execution === null;
            $startedAt = $root ? (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true)) : microtime(true);

            if ($root) {
                $this->request = $request;
                $this->secretPathValues = [];
            }

            $this->start(Kind::Request, $request->getMethod().' (unmatched)', TraceContext::parse($request->headers->get('traceparent')), $startedAt, spl_object_id($request), [
                'http.method' => $request->getMethod(),
                'url.path' => '/'.ltrim($request->path(), '/'),
            ]);

            if ($root && $this->execution?->kind === Kind::Request) {
                $this->execution->sharedAttributes = [...$this->execution->sharedAttributes, ...ClientAgent::attributes($request->userAgent(), $this->settings->clientAgents)];
            }
        });
    }

    public function routeMatched(Route $route, Request $request): void
    {
        $this->guard(function () use ($route, $request) {
            $x = $this->openRequest();

            if ($x === null || $request !== $this->request) {
                return;
            }

            $this->secretPathValues = $this->secretRouteValues($route);
            $template = '/'.ltrim($route->uri(), '/');
            $x->name = $request->getMethod().' '.$template;
            $x->entryAttributes['http.route'] = $template;
            $x->sharedAttributes['http.route'] = $template;
        });
    }

    public function requestHandled(Request $request, Response $response, ?array $user): void
    {
        $this->guard(function () use ($request, $response, $user) {
            $x = $this->openRequest();

            if ($x === null) {
                return;
            }

            $status = $response->getStatusCode();

            if ($request !== $this->request) {
                $x->close(spl_object_id($request), microtime(true), $status >= 500);

                return;
            }

            $x->respondedAt = microtime(true);
            $x->entryAttributes['http.status_code'] = $status;
            $x->failed = $x->failed || $status >= 500;
            $x->entryAttributes['url.path'] = $this->redactPath('/'.ltrim($request->path(), '/'));

            if ($user !== null) {
                $x->sharedAttributes['user.id'] = $user['id'];
                $x->context['user'] = Values::context($user);
            }
        });
    }

    public function terminating(): void
    {
        $this->guard(function () {
            $x = $this->openRequest();

            if ($x === null) {
                return;
            }

            $x->end(microtime(true), false);
            $this->settle();
        });
    }

    public function begin(Kind $kind, string $name, ?string $traceparent, int $key, array $attributes = []): void
    {
        $this->guard(fn () => $this->start($kind, $name, TraceContext::parse($traceparent), microtime(true), $key, $attributes));
    }

    public function end(int $key, bool $failed, array $attributes = []): void
    {
        $this->guard(function () use ($key, $failed, $attributes) {
            $x = $this->execution;

            if ($x === null) {
                return;
            }

            if ($x->key !== $key) {
                $x->close($key, microtime(true), $failed);

                return;
            }

            $x->entryAttributes = [...$x->entryAttributes, ...$attributes];
            $x->end(microtime(true), $failed);

            if (! $x->failed) {
                $this->settle();
            }
        });
    }

    public function settle(): void
    {
        $this->guard(function () {
            $x = $this->execution;

            if ($x === null || ! $x->ended()) {
                return;
            }

            $this->execution = null;
            $this->reserve = null;
            $this->send($x);
        });
    }

    public function timedOut(int $key, Throwable $error): void
    {
        $this->guard(function () use ($key, $error) {
            $x = $this->execution;

            if ($x === null || $x->key !== $key) {
                return;
            }

            $this->capture($error, false, 'timeout');
            $x->end(microtime(true), true);
            $this->settle();
        });
    }

    public function stopping(): void
    {
        $this->guard(function () {
            $x = $this->execution;

            if ($x !== null && ! $x->ended()) {
                $x->entryAttributes['observe.worker_stopped'] = true;
                $x->end(microtime(true), true);
            }

            $this->settle();
            $this->flushAmbient();
        });
    }

    public function exception(Throwable $e): void
    {
        $this->guard(function () use ($e) {
            if ($e instanceof FatalError && str_contains($e->getMessage(), self::OUT_OF_MEMORY)) {
                $this->survive();
            }

            $handled = self::reportedExplicitly(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64));
            $this->capture($e, $handled, $handled ? 'report' : 'handler');
        });
    }

    public function log(string $level, mixed $message, array $context): void
    {
        $this->guard(function () use ($level, $message, $context) {
            if (! $this->settings->logs($level) || (! $this->settings->captureDeprecations && strtolower($level) === 'warning' && self::loggingDeprecation())) {
                return;
            }

            $level = strtolower($level);
            $target = $this->buffer();
            $errorId = null;
            $exception = $context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                $errorId = $target->errorIdFor($exception)
                    ?? (Settings::LEVELS[$level] >= Settings::LEVELS['error'] ? $this->capture($exception, true, 'log') : null);
                $context['exception'] = Values::context($exception);
                $message = $message === $exception->getMessage() ? Stacktrace::message($exception) : $message;
            }

            if (! $target->roomForLog()) {
                return;
            }

            $attributes = [];

            foreach ($context as $key => $value) {
                if (is_string($key) && is_scalar($value) && Values::isAttributeKey($key)) {
                    $attributes[$key] = is_string($value) ? Values::truncate($value, Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE) : $value;
                    unset($context[$key]);
                }
            }

            [$context, $encoded] = Values::fit((array) Values::context($context), Values::MAX_CONTEXT_BYTES);

            $message = Values::truncate(is_string($message) ? $message : Values::json(Values::context($message)), Values::MAX_MESSAGE_BYTES * Values::CAPTURE);
            $x = $this->execution;
            $now = microtime(true);
            $target->addLog([
                'time_us' => Ids::micros($now),
                'level' => $level,
                'message' => $message,
                'trace_id' => $x?->traceId,
                'span_id' => $x?->currentSpanId(),
                'error_id' => $errorId,
                'attributes' => $attributes,
                'context' => $context,
            ], 160 + strlen($message) + $encoded + array_sum(array_map(fn (mixed $v) => 24 + strlen((string) $v), $attributes)));

            $this->flushAmbientWhenFull();
        });
    }

    public function query(QueryExecuted $event): void
    {
        $this->guard(function () use ($event) {
            $x = $this->execution;

            if ($x === null) {
                return;
            }

            $now = microtime(true);
            $attributes = [
                'db.system' => $event->connection->getDriverName(),
                'db.connection' => $event->connectionName,
            ];

            if ($this->settings->captureBindings) {
                $attributes['db.bindings'] = Values::truncate(Values::json(Values::context($event->bindings)), Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE);
            }

            $x->span('query', self::withoutLiterals($event->sql), $now - $event->time / 1000, $now, 'ok', $attributes);
        });
    }

    public function httpMiddleware(): Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $span = $this->startHttp($request);

            if ($span !== null) {
                $request = $request->withHeader('traceparent', $span['traceparent']);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($span) {
                    $this->finishHttp($span, $response->getStatusCode(), null);

                    return $response;
                },
                function (mixed $reason) use ($span) {
                    $this->finishHttp($span, null, $reason);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    public function queuePayload(): array
    {
        try {
            $x = $this->execution;

            return $x === null || $x->ended() ? [] : ['observe' => ['traceparent' => $x->trace()->traceparent()]];
        } catch (Throwable) {
            return [];
        }
    }

    public function flushAmbient(): void
    {
        $this->guard(function () {
            if ($this->ambient->isEmpty()) {
                return;
            }

            $buffer = $this->ambient;
            $this->ambient = new Buffer;
            $shared = $this->resolvedAttributes();

            $this->deliver(null, [], self::withShared($buffer->errors, $shared), self::withShared($buffer->logs, $shared), $buffer->dropped, []);
        });
    }

    public function shutdown(): void
    {
        $this->guard(function () {
            $error = error_get_last();
            $fatal = $error !== null && in_array($error['type'], self::FATAL, true);

            if ($fatal && str_contains($error['message'], self::OUT_OF_MEMORY)) {
                $this->survive();
            }

            if ($fatal && ! $this->fatalCaptured) {
                $this->captureFatal($error);
            }

            $x = $this->execution;

            if ($x !== null && ! $x->ended()) {
                if ($fatal && $x->kind === Kind::Request) {
                    $x->entryAttributes['http.status_code'] ??= 500;
                }

                $x->end(microtime(true), $fatal);
            }

            $this->settle();
            $this->flushAmbient();
        });
    }

    private function start(Kind $kind, string $name, ?TraceContext $parent, float $startedAt, int $key, array $attributes): void
    {
        if ($this->execution !== null) {
            $this->execution->open($kind->value, $name, $key, $attributes, microtime(true));

            return;
        }

        $this->reserve ??= str_repeat("\0", self::RESERVE_BYTES);
        $this->execution = Execution::begin($kind, $name, $parent, $startedAt, $key);
        $this->execution->entryAttributes = $attributes;
    }

    private function capture(Throwable $e, bool $handled, string $mechanism): ?string
    {
        $target = $this->buffer();
        $existing = $target->errorIdFor($e);

        if ($existing !== null) {
            return $existing;
        }

        $x = $this->execution;
        $now = microtime(true);
        $id = Ids::uuid7($now);
        $context = method_exists($e, 'context') ? Values::context($e->context()) : null;

        $added = $target->addError($e, [
            'id' => $id,
            'time_us' => Ids::micros($now),
            'handled' => $handled,
            'mechanism' => $mechanism,
            'trace_id' => $x?->traceId,
            'span_id' => $x?->currentSpanId(),
            'exceptions' => $this->stacktrace->chain($e),
            'attributes' => [],
            'context' => is_array($context) && $context !== [] ? ['exception' => $context] : [],
        ]);

        if ($x !== null && ! $handled) {
            $x->failed = true;
        }

        if ($e instanceof FatalError) {
            $this->fatalCaptured = true;
        }

        return $added ? $id : null;
    }

    private function captureFatal(array $error): void
    {
        $x = $this->execution;
        $now = microtime(true);

        $this->buffer()->addError(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']), [
            'id' => Ids::uuid7($now),
            'time_us' => Ids::micros($now),
            'handled' => false,
            'mechanism' => 'fatal',
            'trace_id' => $x?->traceId,
            'span_id' => $x?->currentSpanId(),
            'exceptions' => [[
                'type' => FatalError::class,
                'message' => $error['message'],
                'code' => '0',
                'frames' => [$this->stacktrace->frame($error['file'], $error['line'], null)],
            ]],
            'attributes' => [],
            'context' => [],
        ]);

        if ($x !== null) {
            $x->failed = true;
        }

        $this->fatalCaptured = true;
    }

    private function send(Execution $x): void
    {
        $end = $x->endedAt ?? microtime(true);
        $x->closeAll(max($end, microtime(true)));
        $durationUs = max(0, Ids::micros($x->respondedAt ?? $end) - Ids::micros($x->startedAt));
        $keep = $this->sampler->keep($x->kind, $x->traceId, $x->upstreamSampled, $x->errored(), $durationUs);
        $shared = [...$x->sharedAttributes, ...$this->resolvedAttributes()];

        if (isset($x->entryAttributes['url.path']) && is_string($x->entryAttributes['url.path'])) {
            $x->entryAttributes['url.path'] = $this->redactPath($x->entryAttributes['url.path']);
        }

        if ($x->kind === Kind::Request && $x->buffer->errors !== [] && $this->request !== null && ! isset($x->context['request'])) {
            $x->context['request'] = $this->requestContext($this->request);
        }

        $entry = [
            'trace_id' => $x->traceId,
            'span_id' => $x->spanId,
            'parent_span_id' => $x->parentSpanId,
            'kind' => $x->kind->value,
            'name' => $x->name,
            'start_us' => Ids::micros($x->startedAt),
            'duration_us' => $durationUs,
            'status' => $x->failed ? 'error' : 'ok',
            'entry' => ['sampled' => $keep],
            'attributes' => [...$shared, ...$x->entryAttributes],
        ];

        $errors = $x->buffer->errors;
        $logs = $x->buffer->logs;

        if (! $keep) {
            $errors = array_map(fn (array $error) => [...$error, 'span_id' => $x->spanId], $errors);
            $logs = array_map(fn (array $log) => [...$log, 'span_id' => $x->spanId], $logs);
        }

        $this->deliver($entry, $keep ? $x->buffer->spans : [], self::withShared($errors, $shared), self::withShared($logs, $shared), $x->buffer->dropped, $errors === [] ? [] : $x->context);
    }

    private function deliver(?array $entry, array $children, array $errors, array $logs, array $dropped, array $errorContext): void
    {
        $this->busy = true;

        try {
            $outage = $this->transport instanceof OutageAware ? $this->transport->outageAttributes() : [];
            $prepared = $this->envelope->prepare([...($entry === null ? [] : [$entry]), ...$children], $errors, $logs, $errorContext);
            $head = $entry === null ? null : array_shift($prepared['spans']);
            $children = $prepared['spans'];
            $errors = $prepared['errors'];
            $logs = $prepared['logs'];
            $body = '';
            $stages = [
                fn () => null,
                function () use (&$children, &$dropped) {
                    $dropped['spans'] += count($children);
                    $children = [];
                },
                ...array_fill(0, 6, function () use (&$logs, &$dropped) {
                    $plain = array_keys(array_filter($logs, fn (array $log) => ! isset($log['error_id'])));
                    $cut = array_slice($plain, intdiv(count($plain), 2));
                    $dropped['logs'] += count($cut);
                    $logs = array_values(array_diff_key($logs, array_flip($cut)));
                }),
                function () use (&$logs, &$errors, &$dropped) {
                    $dropped['logs'] += count($logs);
                    $dropped['errors'] += max(0, count($errors) - 1);
                    $logs = [];
                    $errors = array_slice($errors, 0, 1);
                },
            ];

            foreach ($stages as $stage) {
                $stage();
                $counts = array_filter($dropped);
                $droppedAttributes = array_combine(array_map(fn (string $type) => 'observe.dropped.'.$type, array_keys($counts)), array_map(strval(...), $counts));
                $headAttributes = [...(array) ($head['attributes'] ?? []), ...$droppedAttributes];
                $spans = $head === null ? [] : [[...$head, 'attributes' => $headAttributes === [] ? (object) [] : $headAttributes], ...$children];
                $body = $this->envelope->encode(['spans' => $spans, 'errors' => $errors, 'logs' => $logs], [...($head === null ? $droppedAttributes : []), ...$outage]);

                if (self::fits($body)) {
                    break;
                }
            }

            $this->transport->send($body);
        } finally {
            $this->busy = false;
        }
    }

    private static function fits(string $body): bool
    {
        if (strlen($body) <= self::MAX_PLAIN_BYTES) {
            return true;
        }

        if (strlen($body) > self::MAX_ENVELOPE_BYTES) {
            return false;
        }

        $compressed = gzencode($body, 1);

        return $compressed !== false && strlen($compressed) <= self::MAX_GZIP_BYTES;
    }

    private function survive(): void
    {
        if ($this->reserve !== null) {
            $this->reserve = null;
        }

        if (! self::$raised) {
            self::$raised = true;
            $limit = (string) ini_get('memory_limit');
            $bytes = $limit === '' ? -1 : @ini_parse_quantity($limit);

            if ($bytes > 0 && function_exists('ini_set')) {
                @ini_set('memory_limit', (string) (max($bytes, memory_get_usage(true)) + self::HEADROOM_BYTES));
            }
        }

        $this->execution?->buffer->shed();
        $this->ambient->shed();
    }

    private function startHttp(RequestInterface $request): ?array
    {
        try {
            $x = $this->execution;

            if ($x === null || $x->ended() || $this->busy) {
                return null;
            }

            $spanId = Ids::spanId();
            $uri = $request->getUri()->withUserInfo('');

            return [
                'execution' => $x,
                'span_id' => $spanId,
                'parent_span_id' => $x->currentSpanId(),
                'start' => microtime(true),
                'name' => $request->getMethod().' '.$uri->getHost(),
                'attributes' => ['http.method' => $request->getMethod(), 'url.full' => Values::truncate((string) $uri, Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE), 'server.address' => $uri->getHost()],
                'traceparent' => $x->trace($spanId)->traceparent(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function finishHttp(?array $span, ?int $status, mixed $reason): void
    {
        if ($span === null) {
            return;
        }

        try {
            $attributes = $span['attributes'];

            if ($status !== null) {
                $attributes['http.status_code'] = $status;
            }

            if ($reason instanceof Throwable) {
                $attributes['error.type'] = $reason::class;
                $attributes['error.message'] = $reason->getMessage();
            }

            $span['execution']->span('http', $span['name'], $span['start'], microtime(true), $status === null || $status >= 400 ? 'error' : 'ok', $attributes, $span['span_id'], $span['parent_span_id']);
        } catch (Throwable) {
        }
    }

    private function requestContext(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (! str_starts_with((string) $name, 'php-auth-')) {
                $headers[$name] = count($values) === 1 ? $values[0] : $values;
            }
        }

        if (isset($headers['referer']) && is_string($headers['referer'])) {
            $headers['referer'] = $this->scrubber->referer($headers['referer']);
        }

        return array_filter([
            'method' => $request->getMethod(),
            'url' => $this->redactPath(self::attempt(fn () => $request->fullUrl(), $request->getRequestUri())),
            'headers' => Values::context($headers),
            'query' => Values::context($request->query->all()),
            'body' => $this->settings->captureBody ? self::attempt(fn () => $this->body($request), null) : null,
            'ip' => self::attempt(fn () => $request->ip(), null),
        ], fn (mixed $v) => $v !== null && $v !== []);
    }

    private function secretRouteValues(Route $route): array
    {
        $values = [];

        foreach ($route->originalParameters() as $name => $value) {
            if (is_scalar($value) && (string) $value !== '' && $this->scrubber->matchesKey((string) $name)) {
                $values[] = (string) $value;
                $values[] = rawurlencode((string) $value);
            }
        }

        return array_values(array_unique($values));
    }

    private function redactPath(string $url): string
    {
        foreach ($this->secretPathValues as $value) {
            $url = str_replace('/'.$value, '/'.Scrubber::REPLACEMENT, $url);
        }

        return $url;
    }

    private function flushAmbientWhenFull(): void
    {
        if ($this->execution !== null) {
            return;
        }

        $age = $this->ambient->firstAt === null ? 0 : microtime(true) - $this->ambient->firstAt;

        if ($this->ambient->size() >= self::AMBIENT_MAX_ITEMS || $age >= self::AMBIENT_MAX_SECONDS) {
            $this->flushAmbient();
        }
    }

    private static function withoutLiterals(string $sql): string
    {
        return str_contains($sql, "'") ? preg_replace("/'(?:[^'\\\\]|\\\\.|'')*+'/", '?', $sql) ?? '[scrubbed]' : $sql;
    }

    private static function loggingDeprecation(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24) as $frame) {
            if ($frame['function'] === 'handleDeprecationError') {
                return true;
            }
        }

        return false;
    }

    private static function attempt(Closure $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function body(Request $request): mixed
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $data = $request->isJson() ? $request->json()->all() : $request->request->all();

        if ($request->files->count() > 0) {
            $data['_files'] = array_keys($request->files->all());
        }

        if ($data === []) {
            $raw = $request->getContent();

            return $raw === '' ? null : Values::truncate($raw, self::MAX_BODY_BYTES);
        }

        return Values::bound((array) Values::context($data), self::MAX_BODY_BYTES);
    }

    private function openRequest(): ?Execution
    {
        $x = $this->execution;

        return $x !== null && $x->kind === Kind::Request && ! $x->ended() ? $x : null;
    }

    private function buffer(): Buffer
    {
        return $this->execution->buffer ?? $this->ambient;
    }

    private function resolvedAttributes(): array
    {
        if ($this->attributeResolver === null) {
            return [];
        }

        try {
            $attributes = ($this->attributeResolver)();

            return is_array($attributes) ? array_filter((array) Values::context($attributes), fn (mixed $v) => $v !== null) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function guard(Closure $callback): void
    {
        if ($this->busy) {
            return;
        }

        try {
            $callback();
        } catch (Throwable) {
        }
    }

    private static function withShared(array $items, array $shared): array
    {
        return array_map(fn (array $item) => [...$item, 'attributes' => [...$shared, ...$item['attributes']]], $items);
    }

    private static function reportedExplicitly(array $trace): bool
    {
        foreach ($trace as $frame) {
            if (! isset($frame['class']) && in_array($frame['function'], ['report', 'rescue', 'report_if', 'report_unless'], true)) {
                return true;
            }
        }

        return false;
    }
}
