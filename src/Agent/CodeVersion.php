<?php

namespace Observe\Laravel\Agent;

final class CodeVersion
{
    private const FILES = ['.env', 'vendor/composer/installed.json', 'bootstrap/cache/config.php'];

    public static function of(string $basePath): string
    {
        clearstatcache(true);
        $parts = [];

        if (basename(dirname($basePath)) === 'releases') {
            $parts[] = (string) @realpath(dirname($basePath, 2).'/current');
        }

        foreach (self::FILES as $file) {
            $stat = @stat($basePath.'/'.$file);
            $parts[] = $stat === false ? '-' : $stat['mtime'].':'.$stat['size'];
        }

        return implode('|', $parts);
    }
}
