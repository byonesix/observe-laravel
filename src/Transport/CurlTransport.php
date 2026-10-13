<?php

namespace Observe\Laravel\Transport;

use Throwable;

final readonly class CurlTransport implements Transport
{
    private Ingest $ingest;

    public function __construct(
        string $endpoint,
        string $key,
        private int $connectTimeoutMs = 500,
        private int $timeoutMs = 1500,
    ) {
        $this->ingest = new Ingest($endpoint, $key);
    }

    public function send(string $body): Result
    {
        try {
            $handle = $this->ingest->handle($body, $this->connectTimeoutMs, $this->timeoutMs);

            if ($handle === null) {
                return Result::Failed;
            }

            $share = self::share();

            if ($share !== null) {
                curl_setopt($handle, CURLOPT_SHARE, $share);
            }

            curl_exec($handle);

            return Ingest::result($handle, curl_errno($handle));
        } catch (Throwable) {
            return Result::Failed;
        }
    }

    private static function share(): ?object
    {
        if (! function_exists('curl_share_init_persistent')) {
            return null;
        }

        return curl_share_init_persistent([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_SSL_SESSION, CURL_LOCK_DATA_CONNECT]);
    }
}
