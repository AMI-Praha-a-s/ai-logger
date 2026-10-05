<?php

namespace AmiPraha\AILogger\Tests;

use AmiPraha\AILogger\AILoggerServiceProvider;
use AmiPraha\AILogger\QueueJobContext;
use AmiPraha\AILogger\Tests\Fixtures\CapturingAILogger;
use Monolog\Logger;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;

        parent::setUp();

        CapturingAILogger::$payloads = [];
        QueueJobContext::clear();
    }

    protected function tearDown(): void
    {
        QueueJobContext::clear();

        parent::tearDown();

        $_SERVER = $this->serverBackup;
    }

    protected function getPackageProviders($app): array
    {
        return [AILoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['log']->extend('ai-logger-capture', function () {
            return new Logger('ai-logger', [
                new CapturingAILogger('http://webhook.test', 'TESTAPP', 'Test App', 'http://app.test'),
            ]);
        });

        $app['config']->set('logging.default', 'ai-logger-capture');
        $app['config']->set('logging.channels.ai-logger-capture', ['driver' => 'ai-logger-capture']);
    }

    /**
     * The last payload the handler would have sent to the webhook.
     */
    protected function lastSentPayload(): array
    {
        $this->assertNotEmpty(CapturingAILogger::$payloads, 'Nothing was sent to the webhook.');

        return end(CapturingAILogger::$payloads);
    }
}
