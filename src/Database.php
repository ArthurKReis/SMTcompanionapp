<?php

declare(strict_types=1);

namespace SMT;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Conexão PDO única + atalhos de consulta com prepared statements.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
            $cfg['name'] ?? 'smt_db',
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Falha ao conectar no banco: ' . $e->getMessage(), 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->run($sql, $params);

        return $st->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function run(string $sql, array $params): PDOStatement
    {
        try {
            $st = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $placeholder = is_int($key) ? $key + 1 : $key;
                $type = match (true) {
                    is_int($value)  => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    $value === null => PDO::PARAM_NULL,
                    default         => PDO::PARAM_STR,
                };
                $st->bindValue($placeholder, $value, $type);
            }
            $st->execute();

            return $st;
        } catch (PDOException $e) {
            // 23000 = violação de integridade (FK, UNIQUE, CHECK)
            if ($e->getCode() === '23000') {
                throw ApiException::conflict('Operação viola uma regra de integridade do banco.', [
                    'sqlstate' => '23000',
                    'erro'     => $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }
}
