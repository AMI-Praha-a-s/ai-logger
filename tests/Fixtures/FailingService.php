<?php

namespace AmiPraha\AILogger\Tests\Fixtures;

use RuntimeException;

/**
 * Application code that fails, to read the code snippets of its exception from.
 */
class FailingService
{
    public function charge(int $amount): void
    {
        if ($amount > 0) {
            throw new RuntimeException('Gateway timed out');
        }
    }
}
