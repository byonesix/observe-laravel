<?php

namespace Observe\Laravel;

use Closure;
use GuzzleHttp\Promise\Create;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Queue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Observe\Laravel\Agent\Spool;
use Observe\Laravel\Console\AgentCommand;
use Observe\Laravel\Transport\AgentTransport;
use Observe\Laravel\Transport\ApcuBreakerStore;
use Observe\Laravel\Transport\Breaker;
use Observe\Laravel\Transport\CurlTransport;
use Observe\Laravel\Transport\FileBreakerStore;
use Observe\Laravel\Transport\NullTransport;
use Observe\Laravel\Transport\Transport;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

final class ObserveServiceProvider extends ServiceProvider
{
    public const TRACEPARENT_ENV = 'OBSERVE_TRACEPARENT';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observe.php', 'observe');

        $this->app->singleton(Settings::class, fn (Application $app) => Settings::fromConfig((array) $app['config']->get('observe', []), $app->storagePath('observe/agent')));

        $this->app->singleton(Transport::class, function (Application $app) {
            $settings = $app->make(Settings::class);

            if (! $settings->sends()) {
                return new NullTransport;
            }

            $store = ApcuBreakerStore::available()
                ? new ApcuBreakerStore('observe-breaker:'.md5($app->basePath()))
                : new FileBreakerStore($app->storagePath('framework/observe-breaker'));

            $direct = new Breaker(
                new CurlTransport($settings->endpoint, (string) $settings->key, $settings->connectTimeoutMs, $settings->timeoutMs),
                $store,
                probeSeconds: max(Breaker::MIN_PROBE_SECONDS, $settings->timeoutMs / 1000 + 0.5),
            );

            return $settings->agent === null ? $direct : new AgentTransport(new Spool($settings->agent->path), $direct);
        });

        $this->app->singleton(Scrubber::class, fn (Application $app) => Scrubber::withDefaults($app->make(Settings::class)->scrubKeys));

        $this->app->singleton(Stacktrace::class, fn (Application $app) => new Stacktrace($app->basePath()));

        $this->app->singleton(Envelope::class, fn (Application $app) => new Envelope(new Resource($app->make(Settings::class), $app->basePath(), $app->version()), $app->make(Scrubber::class)));

