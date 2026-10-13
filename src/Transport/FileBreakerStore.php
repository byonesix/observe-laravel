<?php

namespace Observe\Laravel\Transport;

final class FileBreakerStore implements BreakerStore
{
    private mixed $lock = null;

    public function __construct(private readonly string $path) {}

    public function get(): ?BreakerState
    {
        return is_file($this->path) ? BreakerState::decode(@file_get_contents($this->path, length: 64)) : null;
    }

    public function claim(float $seconds): bool
    {
        if ($this->lock !== null) {
            return false;
        }

        $handle = @fopen($this->path.'.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lock = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->lock === null) {
            return;
        }

        @flock($this->lock, LOCK_UN);
        @fclose($this->lock);
        $this->lock = null;
    }

    public function put(?BreakerState $state): void
    {
        if ($state === null) {
            @unlink($this->path);

            return;
        }

        $temporary = $this->path.'.'.getmypid().'.'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $state->encode()) !== false) {
            @rename($temporary, $this->path) || @unlink($temporary);
        }
    }
}
