<?php

declare(strict_types=1);

namespace SMT;

/**
 * Roteador simples por querystring:  api/index.php?rota=demons
 * (decisão do projeto: sem .htaccess, funciona em qualquer servidor)
 */
final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $name, callable $handler): void
    {
        $this->routes[$name][strtoupper($method)] = $handler;
    }

    /** @return array<int, array{method:string, rota:string}> */
    public function map(): array
    {
        $out = [];
        foreach ($this->routes as $name => $methods) {
            foreach (array_keys($methods) as $method) {
                $out[] = ['method' => $method, 'rota' => $name];
            }
        }
        usort($out, fn ($a, $b) => [$a['rota'], $a['method']] <=> [$b['rota'], $b['method']]);

        return $out;
    }

    public function dispatch(Request $request): void
    {
        $name = $request->route();

        if ($name === '') {
            Response::json(['api' => 'SMT1', 'versao' => 'v1'], ['rotas' => $this->map()]);

            return;
        }

        if (!isset($this->routes[$name])) {
            throw ApiException::notFound("Rota \"$name\" não existe.");
        }

        $handler = $this->routes[$name][$request->method] ?? null;

        if ($handler === null) {
            $permitidos = array_keys($this->routes[$name]);
            throw new ApiException(
                'metodo_nao_permitido',
                sprintf('A rota "%s" não aceita %s.', $name, $request->method),
                405,
                ['metodos' => $permitidos]
            );
        }

        $handler($request);
    }
}
