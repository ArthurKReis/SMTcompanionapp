<?php

declare(strict_types=1);

/**
 * Ponto de entrada único da API.
 *
 *   GET  api/index.php                          -> mapa de rotas
 *   GET  api/index.php?rota=demons&q=cervo      -> listagem de demônios
 *   GET  api/index.php?rota=demon&slug=cerberus -> ficha
 *   GET  api/index.php?parties                  -> ...
 *
 * Decisão do projeto: roteamento por querystring (funciona em php -S, Apache,
 * IIS, hospedagem compartilhada) e sem autenticação (uso local).
 */

require __DIR__ . '/bootstrap.php';

use SMT\{Database, Request, Response, Router};
use SMT\Repositories\{DemonRepository, FusionRepository, PartyRepository};

$db        = new Database($config['db']);
$demons    = new DemonRepository($db);
$parties   = new PartyRepository($db);
$fusions   = new FusionRepository($db);

/** Slug obrigatório nas rotas de demônio. */
function slugObrigatorio(Request $r): string
{
    $slug = $r->str('slug');

    if ($slug === null || $slug === '') {
        throw SMT\ApiException::invalid(['slug' => 'campo obrigatório']);
    }

    return $slug;
}

$router = new Router();

// --- catálogo -------------------------------------------------------------

$router->add('GET', 'health', static function (Request $r) use ($demons): void {
    Response::json([
        'status'   => 'ok',
        'api'      => 'v1',
        'catalogo' => $demons->stats(),
    ], ['importacao' => $demons->lastImport()]);
});

$router->add('GET', 'demons', static function (Request $r) use ($demons): void {
    [$rows, $meta] = $demons->list($r);
    Response::json($rows, $meta);
});

$router->add('GET', 'demons_lista', static function (Request $r) use ($demons): void {
    Response::json($demons->options());
});

$router->add('GET', 'demon', static function (Request $r) use ($demons): void {
    $slug = $r->str('slug');
    if ($slug === null || $slug === '') {
        throw SMT\ApiException::invalid(['slug' => 'campo obrigatório']);
    }
    Response::json($demons->findBySlug($slug));
});

$router->add('GET', 'races', static function () use ($demons): void {
    Response::json($demons->races());
});

$router->add('GET', 'species', static function () use ($demons): void {
    Response::json($demons->species());
});

$router->add('GET', 'elements', static function () use ($demons): void {
    Response::json($demons->elements());
});

$router->add('GET', 'skills', static function (Request $r) use ($demons): void {
    Response::json($demons->skills($r));
});

// --- parties --------------------------------------------------------------

$router->add('GET', 'parties', static function () use ($parties): void {
    Response::json($parties->list());
});

$router->add('POST', 'parties', static function (Request $r) use ($parties): void {
    Response::created($parties->create($r->body()));
});

$router->add('GET', 'party', static function (Request $r) use ($parties): void {
    Response::json($parties->find($r->int('id') ?? 0));
});

$router->add('PATCH', 'party', static function (Request $r) use ($parties): void {
    Response::json($parties->update($r->int('id') ?? 0, $r->body()));
});

$router->add('DELETE', 'party', static function (Request $r) use ($parties): void {
    $parties->delete($r->int('id') ?? 0);
    Response::noContent();
});

$router->add('PUT', 'party_demons', static function (Request $r) use ($parties): void {
    $body = $r->body();
    $linha = $body['demons'] ?? $body;
    Response::json($parties->replaceDemons($r->int('id') ?? 0, $linha));
});

$router->add('DELETE', 'party_demon', static function (Request $r) use ($parties): void {
    $id   = $r->int('id') ?? 0;
    $slot = $r->int('slot') ?? 0;
    Response::json($parties->deleteDemon($id, $slot));
});

// --- fusões ---------------------------------------------------------------

$router->add('GET', 'demon_fusions', static function (Request $r) use ($fusions): void {
    // modo retroativo: o que gera este demônio
    [$rows, $meta] = $fusions->reverse(slugObrigatorio($r));
    Response::json($rows, $meta);
});

$router->add('GET', 'demon_fusions_out', static function (Request $r) use ($fusions): void {
    // modo normal: o que sai deste demônio
    [$rows, $meta] = $fusions->forward(slugObrigatorio($r));
    Response::json($rows, $meta);
});

$router->add('GET', 'fusion_chart', static function () use ($fusions): void {
    // tabela raça × raça (239 células) — a visão clássica do jogo
    $chart = $fusions->chart();
    Response::json($chart, [
        'total' => count($chart['cells']),
        'races' => count($chart['races']),
    ]);
});

$router->add('GET', 'special_fusions', static function (Request $r) use ($fusions): void {
    $kind = $r->str('kind');
    $slug = $r->str('slug');
    $rows = $fusions->specials($kind, $slug);
    $meta = ['total' => count($rows)];

    if ($kind !== null && $kind !== '') {
        $meta['kind'] = $kind;
    }

    Response::json($rows, $meta);
});

$router->dispatch(Request::fromGlobals());
