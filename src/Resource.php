<?php

namespace Observe\Laravel;

final class Resource
{
    public const SDK_NAME = 'observe-laravel';

    public const SDK_VERSION = '0.5.0';

    private ?array $resolved = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $basePath,
        private readonly string $frameworkVersion,
    ) {}

    public function toArray(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $release = self::release($this->settings->release, $this->basePath);
        $attributes = [
            'framework.name' => 'laravel',
            'framework.version' => $this->frameworkVersion,
            'process.kind' => match (PHP_SAPI) {
                'fpm-fcgi' => 'fpm',
                'cli' => 'cli',
                default => PHP_SAPI,
            },
        ];

        if ($release !== null && preg_match('/^[0-9a-f]{7,40}$/i', $release) === 1) {
            $attributes['vcs.revision'] = strtolower($release);
        }

        return $this->resolved = array_filter([
            'service' => $this->settings->service,
            'platform' => 'php',
            'release' => $release,
            'host' => gethostname() ?: null,
            'sdk' => ['name' => self::SDK_NAME, 'version' => self::SDK_VERSION],
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
            'attributes' => $attributes,
        ], fn (mixed $v) => $v !== null);
    }

    public static function release(?string $configured, string $basePath): ?string
    {
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        $file = rtrim($basePath, '/').'/REVISION';
        $revision = is_file($file) ? trim((string) @file_get_contents($file, length: 256)) : '';

        if ($revision !== '') {
            return $revision;
        }

        $forge = getenv('FORGE_DEPLOY_COMMIT');

        return is_string($forge) && trim($forge) !== '' ? trim($forge) : null;
    }
}
