<?php

namespace Observe\Laravel;

final class ClientAgent
{
    public static function attributes(?string $userAgent, array $names): array
    {
        if ($userAgent === null || $names === []) {
            return [];
        }

        $alternatives = implode('|', array_map(fn (string $name) => preg_quote($name, '/'), $names));

        if (preg_match('/\b('.$alternatives.')(?:\/(\d[0-9A-Za-z.+_-]*))?/', $userAgent, $m) !== 1) {
            return [];
        }

        return isset($m[2]) ? ['client.name' => $m[1], 'client.version' => $m[2]] : ['client.name' => $m[1]];
    }
}
