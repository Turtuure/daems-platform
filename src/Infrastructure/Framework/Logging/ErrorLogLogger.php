<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Logging;

use Throwable;

final class ErrorLogLogger implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        $encoded = [];
        foreach ($context as $k => $v) {
            if ($v instanceof Throwable) {
                $encoded[$k] = [
                    'class'   => $v::class,
                    'message' => $v->getMessage(),
                    'file'    => $v->getFile(),
                    'line'    => $v->getLine(),
                ];
            } else {
                $encoded[$k] = $v;
            }
        }
        $payload = json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[daems] ' . $message . ' ' . ($payload === false ? '{}' : $payload));
    }
}
