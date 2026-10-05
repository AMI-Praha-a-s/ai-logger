<?php

namespace AmiPraha\AILogger\Tests\Fixtures;

use AmiPraha\AILogger\AILogger;

/**
 * The handler with the webhook call replaced by collecting the payloads.
 */
class CapturingAILogger extends AILogger
{
    public static array $payloads = [];

    protected function send(string $jsonPayload): void
    {
        static::$payloads[] = json_decode($jsonPayload, true);
    }
}
