<?php

namespace Observe\Laravel;

final class Baggage
{
    public const SESSION_KEY = 'observe.session';

    private const MAX_BYTES = 8192;

    private const UUIDV7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public static function session(mixed $headers): ?string
    {
        $header = is_array($headers) ? implode(',', array_filter($headers, is_string(...))) : $headers;

        if (! is_string($header) || $header === '' || strlen($header) > self::MAX_BYTES) {
            return null;
        }

        foreach (explode(',', $header) as $member) {
            $pair = explode('=', explode(';', $member, 2)[0], 2);

            if (count($pair) === 2 && trim($pair[0]) === self::SESSION_KEY) {
                $id = strtolower(rawurldecode(trim($pair[1])));

                return preg_match(self::UUIDV7, $id) === 1 ? $id : null;
            }
        }

        return null;
    }
}
