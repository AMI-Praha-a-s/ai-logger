<?php

namespace AmiPraha\AILogger\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ReflectionProperty;

class ConsoleContextTest extends TestCase
{
    public function test_a_console_log_carries_the_command_instead_of_the_fake_request(): void
    {
        $_SERVER['argv'] = ['/var/www/app/artisan', 'reports:send', '--month=9'];

        Log::warning('Report failed');

        $payload = $this->lastSentPayload();

        $this->assertTrue($payload['debug_data']['is_console']);
        $this->assertSame('artisan reports:send --month=9', $payload['debug_data']['command']);
        $this->assertNull($payload['debug_data']['url']);
        $this->assertNull($payload['debug_data']['method']);
        $this->assertNull($payload['debug_data']['queue_job']);
        $this->assertArrayNotHasKey('ip', $payload['user_tracking_data']);
        $this->assertArrayNotHasKey('user_agent', $payload['user_tracking_data']);
    }

    public function test_secret_command_options_are_redacted(): void
    {
        $_SERVER['argv'] = ['artisan', 'users:import', '--password=hunter2', '--api-token', 'abc123', '--force', 'users.csv', '--DB_SECRET=x'];

        Log::warning('Import failed');

        $this->assertSame(
            'artisan users:import --password=*** --api-token *** --force users.csv --DB_SECRET=***',
            $this->lastSentPayload()['debug_data']['command']
        );
    }

    public function test_an_http_request_keeps_its_request_data_and_has_no_command(): void
    {
        (new ReflectionProperty(Application::class, 'isRunningInConsole'))->setValue($this->app, false);

        Route::post('/orders/{order}', function () {
            Log::warning('Order failed');

            return 'ok';
        })->name('orders.store');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeader('User-Agent', 'Firefox')
            ->post('/orders/5?step=2')
            ->assertOk();

        $payload = $this->lastSentPayload();

        $this->assertFalse($payload['debug_data']['is_console']);
        $this->assertSame('http://localhost/orders/5?step=2', $payload['debug_data']['url']);
        $this->assertSame('POST', $payload['debug_data']['method']);
        $this->assertSame('orders.store', $payload['debug_data']['route_name']);
        $this->assertNull($payload['debug_data']['command']);
        $this->assertSame('10.0.0.5', $payload['user_tracking_data']['ip']);
        $this->assertSame('Firefox', $payload['user_tracking_data']['user_agent']);
    }
}
