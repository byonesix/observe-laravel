<?php

namespace Observe\Laravel;

use Illuminate\Support\Str;
use Observe\Laravel\Agent\AgentSettings;
use Throwable;

final readonly class Settings
{
    public const AGENT_COMMAND = 'observe:agent';

    public const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    public function __construct(
        public bool $enabled,
        public ?string $key,
        public string $endpoint,
        public string $service,
        public ?string $release,
        public string $minLevel,
        public float $sampleRate,
        public array $slowMs,
        public array $scrubKeys,
        public array $clientAgents,
        public bool $captureBindings,
        public bool $captureBody,
        public bool $captureDeprecations,
        public array $ignoreCommands,
        public int $connectTimeoutMs,
        public int $timeoutMs,
        public ?AgentSettings $agent = null,
    ) {}

    public static function fromConfig(array $config, string $agentPath = ''): self
    {
        try {
            return self::parse($config, $agentPath);
        } catch (Throwable) {
            return self::parse(['enabled' => false]);
        }
    }

    private static function parse(array $config, string $agentPath = ''): self
    {
        $level = strtolower(self::string($config['min_level'] ?? null, 'info'));
        $connect = self::milliseconds($config['transport']['connect_timeout_ms'] ?? null, 500, 5000);

        return new self(
            enabled: filter_var($config['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            key: self::string($config['key'] ?? null, '') ?: null,
            endpoint: self::string($config['endpoint'] ?? null, ''),
            service: self::string($config['service'] ?? null, 'laravel'),
            release: self::string($config['release'] ?? null, '') ?: null,
            minLevel: array_key_exists($level, self::LEVELS) ? $level : 'info',
            sampleRate: is_numeric($config['sample_rate'] ?? null) ? max(0.0, min(1.0, (float) $config['sample_rate'])) : 0.1,
            slowMs: array_map(fn (mixed $ms) => is_numeric($ms) ? (int) $ms : PHP_INT_MAX, (array) ($config['slow_ms'] ?? [])),
            scrubKeys: self::list($config['scrub']['keys'] ?? []),
            clientAgents: self::list($config['client_agents'] ?? []),
            captureBindings: filter_var($config['capture']['query_bindings'] ?? false, FILTER_VALIDATE_BOOL),
            captureBody: filter_var($config['capture']['request_body'] ?? true, FILTER_VALIDATE_BOOL),
            captureDeprecations: filter_var($config['capture']['deprecations'] ?? false, FILTER_VALIDATE_BOOL),
            ignoreCommands: array_values(array_filter((array) ($config['ignore_commands'] ?? []), is_string(...))),
            connectTimeoutMs: $connect,
            timeoutMs: max($connect, self::milliseconds($config['transport']['timeout_ms'] ?? null, 1500, 10000)),
            agent: self::agent(is_array($config['agent'] ?? null) ? $config['agent'] : [], $agentPath),
        );
    }

    private static function agent(array $config, string $defaultPath): ?AgentSettings
    {
        $path = self::string($config['path'] ?? null, $defaultPath);

        if (! filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL) || $path === '') {
            return null;
        }

        return new AgentSettings(
            path: rtrim($path, '/'),
            maxBytes: self::positive($config['max_mb'] ?? null, AgentSettings::DEFAULT_MAX_MB) * 1024 * 1024,
            maxFiles: self::positive($config['max_files'] ?? null, AgentSettings::DEFAULT_MAX_FILES),
        );
    }

    private static function string(mixed $value, string $default): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private static function list(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;

        return array_values(array_filter(array_map(fn (mixed $item) => is_string($item) ? trim($item) : '', $items), fn (string $item) => $item !== ''));
    }

    private static function milliseconds(mixed $value, int $default, int $max): int
    {
        return is_numeric($value) && (int) $value > 0 ? min($max, (int) $value) : $default;
    }

    private static function positive(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    public function sends(): bool
    {
        return $this->enabled && $this->key !== null && $this->endpoint !== '';
    }

    public function logs(string $level): bool
    {
        return (self::LEVELS[strtolower($level)] ?? 0) >= self::LEVELS[$this->minLevel];
    }

    public function ignoresCommand(string $command): bool
    {
        return $command === '' || $command === self::AGENT_COMMAND || Str::is($this->ignoreCommands, $command);
    }
}
