<?php

namespace Observe\Laravel;

final readonly class Envelope
{
    public function __construct(
        private Resource $resource,
        private Scrubber $scrubber,
    ) {}

    public function prepare(array $spans, array $errors, array $logs, array $errorContext = []): array
    {
        $shared = $errorContext === [] ? [] : $this->scrubber->keyed($errorContext);

        return [
            'spans' => array_map($this->span(...), $spans),
            'errors' => array_map(fn (array $error) => $this->error($error, $shared), $errors),
            'logs' => array_map($this->log(...), $logs),
        ];
    }

    public function encode(array $prepared, array $resourceAttributes = []): string
    {
        $resource = $this->resource->toArray();
        $resource['attributes'] = Values::attributes([...$resource['attributes'] ?? [], ...$resourceAttributes]);

        return Values::json([
            'batch_id' => Ids::uuid7(),
            'sent_at_us' => Ids::micros(microtime(true)),
            'resource' => $resource,
            'spans' => $prepared['spans'],
            'errors' => $prepared['errors'],
            'logs' => $prepared['logs'],
        ]);
    }

    public static function restamp(string $body, int $sentAtUs): string
    {
        return preg_replace('/\A\{"batch_id":"[0-9a-f-]{36}","sent_at_us":\K\d+/', (string) $sentAtUs, $body, 1) ?? $body;
    }

    private function span(array $span): array
    {
        $span['name'] = Values::truncate($this->scrubber->text($span['name']), Values::MAX_NAME_BYTES);
        $span['attributes'] = $this->attributes($span['attributes']);

        return self::withoutNulls($span);
    }

    private function error(array $error, array $sharedContext): array
    {
        $error['exceptions'] = array_map(function (array $exception) {
            $exception['message'] = Values::truncate($this->scrubber->text($exception['message']), Values::MAX_MESSAGE_BYTES);
            $exception['frames'] = array_map(function (array $frame) {
                if (isset($frame['snippet']['lines'])) {
                    $frame['snippet']['lines'] = array_map($this->scrubber->text(...), $frame['snippet']['lines']);
                }

                return $frame;
            }, $exception['frames']);

            return $exception;
        }, $error['exceptions']);
        $error['attributes'] = $this->attributes($error['attributes']);
        $error['context'] = self::bounded([...$sharedContext, ...$this->scrubber->keyed($error['context'])]);

        return self::withoutNulls($error);
    }

    private function log(array $log): array
    {
        $log['message'] = Values::truncate($this->scrubber->text($log['message']), Values::MAX_MESSAGE_BYTES);
        $log['attributes'] = $this->attributes($log['attributes']);
        $log['context'] = self::bounded($this->scrubber->keyed($log['context']));

        return self::withoutNulls($log);
    }

    private function attributes(array $attributes): array|object
    {
        $normalized = Values::attributes($this->scrubber->keyed($attributes));

        return $normalized === [] ? (object) [] : $normalized;
    }

    private static function bounded(array $context): array|object
    {
        $bounded = Values::boundedContext(Values::truncateStrings($context, Values::MAX_STRING_BYTES));

        return $bounded === [] || array_is_list($bounded) ? (object) $bounded : $bounded;
    }

    private static function withoutNulls(array $item): array
    {
        return array_filter($item, fn (mixed $v) => $v !== null);
    }
}
