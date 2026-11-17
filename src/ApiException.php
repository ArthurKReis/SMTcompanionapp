<?php

declare(strict_types=1);

namespace SMT;

use RuntimeException;

/**
 * Exceção que carrega o código de erro, o status HTTP e os detalhes
 * usados no envelope JSON de erro.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public static function notFound(string $message = 'Recurso não encontrado.'): self
    {
        return new self('nao_encontrado', $message, 404);
    }

    public static function invalid(array $details, string $message = 'Dados inválidos.'): self
    {
        return new self('validacao', $message, 422, $details);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self('requisicao_invalida', $message, 400, $details);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self('conflito', $message, 409, $details);
    }
}
