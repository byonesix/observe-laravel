<?php

namespace Observe\Laravel\Transport;

final class ApcuBreakerStore implements BreakerStore
{
    private ?string $token = null;

    public function __construct(private readonly string $key) {}

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(): ?BreakerState
    {
        return BreakerState::decode(apcu_fetch($this->key));
    }

    public function claim(float $seconds): bool
    {
        $token = bin2hex(random_bytes(8));

        if ($this->token !== null || ! apcu_add($this->key.':probe', $token, max(1, (int) ceil($seconds)))) {
            return false;
        }

        $this->token = $token;

        return true;
    }

    public function release(): void
    {
        if ($this->token !== null && apcu_fetch($this->key.':probe') === $this->token) {
            apcu_delete($this->key.':probe');
        }

        $this->token = null;
    }

    public function put(?BreakerState $state): void
    {
        if ($state === null) {
            apcu_delete($this->key);

            return;
        }

        apcu_store($this->key, $state->encode(), Breaker::UNAUTHORIZED_SECONDS * 2);
    }
}