        $this->app->singleton(Recorder::class, function (Application $app) {
            $settings = $app->make(Settings::class);

            return new Recorder(
                $settings,
                $app->make(Transport::class),
                new Sampler($settings->sampleRate, $settings->slowMs),
                $app->make(Envelope::class),
                $app->make(Stacktrace::class),
                $app->make(Scrubber::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/observe.php' => $this->app->configPath('observe.php')], 'observe-config');

        if ($this->app->runningInConsole()) {
            $this->commands([AgentCommand::class]);
        }

        try {
            if ($this->app->make(Settings::class)->sends() && ! $this->runningAgent()) {
                $this->hook($this->app->make(Recorder::class), $this->app->make(Settings::class));
            }
        } catch (Throwable) {
        }
    }

    private function hook(Recorder $recorder, Settings $settings): void
    {
        $app = $this->app;
        $events = $app->make('events');
        $listen = fn (string|array $event, Closure $listener) => $events->listen($event, self::safely($listener));

        if (! $app->runningInConsole() || $app->runningUnitTests()) {
            $kernel = $app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware') && method_exists($kernel, 'whenRequestLifecycleIsLongerThan')) {
                $kernel->prependMiddleware(RecordRequest::class);
                $kernel->whenRequestLifecycleIsLongerThan(-1, fn () => $recorder->terminating());
            }

            $listen(RouteMatched::class, fn (RouteMatched $e) => $recorder->routeMatched($e->route, $e->request));
            $listen(RequestHandled::class, fn (RequestHandled $e) => $recorder->requestHandled($e->request, $e->response, self::user($app)));
        }

        $handler = $app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            $handler->reportable(function (Throwable $e) use ($recorder) {
                $recorder->exception($e);
            });
        }

        $listen(MessageLogged::class, fn (MessageLogged $e) => $recorder->log((string) $e->level, $e->message, $e->context));
        $listen(QueryExecuted::class, fn (QueryExecuted $e) => $recorder->query($e));

        if (class_exists(Create::class) && $app->bound(HttpFactory::class)) {
            $app->make(HttpFactory::class)->globalMiddleware($recorder->httpMiddleware());
        }

        Queue::createPayloadUsing(fn () => $recorder->queuePayload());

        $listen(JobProcessing::class, fn (JobProcessing $e) => $recorder->begin(Kind::Job, $e->job->resolveName(), self::payloadTraceparent($e->job), spl_object_id($e->job), [
            'job.connection' => $e->connectionName,
            'job.queue' => $e->job->getQueue(),
            'job.attempts' => $e->job->attempts(),
            'job.id' => $e->job->getJobId(),
        ]));
        $listen(JobProcessed::class, fn (JobProcessed $e) => $recorder->end(spl_object_id($e->job), $e->job->hasFailed()));
        $listen([JobFailed::class, JobExceptionOccurred::class, JobReleasedAfterException::class], fn (JobFailed|JobExceptionOccurred|JobReleasedAfterException $e) => $recorder->end(spl_object_id($e->job), true));
        $listen(JobTimedOut::class, fn (JobTimedOut $e) => $recorder->timedOut(spl_object_id($e->job), TimeoutExceededException::forJob($e->job)));
        $listen(Looping::class, function () use ($recorder) {
            $recorder->settle();
            $recorder->flushAmbient();
        });
        $listen(WorkerStopping::class, fn () => $recorder->stopping());

        $scrubber = $app->make(Scrubber::class);

        $listen(CommandStarting::class, function (CommandStarting $e) use ($recorder, $settings, $scrubber) {
            if ($settings->ignoresCommand($e->command)) {
                $recorder->settle();

                return;
            }

            $traceparent = getenv(self::TRACEPARENT_ENV);
            $recorder->begin(Kind::Command, $e->command, is_string($traceparent) ? $traceparent : null, spl_object_id($e->input), [
                'process.command_line' => self::commandLine($e->input, $e->command, $scrubber),
            ]);
        });
        $listen(CommandFinished::class, function (CommandFinished $e) use ($recorder, $settings) {
            if ($settings->ignoresCommand($e->command)) {
                $recorder->settle();

                return;
            }

            $recorder->end(spl_object_id($e->input), $e->exitCode !== 0, ['process.exit_code' => $e->exitCode]);
        });

        $listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $e) use ($recorder) {
            $recorder->settle();
            $recorder->begin(Kind::ScheduledTask, self::taskName($e->task), null, spl_object_id($e->task), [
                'schedule.expression' => $e->task->expression,
            ]);

            self::exportTraceparent($recorder->context()?->traceparent());
        });
        $listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $e) use ($recorder) {
            self::exportTraceparent(null);
            $recorder->end(spl_object_id($e->task), ! in_array($e->task->exitCode, [null, 0], true));
        });
        $listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $e) use ($recorder) {
            self::exportTraceparent(null);
            $recorder->end(spl_object_id($e->task), true);
        });

        register_shutdown_function(fn () => $recorder->shutdown());
    }

    private function runningAgent(): bool
    {
        return $this->app->runningInConsole() && (new ArgvInput)->getFirstArgument() === Settings::AGENT_COMMAND;
    }

    private static function safely(Closure $listener): Closure
    {
        return function (object $event) use ($listener): void {
            try {
                $listener($event);
            } catch (Throwable) {
            }
        };
    }

    private static function commandLine(InputInterface $input, string $command, Scrubber $scrubber): string
    {
        try {
            $named = array_filter($input->getArguments(), fn (mixed $value, int|string $name) => is_string($name) && $name !== 'command', ARRAY_FILTER_USE_BOTH);
            $arguments = $scrubber->keyed((array) Values::context($named));
            $options = $scrubber->keyed((array) Values::context($input->getOptions()));
        } catch (Throwable) {
            return $command;
        }

        $render = fn (mixed $value) => is_array($value) ? implode(',', array_map(fn (mixed $v) => is_scalar($v) ? (string) $v : Values::json($v), $value)) : (is_scalar($value) ? (string) $value : Values::json($value));
        $parts = [$command];

        foreach ($arguments as $value) {
            if ($value !== null && $value !== []) {
                $parts[] = $render($value);
            }
        }

        foreach ($options as $name => $value) {
            if ($value === null || $value === false || $value === []) {
                continue;
            }

            $parts[] = $value === true ? '--'.$name : '--'.$name.'='.$render($value);
        }

        return Values::truncate(implode(' ', $parts), Values::MAX_ATTRIBUTE_BYTES * Values::CAPTURE);
    }

    private static function exportTraceparent(?string $traceparent): void
    {
        if ($traceparent === null) {
            putenv(self::TRACEPARENT_ENV);
            unset($_ENV[self::TRACEPARENT_ENV], $_SERVER[self::TRACEPARENT_ENV]);

            return;
        }

        putenv(self::TRACEPARENT_ENV.'='.$traceparent);
        $_ENV[self::TRACEPARENT_ENV] = $_SERVER[self::TRACEPARENT_ENV] = $traceparent;
    }

    private static function payloadTraceparent(Job $job): ?string
    {
        $traceparent = $job->payload()['observe']['traceparent'] ?? null;

        return is_string($traceparent) ? $traceparent : null;
    }

    private static function taskName(ScheduledEvent $task): string
    {
        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        if (is_string($task->command) && $task->command !== '') {
            return trim(preg_replace("/^.*?'?artisan'?\s+/", '', $task->command) ?? $task->command);
        }

        return 'closure';
    }

    private static function user(Application $app): ?array
    {
        try {
            if (! $app->resolved('auth')) {
                return null;
            }

            $guard = $app->make('auth')->guard();

            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return null;
            }

            $user = $guard->user();

            return $user === null ? null : array_filter([
                'id' => $user->getAuthIdentifier(),
                'name' => self::text($user, 'name'),
                'email' => self::text($user, 'email'),
            ], fn (mixed $v) => $v !== null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(object $user, string $attribute): ?string
    {
        if (! $user instanceof Model || ! $user->hasAttribute($attribute)) {
            return null;
        }

        $value = $user->getAttribute($attribute);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
