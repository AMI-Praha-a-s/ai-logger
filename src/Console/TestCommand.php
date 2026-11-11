<?php

namespace AmiPraha\AILogger\Console;

use AmiPraha\AILogger\AILogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Level;
use Throwable;

/**
 * Sends a test error through the ai-logger log channel and tells whether AI Logger received it.
 */
class TestCommand extends Command
{
    /**
     * The same message and context every time, as AI Logger groups logs by them: repeated tests make one issue.
     */
    public const MESSAGE = 'Test error sent by "php artisan ai-logger:test" to check that this app can send logs to AI Logger. It can be ignored.';
    public const CONTEXT = ['ai_logger_test' => true];

    protected $signature = 'ai-logger:test
        {--channel=ai-logger : The log channel that uses the ai-logger driver}';

    protected $description = 'Send a test error to AI Logger and check that it was received';

    public function handle(): int
    {
        $channel = (string) $this->option('channel');
        $config = $this->channelConfig($channel);

        if ($config === null) {
            $this->components->error("The log channel [$channel] is not defined in config/logging.php. See \"Configuring Logging Channel\" in the README of ami-praha/ai-logger.");

            if ($this->laravel->configurationIsCached()) {
                $this->components->warn('The configuration is cached, run "php artisan config:clear" if you have just added the channel.');
            }

            return self::FAILURE;
        }

        $handler = $this->findHandler($channel);

        if ($handler === null) {
            $driver = $config['driver'] ?? null;

            $this->components->error($driver === 'ai-logger'
                ? "The log channel [$channel] could not be created, see storage/logs/laravel.log."
                : 'The log channel [' . $channel . '] uses the [' . ($driver ?? 'none') . '] driver, not [ai-logger].');

            return self::FAILURE;
        }

        if ($handler->getLevel()->value > Level::Error->value) {
            $this->components->error('The log channel [' . $channel . '] only sends logs of level [' . strtolower($handler->getLevel()->getName()) . '] and above, so errors are not sent to AI Logger. Lower its "level" in config/logging.php.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Channel', $channel);
        $this->components->twoColumnDetail('Webhook URL', $this->configValue($config, 'webhookUrl'));
        $this->components->twoColumnDetail('Source', $this->configValue($config, 'sourceCode') . ' (' . $this->configValue($config, 'sourceName') . ')');

        $default = config('logging.default');

        if (!$this->defaultChannelReaches($channel)) {
            $this->components->warn('The default log channel [' . ($default ?? 'none') . '] does not send to [' . $channel . '], so errors the app logs do not reach AI Logger. Add [' . $channel . '] to the channels of the stack (LOG_STACK in .env on Laravel 11+).');
        }

        try {
            Log::channel($channel)->error(static::MESSAGE, static::CONTEXT);
        } catch (Throwable $e) {
            $this->components->error('Logging the test error failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return $this->report($handler->getLastDelivery(), $config);
    }

    /**
     * @param array{sent: bool, status_code: int|null, error: string|null, error_context: array}|null $delivery
     * @param array $config
     * @return int
     */
    protected function report(?array $delivery, array $config): int
    {
        if ($delivery === null) {
            $this->components->error('The test error did not reach the AI Logger handler.');

            return self::FAILURE;
        }

        $status = $delivery['status_code'] ? ' (HTTP ' . $delivery['status_code'] . ')' : '';

        if ($delivery['sent']) {
            $source = filled($config['sourceCode'] ?? null) ? ' under the source [' . $config['sourceCode'] . ']' : '';

            $this->components->info("AI Logger received the test error$status. Find it$source, repeated tests are grouped into one issue.");

            return self::SUCCESS;
        }

        $this->components->error('AI Logger did not receive the test error: ' . rtrim((string) $delivery['error'], '.') . $status);

        $context = $delivery['error_context'];
        $message = $context['message'] ?? null;
        $details = [];

        if (is_string($message) && $message !== 'Unknown error') {
            $details['Message'] = $message;
        } elseif (is_string($context['raw_response'] ?? null) && trim($context['raw_response']) !== '') {
            $details['Response'] = Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($context['raw_response']))), 200);
        }

        foreach (is_array($context['errors'] ?? null) ? $context['errors'] : [] as $field => $errors) {
            $details[(string) $field] = implode(' ', array_map('strval', array_filter((array) $errors, 'is_scalar')));
        }

        foreach ($details as $label => $detail) {
            $this->components->twoColumnDetail($label, $detail);
        }

        // The boxes end with an empty line, the details do not
        if ($delivery['status_code'] >= 300 && $delivery['status_code'] < 400) {
            $this->components->warn('Redirects are not followed, check the webhook URL (https instead of http?).');
        } elseif (array_key_exists('response', $context)) {
            $this->components->warn('The webhook URL answered, but not the way AI Logger does. Check that it is the /api/receive endpoint of AI Logger.');
        } elseif (!empty($details)) {
            $this->newLine();
        }

        $this->line('  Failed deliveries are also written to storage/logs/ai_logger_critical.log.');
        $this->newLine();

        return self::FAILURE;
    }

    /**
     * The AI Logger handler of the channel, null when the channel does not use the ai-logger driver.
     *
     * @param string $channel
     * @return AILogger|null
     */
    protected function findHandler(string $channel): ?AILogger
    {
        $logger = Log::channel($channel);
        $monolog = method_exists($logger, 'getLogger') ? $logger->getLogger() : $logger;

        if (!method_exists($monolog, 'getHandlers')) {
            return null;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof AILogger) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Whether logs of the default channel get to the channel, directly or through stacks.
     *
     * @param string $channel
     * @return bool
     */
    protected function defaultChannelReaches(string $channel): bool
    {
        $pending = [config('logging.default')];
        $seen = [];

        while (!empty($pending)) {
            $name = array_shift($pending);

            if (!is_string($name) || isset($seen[$name])) {
                continue;
            }

            if ($name === $channel) {
                return true;
            }

            $seen[$name] = true;
            $config = $this->channelConfig($name);

            if (($config['driver'] ?? null) === 'stack') {
                $channels = $config['channels'] ?? [];
                array_push($pending, ...array_values(is_string($channels) ? explode(',', $channels) : (array) $channels));
            }
        }

        return false;
    }

    protected function channelConfig(string $channel): ?array
    {
        $config = config('logging.channels')[$channel] ?? null;

        return is_array($config) ? $config : null;
    }

    protected function configValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : 'not set';
    }
}
