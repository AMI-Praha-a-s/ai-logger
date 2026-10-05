<?php

namespace AmiPraha\AILogger\Tests;

use AmiPraha\AILogger\Tests\Fixtures\CapturingAILogger;
use AmiPraha\AILogger\Tests\Fixtures\FailingService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use LogicException;
use Monolog\Logger;
use RuntimeException;
use Throwable;

class ExceptionDataTest extends TestCase
{
    public function test_a_log_carries_its_time_and_the_data_of_laravel_context(): void
    {
        Context::add('trace_id', 'e04e1a11');

        Log::warning('Payment failed');

        $debugData = $this->lastSentPayload()['debug_data'];

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}[+-]\d\d:\d\d$/', $debugData['logged_at']);
        $this->assertSame(['trace_id' => 'e04e1a11'], $debugData['extra']);
        $this->assertSame('extra', array_key_last($debugData));
    }

    public function test_a_log_without_extra_data_sends_none(): void
    {
        Log::warning('Payment failed');

        $this->assertArrayNotHasKey('extra', $this->lastSentPayload()['debug_data']);
    }

    public function test_an_exception_carries_the_exceptions_that_caused_it(): void
    {
        $gatewayError = $this->failInApplicationCode();
        $exception = new RuntimeException('Checkout failed', 0, new LogicException('Payment failed', 3, $gatewayError));

        Log::error('Checkout failed', ['exception' => $exception]);

        $sent = $this->lastSentPayload()['context']['exception'];

        $this->assertSame(RuntimeException::class, $sent['type']);
        $this->assertSame(['type', 'message', 'code', 'file', 'line', 'trace', 'snippets', 'previous'], array_keys($sent));
        $this->assertSame([
            'type' => LogicException::class,
            'message' => 'Payment failed',
            'code' => 3,
            'file' => __FILE__,
            'line' => $exception->getPrevious()->getLine(),
            'previous' => [
                'type' => RuntimeException::class,
                'message' => 'Gateway timed out',
                'code' => 0,
                'file' => $gatewayError->getFile(),
                'line' => $gatewayError->getLine(),
            ],
        ], $sent['previous']);

        // The line the root cause was thrown on comes right after the outer exception's own line
        $this->assertSame(
            [[__FILE__, $exception->getLine()], [$gatewayError->getFile(), $gatewayError->getLine()]],
            array_map(fn (array $snippet) => [$snippet['file'], $snippet['line']], array_slice($sent['snippets'], 0, 2))
        );
    }

    public function test_a_long_chain_of_causes_is_cut(): void
    {
        $exception = new RuntimeException('Cause 0');

        for ($i = 1; $i <= 8; $i++) {
            $exception = new RuntimeException('Cause ' . $i, 0, $exception);
        }

        Log::error('Failed', ['exception' => $exception]);

        $depth = 0;
        $cause = $this->lastSentPayload()['context']['exception'];

        while (isset($cause['previous'])) {
            $cause = $cause['previous'];
            $depth++;
        }

        $this->assertSame(5, $depth);
        $this->assertSame('Cause 3', $cause['message']);
    }

    public function test_an_exception_carries_the_code_around_the_lines_of_the_application(): void
    {
        $exception = $this->failInApplicationCode();

        Log::error('Payment failed', ['exception' => $exception]);

        $snippets = $this->lastSentPayload()['context']['exception']['snippets'];

        $this->assertSame((new \ReflectionClass(FailingService::class))->getFileName(), $snippets[0]['file']);
        $this->assertSame($exception->getLine(), $snippets[0]['line']);
        $this->assertSame(implode("\n", [
            '7  | /**',
            '8  |  * Application code that fails, to read the code snippets of its exception from.',
            '9  |  */',
            '10 | class FailingService',
            '11 | {',
            '12 |     public function charge(int $amount): void',
            '13 |     {',
            '14 |         if ($amount > 0) {',
            "15 >             throw new RuntimeException('Gateway timed out');",
            '16 |         }',
            '17 |     }',
            '18 | }',
        ]), $snippets[0]['code']);

        // The caller in this test is application code too, the frames of PHPUnit are not
        $this->assertSame(__FILE__, $snippets[1]['file']);
        $this->assertLessThanOrEqual(4, count($snippets));

        foreach ($snippets as $snippet) {
            $this->assertStringNotContainsString('/vendor/', $snippet['file']);
        }
    }

    public function test_code_snippets_can_be_turned_off(): void
    {
        $logger = new Logger('ai-logger', [
            new CapturingAILogger('http://webhook.test', 'TESTAPP', 'Test App', 'http://app.test', null, null, null, null, 100, true, false),
        ]);

        $logger->error('Payment failed', ['exception' => $this->failInApplicationCode()]);

        $this->assertArrayNotHasKey('snippets', $this->lastSentPayload()['context']['exception']);
    }

    public function test_anonymous_classes_are_named_without_the_nul_byte_postgresql_rejects(): void
    {
        $thrower = new class {
            public function run(): void
            {
                throw new class('Stock sync failed') extends RuntimeException {};
            }
        };

        try {
            $thrower->run();
        } catch (RuntimeException $e) {
            Log::error('Stock sync failed', ['exception' => $e]);
        }

        $sent = $this->lastSentPayload()['context']['exception'];

        $this->assertSame('RuntimeException@anonymous', $sent['type']);
        $this->assertSame('class@anonymous', $sent['trace'][0]['class']);
        $this->assertStringNotContainsString("\0", json_encode($sent));
    }

    public function test_something_else_logged_as_the_exception_is_sent_as_it_is(): void
    {
        Log::error('Something went wrong.', ['exception' => 'Some exception info']);

        $this->assertSame('Some exception info', $this->lastSentPayload()['context']['exception']);
    }

    public function test_invalid_utf8_does_not_lose_the_log(): void
    {
        Log::error("Import of \xB1 failed", ['row' => "\xC3\x28"]);

        $payload = $this->lastSentPayload();

        $this->assertSame("Import of \u{FFFD} failed", $payload['message']);
        $this->assertSame("\u{FFFD}(", $payload['context']['row']);
    }

    protected function failInApplicationCode(): Throwable
    {
        try {
            (new FailingService())->charge(100);
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('FailingService did not fail.');
    }
}
