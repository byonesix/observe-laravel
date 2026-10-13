<?php

namespace Observe\Laravel\Agent;

interface Sender
{
    public function start(SpoolFile $file, string $body): void;

    public function poll(float $timeoutSeconds): array;

    public function abandon(): void;
}
