<?php

declare(strict_types=1);

use SMT\ApiException;
use SMT\Response;

/**
 * Bootstrap da API: autoload, configuração e tratamento global de erros.
 * Tudo que sai daqui é JSON — nunca HTML.
 */

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'SMT\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $rel . '.php';

    if (is_file($file)) {
        require $file;
    }
});

$configFile = __DIR__ . '/config.php';
$config     = require is_file($configFile) ? $configFile : __DIR__ . '/config.example.php';

date_default_timezone_set($config['app']['timezone'] ?? 'America/Sao_Paulo');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($config): void {
    if ($e instanceof ApiException) {
        Response::error($e->errorCode(), $e->getMessage(), $e->status(), $e->details());

        return;
    }

    error_log('[smt-api] ' . $e::class . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());

    $debug = (bool) ($config['app']['debug'] ?? false);
    Response::error(
        'erro_interno',
        $debug ? $e->getMessage() : 'Erro interno no servidor.',
        500,
        $debug ? ['tipo' => $e::class, 'arquivo' => $e->getFile(), 'linha' => $e->getLine()] : []
    );
});
