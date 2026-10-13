<?php

namespace Observe\Laravel\Agent;

use Observe\Laravel\Transport\Result;

final readonly class Completion
{
    public function __construct(
        public SpoolFile $file,
        public Result $result,
    ) {}
}
