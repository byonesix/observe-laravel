<?php

namespace Observe\Laravel\Transport;

interface OutageAware
{
    public function outageAttributes(): array;
}
