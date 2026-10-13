<?php

namespace Observe\Laravel;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

final class Values
{
    public const MAX_ATTRIBUTES = 128;

    public const MAX_ATTRIBUTE_BYTES = 4096;

    public const MAX_CONTEXT_BYTES = 65536;

    public const MAX_MESSAGE_BYTES = 32768;

    public const MAX_NAME_BYTES = 2048;

    public const MAX_STRING_BYTES = 8192;

    public const CAPTURE = 2;

    private const MAX_DEPTH = 6;

    private const MAX_ITEMS = 200;

    public static function attributes(array $attributes, int $bytes = self::MAX_ATTRIBUTE_BYTES): array
    {
        $out = [];

        foreach ($attributes as $key => $value) {
            $key = self::attributeKey((string) $key);
            $value = self::attribute($value, $bytes);

            if ($key === '' || $value === null) {
                continue;
            }

            $out[$key] = $value;

            if (count($out) >= self::MAX_ATTRIBUTES) {
                break;
            }
        }

        return $out;
    }

    public static function attribute(mixed $value, int $bytes = self::MAX_ATTRIBUTE_BYTES): ?string
    {
        $string = match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => json_encode(self::context($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: null,
        };

        return $string === null ? null : self::truncate($string, $bytes);
    }

    public static function attributeKey(string $key): string
    {
        return substr(trim(preg_replace('/[^a-z0-9_.]+/', '_', strtolower($key)) ?? '', '_'), 0, 128);
    }

    public static function isAttributeKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_.]{1,128}$/', $key) === 1;
    }

    public static function context(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return self::truncate($value, self::MAX_STRING_BYTES * self::CAPTURE);
        }

        if ($value === null || is_scalar($value)) {
            return is_float($value) && ! is_finite($value) ? (string) $value : $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return is_object($value) ? '['.$value::class.']' : '[array]';
        }

        if ($value instanceof Throwable) {
            return ['type' => $value::class, 'message' => self::truncate(Stacktrace::message($value), self::MAX_STRING_BYTES * self::CAPTURE), 'code' => (string) $value->getCode()];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof JsonSerializable) {
            return self::context($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Arrayable) {
            return self::context($value->toArray(), $depth + 1);
        }

        if ($value instanceof Stringable) {
            return self::truncate((string) $value, self::MAX_STRING_BYTES * self::CAPTURE);
        }

        if (is_array($value)) {
            $out = [];

            foreach (array_slice($value, 0, self::MAX_ITEMS, true) as $k => $v) {
                $out[$k] = self::context($v, $depth + 1);
            }

            return $out;
        }

        return is_object($value) ? '['.$value::class.']' : '['.get_debug_type($value).']';
    }

    public static function truncate(string $value, int $bytes): string
    {
        return strlen($value) <= $bytes ? $value : mb_strcut($value, 0, $bytes, 'UTF-8');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    public static function truncateStrings(array $data, int $bytes): array
    {
        array_walk_recursive($data, function (mixed &$value) use ($bytes) {
            if (is_string($value) && strlen($value) > $bytes) {
                $value = self::truncate($value, $bytes);
            }
        });

        return $data;
    }

    public static function boundedContext(array $context): array
    {
        return self::bound($context, self::MAX_CONTEXT_BYTES);
    }

    public static function bound(array $data, int $bytes): array
    {
        return self::fit($data, $bytes)[0];
    }

    public static function fit(array $data, int $limit): array
    {
        $size = strlen(self::json($data));

        if ($size <= $limit) {
            return [$data, $size];
        }

        $list = array_is_list($data);
        $costs = [];

        foreach ($data as $key => $value) {
            $costs[$key] = strlen(self::json($value)) + 1 + ($list ? 0 : strlen(self::json((string) $key)) + 1);
        }

        asort($costs);
        $budget = $limit - 48;
        $used = 2;
        $kept = [];
        $dropped = 0;

        foreach ($costs as $key => $cost) {
            if ($used + $cost <= $budget) {
                $kept[$key] = $data[$key];
                $used += $cost;

                continue;
            }

            $overhead = 1 + ($list ? 0 : strlen(self::json((string) $key)) + 1);
            $room = $budget - $used - $overhead;
            $value = $data[$key];

            if ($room >= 64 && is_array($value)) {
                [$kept[$key], $fitted] = self::fit($value, $room);
                $used += $overhead + $fitted;
            } elseif ($room >= 64 && is_string($value)) {
                $kept[$key] = self::truncateEncoded($value, $room);
                $used += $overhead + strlen(self::json($kept[$key]));
            } else {
                $dropped++;
            }
        }

        $ordered = [];

        foreach ($data as $key => $_) {
            if (array_key_exists($key, $kept)) {
                $ordered[$key] = $kept[$key];
            }
        }

        if ($list) {
            $ordered = array_values($ordered);
        }

        if ($dropped > 0) {
            $list ? $ordered[] = '['.$dropped.' truncated]' : $ordered['observe.truncated'] = $dropped;
        }

        $size = strlen(self::json($ordered));

        return $size <= $limit ? [$ordered, $size] : [['observe.truncated' => count($data)], 26 + strlen((string) count($data))];
    }

    private static function truncateEncoded(string $value, int $room): string
    {
        $value = self::truncate($value, max(0, $room - 2));

        while ($value !== '' && strlen(self::json($value)) > $room) {
            $value = self::truncate($value, intdiv(strlen($value) * 2, 3));
        }

        return $value;
    }
}
