<?php

namespace AmiPraha\AILogger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;

class AILogger extends AbstractProcessingHandler
{
    protected ?string $webhookUrl;
    protected ?string $sourceCode;
    protected ?string $sourceName;
    protected ?string $sourceUrl;
    protected ?string $sourceGitProjectOwner;
    protected ?string $sourceGitProjectName;
    protected ?string $sourceJiraProjectCode;
    protected ?string $sourceJiraParentProjectKey;

    public function __construct(
        ?string $webhookUrl = null,
        ?string $sourceCode = null,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?string $sourceGitProjectOwner = null,
        ?string $sourceGitProjectName = null,
        ?string $sourceJiraProjectCode = null,
        ?string $sourceJiraParentProjectKey = null,
        int $level = 100, // debug
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->webhookUrl = $webhookUrl;
        $this->sourceCode = $sourceCode;
        $this->sourceName = $sourceName;
        $this->sourceUrl = $sourceUrl;
        $this->sourceGitProjectOwner = $sourceGitProjectOwner;
        $this->sourceGitProjectName = $sourceGitProjectName;
        $this->sourceJiraProjectCode = $sourceJiraProjectCode;
        $this->sourceJiraParentProjectKey = $sourceJiraParentProjectKey;
    }

    /**
     * Writes the log to the external webhook.
     *
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        try {
            if (empty($this->webhookUrl) || empty($this->sourceCode) || empty($this->sourceName) || empty($this->sourceUrl)) {
                $this->logInternalError('webhookUrl, sourceCode, sourceName, or sourceUrl is missing, please add all of them to your config. See ai-logger documentation for more info.');

                return;
            }

            $payload = [
                'level'     => $record->level->getName(),
                'message'   => $record->message,
                'context'   => $record->context,
                'debug_data' => [
                    'url' => $this->getRequestUrl(),
                    'method' => $this->getRequestMethod(),
                    'route_name' => $this->getRouteName(),
                    'route_action' => $this->getRouteAction(),
                    'is_console' => $this->isRunningInConsole(),
                    'environment' => $this->getEnvironment(),
                    'command' => $this->getConsoleCommand(),
                    'queue_job' => QueueJobContext::job(),
                ],
                'performance_data' => [
                    'memory_usage' => [
                        'value' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'unit' => 'MB'
                    ],
                    'peak_memory' => [
                        'value' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                        'unit' => 'MB'
                    ],
                    'execution_time' => [
                        'value' => $this->getExecutionTime(),
                        'unit' => 'ms'
                    ],
                ],
                'user_tracking_data' => collect([
                    'logger_user_id'    => $this->getUserId(),
                    'logger_user_name'  => $this->getLoggerUserName(),
                    'logger_user_email' => $this->getUserEmail(),
                    'ip'                => $this->getClientIp(),
                    'user_agent'        => $this->getUserAgent(),
                    'referer'           => $this->getReferer(),
                ])->filter()->all(),
                'source' => [
                    'code' => $this->sourceCode,
                    'name' => $this->sourceName,
                    'url'  => $this->sourceUrl,
                    'git_project_owner' => $this->sourceGitProjectOwner,
                    'git_project_name' => $this->sourceGitProjectName,
                    'jira_project_code' => $this->sourceJiraProjectCode,
                    'jira_parent_project_key' => $this->sourceJiraParentProjectKey,
                ],
            ];

            if (!empty($record->context['exception'])) {
                $payload['context']['exception'] = $this->formatExceptionToArray($record->context['exception']);
            }

            $jsonPayload = json_encode($payload);

            if ($jsonPayload === false) {
                $this->logInternalError('JSON encoding error - ' . json_last_error_msg());

                return;
            }

            $this->send($jsonPayload);
        } catch (Throwable $e) {
            $this->logInternalError('Fatal error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file' . $e->getFile());
        }
    }

    /**
     * Posts the JSON payload to the webhook.
     *
     * @param string $jsonPayload
     * @return void
     */
    protected function send(string $jsonPayload): void
    {
        $ch = curl_init($this->webhookUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logInternalError('cURL error - ' . curl_error($ch) . '. Probably invalid webhook URL.');
        } else {
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $decodedResponse = json_decode($response, true);

            if ($httpStatusCode < 200 || $httpStatusCode >= 300) {
                $this->logInternalError('HTTP error', [
                    'status_code' => $httpStatusCode,
                    'message' => $decodedResponse['message'] ?? 'Unknown error',
                    'errors'  => $decodedResponse['errors'] ?? null,
                    'raw_response' => $response,
                ]);
            } else {
                if (!isset($decodedResponse['status']) || $decodedResponse['status'] !== 'success') {
                    $this->logInternalError('Unexpected API response format or failed status', [
                        'response' => $decodedResponse,
                    ]);
                }
            }
        }

        curl_close($ch);
    }

