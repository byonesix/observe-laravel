<?php

namespace Observe\Laravel\Transport;

use CurlHandle;
use Observe\Laravel\Resource;
use Observe\Laravel\Settings;

final readonly class Ingest
{
    public function __construct(
        private string $endpoint,
        private string $key,
    ) {}

    public static function fromSettings(Settings $settings): self
    {
        return new self($settings->endpoint, (string) $settings->key);
    }

    public function handle(string $body, int $connectTimeoutMs, int $timeoutMs): ?CurlHandle
    {
        $compressed = gzencode($body, 6);
        $handle = curl_init($this->endpoint);

        if ($compressed === false || $handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $compressed,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->key,
                'Content-Type: application/json',
                'Content-Encoding: gzip',
                'User-Agent: '.Resource::SDK_NAME.'/'.Resource::SDK_VERSION,
                'Expect:',
            ],
        ]);

        return $handle;
    }

    public static function detail(CurlHandle $handle, int $errno): string
    {
        return $errno !== 0
            ? 'curl error '.$errno.': '.curl_strerror($errno)
            : 'HTTP '.(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    }

    public static function result(CurlHandle $handle, int $errno): Result
    {
        return Result::fromHttp($errno, (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
    }
}
