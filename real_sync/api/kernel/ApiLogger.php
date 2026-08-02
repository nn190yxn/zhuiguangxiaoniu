<?php
declare(strict_types=1);

require_once __DIR__ . '/RequestContext.php';
require_once __DIR__ . '/SensitiveData.php';

final class PlatformApiLogger
{
    private Closure $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer === null
            ? static fn(string $line): bool => error_log($line)
            : Closure::fromCallable($writer);
    }

    public function log(
        string $level,
        string $event,
        PlatformRequestContext $context,
        array $data = []
    ): void {
        $level = strtolower($level);
        if (!in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true)) {
            $level = 'info';
        }
        $event = preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $event) === 1
            ? $event
            : 'api.event';
        $request = $context->toArray();
        $row = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'request_id' => $context->requestId(),
            'domain' => $request['domain'],
            'action' => $request['action'],
            'request' => $request,
            'data' => PlatformSensitiveData::sanitize($data),
        ];
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ($this->writer)($json === false ? '{"level":"error","event":"api.log_encode_failed"}' : $json);
    }
}
