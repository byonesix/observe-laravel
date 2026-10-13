<?php

namespace Observe\Laravel\Agent;

use Observe\Laravel\Ids;
use RuntimeException;
use Throwable;

final class Spool
{
    public const STATUS_TTL_SECONDS = 5.0;

    public const ORPHAN_SECONDS = 60;

    private mixed $lock = null;

    public function __construct(private readonly string $path) {}

    public function accepting(float $now): bool
    {
        return (float) @file_get_contents($this->path.'/status', length: 32) > $now;
    }

    public function put(string $body, float $now): bool
    {
        try {
            $file = SpoolFile::fresh(Ids::micros($now), strlen($body));
            $temporary = $this->path.'/.'.$file->name.'.tmp';

            if (@file_put_contents($temporary, $body) === strlen($body) && @rename($temporary, $this->path.'/'.$file->name)) {
                return true;
            }

            @unlink($temporary);
        } catch (Throwable) {
        }

        return false;
    }

    public function lock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        if (! is_dir($this->path)) {
            @mkdir($this->path, 0775, true);
        }

        $handle = @fopen($this->path.'/agent.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$this->path}/agent.lock. Check that the directory exists and this user can write to it.");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lock = $handle;

        return true;
    }

    public function unlock(): void
    {
        if ($this->lock === null) {
            return;
        }

        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }

    public function publish(float $acceptingUntil): void
    {
        $temporary = $this->path.'/.status.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, sprintf('%d', $acceptingUntil)) !== false) {
            @rename($temporary, $this->path.'/status') || @unlink($temporary);
        }
    }

    public function list(): array
    {
        $files = [];

        foreach (@scandir($this->path) ?: [] as $name) {
            $file = SpoolFile::parse($name);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function read(SpoolFile $file): ?string
    {
        $body = @file_get_contents($this->path.'/'.$file->name);

        return $body === false ? null : $body;
    }

    public function delete(SpoolFile $file): void
    {
        @unlink($this->path.'/'.$file->name);
    }

    public function sweep(float $now): void
    {
        foreach (@scandir($this->path) ?: [] as $name) {
            if ($name[0] !== '.' || ! str_ends_with($name, '.tmp')) {
                continue;
            }

            $modified = @filemtime($this->path.'/'.$name);

            if ($modified !== false && $modified < $now - self::ORPHAN_SECONDS) {
                @unlink($this->path.'/'.$name);
            }
        }
    }
}
