<?php

namespace Observe\Laravel\Agent;

use CurlHandle;
use CurlMultiHandle;
use Observe\Laravel\Transport\Ingest;
use Observe\Laravel\Transport\Result;

final class CurlMultiSender implements Sender
{
    public const CONNECT_TIMEOUT_MS = 2000;

    public const TIMEOUT_MS = 10_000;

    private CurlMultiHandle $multi;

    private array $transfers = [];

    private array $done = [];

    public function __construct(private readonly Ingest $ingest)
    {
        $this->multi = curl_multi_init();
    }

    public function start(SpoolFile $file, string $body): void
    {
        $handle = $this->ingest->handle($body, self::CONNECT_TIMEOUT_MS, self::TIMEOUT_MS);

        if ($handle === null || curl_multi_add_handle($this->multi, $handle) !== CURLM_OK) {
            $this->done[] = new Completion($file, Result::Failed, 'could not build the request');

            return;
        }

        $this->transfers[spl_object_id($handle)] = [$handle, $file];
    }

    public function poll(float $timeoutSeconds): array
    {
        if ($this->transfers === []) {
            if ($this->done === []) {
                usleep((int) ($timeoutSeconds * 1_000_000));
            }

            return $this->collect();
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            $done = $this->collect();
            $left = $deadline - microtime(true);

            if ($done !== [] || $left <= 0) {
                return $done;
            }

            curl_multi_select($this->multi, $left);
        }
    }

    public function abandon(): void
    {
        foreach ($this->transfers as [$handle]) {
            curl_multi_remove_handle($this->multi, $handle);
        }

        $this->transfers = [];
        $this->done = [];
    }

    private function collect(): array
    {
        $done = $this->done;
        $this->done = [];

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'];

            if ($info['msg'] !== CURLMSG_DONE || ! isset($this->transfers[spl_object_id($handle)])) {
                continue;
            }

            $file = $this->transfers[spl_object_id($handle)][1];
            unset($this->transfers[spl_object_id($handle)]);
            curl_multi_remove_handle($this->multi, $handle);
            $done[] = new Completion($file, Ingest::result($handle, $info['result']), Ingest::detail($handle, $info['result']), (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME));
        }

        return $done;
    }
}