    /**
     * Writes internal errors to a dedicated log file, bypassing Laravel's logging.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    protected function logInternalError(string $message, array $context = []): void
    {
        try {
            $timestamp = date('Y-m-d H:i:s');
            $line = "[$timestamp] [AILogger INTERNAL ERROR] $message";

            if (!empty($context)) {
                $contextJson = json_encode($context);
                $line .= " | context: " . ($contextJson === false ? print_r($context, true) : $contextJson);
            }

            $line .= "\n";

            file_put_contents(App::storagePath('logs/ai_logger_critical.log'), $line, FILE_APPEND);
        } catch (Throwable $e) {
            // If we can't even log the error, there's not much we can do
            error_log("AILogger critical error: " . $e->getMessage());
        }
    }

    protected function formatExceptionToArray(Throwable $e): array
    {
        return [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->map(function ($trace) {
                return Arr::only($trace, ['file', 'line', 'function', 'class', 'type']);
            })->all(),
        ];
    }

    protected function getRequestUrl(): ?string
    {
        try {
            // In console the request is a fake one made from APP_URL (GET, 127.0.0.1, "Symfony" user agent)
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::fullUrl();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRequestMethod(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::method();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRouteName(): ?string
    {
        try {
            return Request::route()?->getName();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRouteAction(): ?string
    {
        try {
            return Request::route()?->getActionName();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function isRunningInConsole(): bool
    {
        try {
            return App::runningInConsole();
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function getConsoleCommand(): ?string
    {
        try {
            $argv = $_SERVER['argv'] ?? [];

            if (!$this->isRunningInConsole() || empty($argv)) {
                return null;
            }

            return implode(' ', [basename((string) $argv[0]), ...$this->redactCommandArguments(array_slice($argv, 1))]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Replaces values of secret-looking options, given as "--password=secret" or "--password secret".
     *
     * @param array $arguments
     * @return array
     */
    protected function redactCommandArguments(array $arguments): array
    {
        $redacted = [];
        $redactNext = false;

        foreach ($arguments as $argument) {
            $argument = (string) $argument;

            if ($redactNext && !str_starts_with($argument, '-')) {
                $redacted[] = '***';
                $redactNext = false;

                continue;
            }

            $redactNext = false;

            if (preg_match('/^(--?[\w.-]*(?:pass|secret|token|key|auth|credential)[\w.-]*)(=.*)?$/i', $argument, $matches)) {
                if (isset($matches[2])) {
                    $argument = $matches[1] . '=***';
                } else {
                    $redactNext = true;
                }
            }

            $redacted[] = $argument;
        }

        return $redacted;
    }

    protected function getEnvironment(): ?string
    {
        try {
            return App::environment();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getExecutionTime(): float
    {
        try {
            // In a queue worker LARAVEL_START is the worker's start, so measure from the job's start
            $startTime = QueueJobContext::startedAt()
                ?? (defined('\LARAVEL_START') ? \LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
            return (microtime(true) - $startTime) * 1000;
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    protected function getUserId(): ?int
    {
        try {
            return Auth::id();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getUserEmail(): ?string
    {
        try {
            return Auth::user()?->email;
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getClientIp(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::ip();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getUserAgent(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::userAgent();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getReferer(): ?string
    {
        try {
            return Request::header('referer')
                ?? Request::header('referrer') 
                ?? $_SERVER['HTTP_REFERER'] 
                ?? $_SERVER['HTTP_REFERRER'] 
                ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getLoggerUserName(): ?string
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return null;
            }

            $name = $user->name ?? null;

            if (empty($name)) {
                $firstName = $user->first_name ?? $user->firstname ?? null;
                $lastName = $user->last_name ?? $user->lastname ?? null;

                $name = implode(' ', array_filter([$firstName, $lastName]));
            }

            return !empty($name) ? $name : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
