<?php

namespace Observe\Laravel\Transport;

final class NullTransport implements Transport
{
    public function send(string $body): Result
    {
        return Result::Skipped;
    }
}
