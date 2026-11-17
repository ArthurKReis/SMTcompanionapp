<?php

declare(strict_types=1);

namespace SMT;

/**
 * Validador que acumula erros por campo (melhor para formulário: o front
 * recebe todos os problemas de uma vez em vez de um por requisição).
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function text(mixed $value, string $field, int $max, bool $required = false, int $min = 0): ?string
    {
        if ($value === null) {
            if ($required) {
                $this->add($field, 'campo obrigatório');
            }

            return null;
        }

        if (is_array($value)) {
            $this->add($field, 'deve ser um texto simples');

            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            if ($required) {
                $this->add($field, 'campo obrigatório');
            }

            return null;
        }

        // mbstring não é obrigatório: se faltar, conta caracteres UTF-8 com preg
        $len = function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : (int) preg_match_all('/./us', $text, $naoUsado);

        if ($len < $min) {
            $this->add($field, "deve ter pelo menos $min caractere(s)");
        } elseif ($len > $max) {
            $this->add($field, "deve ter no máximo $max caracteres");
        }

        return $text;
    }

    public function integer(mixed $value, string $field, int $min, int $max, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_array($value) || !is_numeric($value) || (string) (int) $value !== (string) $value) {
            // aceita "12" e 12, mas não "12.5" nem "abc"
            if (!is_numeric($value) || (float) $value != (int) $value) {
                $this->add($field, 'deve ser um número inteiro');
                return $default;
            }
        }

        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $this->add($field, "deve estar entre $min e $max");
        }

        return $int;
    }

    public function choice(mixed $value, string $field, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->add($field, 'valores aceitos: ' . implode(', ', $allowed));
            return null;
        }

        return $value;
    }

    public function flag(mixed $value, string $field, bool $default = false): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes', 'sim'], true) ? 1 : 0;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function throwIfFails(): void
    {
        if ($this->fails()) {
            throw ApiException::invalid($this->errors);
        }
    }
}
