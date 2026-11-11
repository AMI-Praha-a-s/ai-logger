<?php

namespace AmiPraha\AILogger\Tests;

use AmiPraha\AILogger\Console\TestCommand;
use AmiPraha\AILogger\Tests\Fixtures\CapturingAILogger;
use Illuminate\Support\Facades\File;

class TestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stack', ['driver' => 'stack', 'channels' => ['single', 'ai-logger']]);
        $this->useAILoggerChannel();
    }

    public function test_it_sends_a_test_error_and_reports_that_it_was_received(): void
    {
        $this->artisan('ai-logger:test')
            ->expectsOutputToContain('AI Logger received the test error')
            ->doesntExpectOutputToContain('does not send to')
            ->assertExitCode(0);

        $payload = $this->lastSentPayload();

        $this->assertSame('ERROR', $payload['level']);
        $this->assertSame(TestCommand::MESSAGE, $payload['message']);
        $this->assertSame(['ai_logger_test' => true], $payload['context']);
    }

    public function test_it_warns_when_the_default_channel_does_not_send_to_ai_logger(): void
    {
        config()->set('logging.channels.stack.channels', ['single']);

        $this->artisan('ai-logger:test')
            ->expectsOutputToContain('does not send to [ai-logger]')
            ->expectsOutputToContain('AI Logger received the test error')
            ->assertExitCode(0);
    }

    public function test_the_channel_can_be_reached_through_nested_stacks(): void
    {
        config()->set('logging.channels.stack.channels', 'single,errors');
        config()->set('logging.channels.errors', ['driver' => 'stack', 'channels' => ['stack', 'ai-logger']]);

        $this->artisan('ai-logger:test')
            ->doesntExpectOutputToContain('does not send to')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_the_channel_is_not_defined(): void
    {
        $this->artisan('ai-logger:test', ['--channel' => 'missing'])
            ->expectsOutputToContain('is not defined in config/logging.php')
            ->assertExitCode(1);

        $this->assertEmpty(CapturingAILogger::$payloads);
    }

    public function test_it_fails_when_the_channel_does_not_use_the_ai_logger_driver(): void
    {
        $this->artisan('ai-logger:test', ['--channel' => 'single'])
            ->expectsOutputToContain('uses the [single] driver')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_channel_level_filters_out_errors(): void
    {
        $this->useAILoggerChannel(['level' => 'critical']);

        $this->artisan('ai-logger:test')
            ->expectsOutputToContain('only sends logs of level [critical]')
            ->assertExitCode(1);

        $this->assertEmpty(CapturingAILogger::$payloads);
    }

    public function test_it_reports_why_ai_logger_rejected_the_test_error(): void
    {
        CapturingAILogger::$rejection = [
            'status_code' => 422,
            'response' => [
                'status' => 'fail',
                'message' => 'Validation error.',
                'errors' => ['source.git_project_owner' => ['The source.git project owner field is required.']],
            ],
        ];

        $this->artisan('ai-logger:test')
            ->expectsOutputToContain('AI Logger did not receive the test error: HTTP error (HTTP 422)')
            ->expectsOutputToContain('Validation error.')
            ->expectsOutputToContain('The source.git project owner field is required.')
            ->assertExitCode(1);
    }

    public function test_it_reports_an_unreachable_webhook(): void
    {
        $storage = sys_get_temp_dir() . '/ai-logger-test-command-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($storage . '/logs');
        $this->app->useStoragePath($storage);

        try {
            config()->set('logging.channels.ai-logger', [
                'driver' => 'ai-logger',
                'webhookUrl' => 'http://127.0.0.1:1/api/receive',
                'sourceCode' => 'TESTAPP',
                'sourceName' => 'Test App',
                'sourceUrl' => 'http://app.test',
            ]);

            $this->artisan('ai-logger:test')
                ->expectsOutputToContain('AI Logger did not receive the test error: cURL error')
                ->assertExitCode(1);

            $this->assertStringContainsString('cURL error', File::get($storage . '/logs/ai_logger_critical.log'));
        } finally {
            File::deleteDirectory($storage);
        }
    }

    protected function useAILoggerChannel(array $config = []): void
    {
        config()->set('logging.channels.ai-logger', [
            'driver' => 'ai-logger-capture',
            'webhookUrl' => 'http://webhook.test',
            'sourceCode' => 'TESTAPP',
            'sourceName' => 'Test App',
            ...$config,
        ]);
    }
}
