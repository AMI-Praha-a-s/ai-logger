<?php

namespace AmiPraha\AILogger\Tests\Fixtures;

use AmiPraha\AILogger\AILogger;

/**
 * The handler with the webhook call replaced by collecting the payloads.
 */
class CapturingAILogger extends AILogger
{
    public static array $payloads = [];

    /**
     * When set, the webhook rejects the logs with this HTTP status code and JSON response, as the ai-logger app does.
     *
     * @var array{status_code: int, response: array}|null
     */
    public static ?array $rejection = null;

    protected function send(string $jsonPayload): void
    {
        if (static::$rejection !== null) {
            $this->lastDelivery['status_code'] = static::$rejection['status_code'];
            $this->logInternalError('HTTP error', [
                'status_code' => static::$rejection['status_code'],
                'message' => static::$rejection['response']['message'] ?? 'Unknown error',
                'errors' => static::$rejection['response']['errors'] ?? null,
                'raw_response' => json_encode(static::$rejection['response']),
            ]);

            return;
        }

        static::$payloads[] = json_decode($jsonPayload, true);
    }
}
