<?php

namespace Observe\Laravel\Transport;

interface Transport
{
    public function send(string $body): Result;
}
