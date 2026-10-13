<?php

namespace Observe\Laravel\Agent;

final class PackageVersion
{
    public static function installed(string $packageRoot): ?string
    {
        $current = preg_replace('#^(.*?)/releases/[^/]+/(.*)$#', '$1/current/$2', $packageRoot) ?? $packageRoot;
        $root = is_dir($current) ? $current : $packageRoot;
        clearstatcache(true);

        $source = @file_get_contents($root.'/src/Resource.php');

        return is_string($source) && preg_match("/SDK_VERSION = '([^']+)'/", $source, $m) === 1 ? $m[1] : null;
    }
}
