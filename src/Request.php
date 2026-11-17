<?php

declare(strict_types=1);

namespace SMT;

/**
 * Requisição normalizada: aceita JSON no corpo ou formulário (x-www-form-urlencoded),
 * e lê filtros da query string.
 */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        public readonly array $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';
        $body   = [];

        if (stripos($ctype, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw ApiException::badRequest('O corpo da requisição não é um JSON válido.');
                }
                $body = $decoded;
            }
        } else {
            $body = $_POST;
        }

        return new self($method, $_GET, $body);
    }

    public function route(): string
    {
        return trim((string) ($this->query['rota'] ?? ''));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function str(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            throw ApiException::invalid([$key => 'deve ser um texto simples']);
        }

        return trim((string) $value);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key, $default);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_array($value) || !is_numeric($value)) {
            throw ApiException::invalid([$key => 'deve ser um número inteiro']);
        }

        return (int) $value;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key, $default);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_array($value) || !is_numeric($value)) {
            throw ApiException::invalid([$key => 'deve ser um número']);
        }

        return (float) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes', 'sim'], true);
    }

    /** Todo o corpo (para payloads de party). */
    public function body(): array
    {
        return $this->body;
    }
}
