<?php

declare(strict_types=1);

namespace SMT;

/**
 * Envelope único de resposta:
 *   sucesso -> {"data": ..., "meta": {...}}
 *   erro    -> {"error": {"code": "...", "message": "...", "details": {...}}}
 */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public static function json(mixed $data, array $meta = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        echo json_encode($payload, self::JSON_FLAGS);
    }

    public static function created(mixed $data, array $meta = []): void
    {
        self::json($data, $meta, 201);
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        echo json_encode(['error' => $error], self::JSON_FLAGS);
    }

    /** Metadados de paginação padronizados. */
    public static function pageMeta(int $total, int $page, int $perPage): array
    {
        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }
}
