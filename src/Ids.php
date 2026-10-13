<?php

namespace Observe\Laravel;

final class Ids
{
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function uuid7(?float $time = null): string
    {
        $ms = (int) floor(($time ?? microtime(true)) * 1000);
        $bytes = substr(pack('J', $ms), 2).random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    public static function micros(float $time): int
    {
        return (int) round($time * 1_000_000);
    }
}
