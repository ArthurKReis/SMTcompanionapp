<?php

declare(strict_types=1);

namespace SMT\Repositories;

use SMT\ApiException;
use SMT\Database;

/**
 * Consultas de fusão, nos modos previstos:
 *
 *   reverse()  -> "quero este demônio: quais pares o produzem?"
 *   forward()  -> "tenho este demônio: o que sai combinando com outros?"
 *   chart()    -> a tabela raça x raça do jogo (239 células)
 *
 * Regra de desempenho (medida no EXPLAIN): filtrar SEMPRE por id.
 * Filtrar a view por nome de ingrediente faz o otimizador varrer `races` e
 * `demons`, ignorando idx_fusions_a/idx_fusions_b; por id ele usa index_merge.
 */
final class FusionRepository
{
    /** Ordem de exibição das regras: das mais comuns para as mais específicas. */
    private const ORDEM_REGRA = "FIELD(f.rule, 'normal', 'species', 'element', 'dark')";

    public function __construct(private readonly Database $db)
    {
    }

    /** Identificação do demônio (para o cabeçalho da tela). */
    public function demon(string $slug): array
    {
        $demon = $this->db->one(
            'SELECT d.id, d.slug, d.name, d.level, r.name AS race, sp.name AS species,
                    d.alignment
               FROM demons d
               JOIN races r   ON r.id = d.race_id
               JOIN species sp ON sp.id = r.species_id
              WHERE d.slug = ?',
            [$slug]
        );

        if ($demon === null) {
            throw ApiException::notFound("Demônio \"$slug\" não encontrado.");
        }

        $demon['id']    = (int) $demon['id'];
        $demon['level'] = (float) $demon['level'];

        return $demon;
    }

    /**
     * Modo retroativo: fusões cujo resultado é este demônio.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    public function reverse(string $slug): array
    {
        $demon = $this->demon($slug);

        $fusoes = $this->db->all(
            'SELECT f.rule,
                    ia.slug AS a_slug, ia.name AS a_name, ra.name AS a_race, ia.level AS a_level,
                    ib.slug AS b_slug, ib.name AS b_name, rb.name AS b_race, ib.level AS b_level
               FROM fusions f
               JOIN demons ia ON ia.id = f.ingredient_a_id
               JOIN races  ra ON ra.id = ia.race_id
               JOIN demons ib ON ib.id = f.ingredient_b_id
               JOIN races  rb ON rb.id = ib.race_id
              WHERE f.result_demon_id = ?
              ORDER BY ' . self::ORDEM_REGRA . ', ia.level, ia.name, ib.level, ib.name',
            [$demon['id']]
        );

        $data = array_map(static fn (array $r): array => [
            'rule'       => $r['rule'],
            'ingredient_a' => [
                'slug'  => $r['a_slug'],
                'name'  => $r['a_name'],
                'race'  => $r['a_race'],
                'level' => (float) $r['a_level'],
            ],
            'ingredient_b' => [
                'slug'  => $r['b_slug'],
                'name'  => $r['b_name'],
                'race'  => $r['b_race'],
                'level' => (float) $r['b_level'],
            ],
        ], $fusoes);

        return [$data, $this->meta($demon, $data)];
    }

    /**
     * Modo normal: fusões em que este demônio é um dos ingredientes.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    public function forward(string $slug): array
    {
        $demon = $this->demon($slug);
        $id    = $demon['id'];

        // OR entre dois índices: o MariaDB resolve com index_merge
        $fusoes = $this->db->all(
            'SELECT f.rule,
                    res.slug AS r_slug, res.name AS r_name, rr.name AS r_race, res.level AS r_level,
                    out_.slug AS o_slug, out_.name AS o_name, ro.name AS o_race, out_.level AS o_level
               FROM fusions f
               JOIN demons res  ON res.id = f.result_demon_id
               JOIN races  rr   ON rr.id = res.race_id
               JOIN demons out_ ON out_.id = IF(f.ingredient_a_id = ?, f.ingredient_b_id, f.ingredient_a_id)
               JOIN races  ro   ON ro.id = out_.race_id
              WHERE f.ingredient_a_id = ? OR f.ingredient_b_id = ?
              ORDER BY ' . self::ORDEM_REGRA . ', res.level, res.name, out_.level, out_.name',
            [$id, $id, $id]
        );

        $data = array_map(static fn (array $r): array => [
            'rule'         => $r['rule'],
            'result'       => [
                'slug'  => $r['r_slug'],
                'name'  => $r['r_name'],
                'race'  => $r['r_race'],
                'level' => (float) $r['r_level'],
            ],
            'other'        => [
                'slug'  => $r['o_slug'],
                'name'  => $r['o_name'],
                'race'  => $r['o_race'],
                'level' => (float) $r['o_level'],
            ],
        ], $fusoes);

        return [$data, $this->meta($demon, $data)];
    }


    /**
     * Chart raça × raça: as 239 células preenchidas do jogo.
     *
     * Devolve a lista de raças que participam do chart (na ordem do jogo, por
     * grupo) e as células. A célula é sempre simétrica; 15 raças têm uma célula
     * "de si mesma" que gera o elemental correspondente.
     *
     * @return array{races: array<int, array<string, mixed>>, cells: array<int, array<string, mixed>>}
     */
    public function chart(): array
    {
        $racas = $this->db->all(
            'SELECT r.id, r.name, sp.name AS species, sp.sort_order AS species_order
               FROM races r
               JOIN species sp ON sp.id = r.species_id
              WHERE r.id IN (SELECT race_a_id FROM fusion_chart UNION SELECT race_b_id FROM fusion_chart)
              ORDER BY sp.sort_order, r.name'
        );

        $celulas = $this->db->all(
            "SELECT a.name AS race_a, b.name AS race_b, fc.result_type,
                    rr.name AS result_race, sp.name AS result_species, d.name AS result_demon,
                    d.slug AS result_demon_slug
               FROM fusion_chart fc
               JOIN races a       ON a.id  = fc.race_a_id
               JOIN races b       ON b.id  = fc.race_b_id
               LEFT JOIN races rr ON rr.id = fc.result_race_id
               LEFT JOIN species sp ON sp.id = fc.result_species_id
               LEFT JOIN demons d ON d.id  = fc.result_demon_id
              ORDER BY a.name, b.name"
        );

        return [
            'races' => array_map(static fn (array $r): array => [
                'id'      => (int) $r['id'],
                'name'    => $r['name'],
                'species' => $r['species'],
            ], $racas),
            'cells' => array_map(static fn (array $c): array => [
                'race_a'  => $c['race_a'],
                'race_b'  => $c['race_b'],
                'type'    => $c['result_type'],
                'result'  => $c['result_race'] ?? $c['result_species'] ?? $c['result_demon'],
                'demon_slug' => $c['result_demon_slug'],
            ], $celulas),
        ];
    }

    /**
     * Receitas especiais (pares fixos, trios, pré-requisitos e recrutamento).
     *
     * @return array<int, array<string, mixed>>
     */
    public function specials(?string $kind = null, ?string $slug = null): array
    {
        $where = [];
        $args  = [];

        if ($kind !== null && $kind !== '') {
            $where[]       = 'sf.kind = ?';
            $args[]        = $kind;
        }

        if ($slug !== null && $slug !== '') {
            $where[]       = 'd.slug = ?';
            $args[]        = $slug;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $rows = $this->db->all(
            "SELECT d.slug AS target_slug, d.name AS target, d.level AS target_level,
                    r.name AS target_race,
                    sf.kind, sf.recipe_text,
                    i1.slug AS i1_slug, i1.name AS i1_name,
                    i2.slug AS i2_slug, i2.name AS i2_name,
                    i3.slug AS i3_slug, i3.name AS i3_name
               FROM special_fusions sf
               JOIN demons d  ON d.id = sf.target_demon_id
               JOIN races  r  ON r.id = d.race_id
               LEFT JOIN demons i1 ON i1.id = sf.ingredient_1_id
               LEFT JOIN demons i2 ON i2.id = sf.ingredient_2_id
               LEFT JOIN demons i3 ON i3.id = sf.ingredient_3_id
               {$clause}
              ORDER BY FIELD(sf.kind, 'pair', 'trio', 'prereq', 'recruit'), d.level, d.name",
            $args
        );

        return array_map(static function (array $r): array {
            $ingredientes = [];
            foreach ([1, 2, 3] as $n) {
                if ($r["i{$n}_slug"] !== null) {
                    $ingredientes[] = ['slug' => $r["i{$n}_slug"], 'name' => $r["i{$n}_name"]];
                }
            }

            return [
                'target'       => [
                    'slug'  => $r['target_slug'],
                    'name'  => $r['target'],
                    'race'  => $r['target_race'],
                    'level' => (float) $r['target_level'],
                ],
                'kind'         => $r['kind'],
                'recipe'       => $r['recipe_text'],
                'ingredients'  => $ingredientes,
            ];
        }, $rows);
    }

    /**
     * Resumo que acompanha as duas listas: contagem por regra e — quando a
     * lista está vazia — como o demônio é obtido no jogo.
     */
    private function meta(array $demon, array $fusoes): array
    {
        $porRegra = ['normal' => 0, 'species' => 0, 'element' => 0, 'dark' => 0];
        foreach ($fusoes as $f) {
            $porRegra[$f['rule']] = ($porRegra[$f['rule']] ?? 0) + 1;
        }

        $receitas = $this->db->all(
            'SELECT sf.kind, sf.recipe_text
               FROM special_fusions sf
              WHERE sf.target_demon_id = ?',
            [$demon['id']]
        );

        return [
            'demon'      => [
                'slug'  => $demon['slug'],
                'name'  => $demon['name'],
                'race'  => $demon['race'],
                'level' => $demon['level'],
            ],
            'total'      => count($fusoes),
            'por_regra'  => $porRegra,
            'receitas'   => $receitas,
            'obtencao'   => $this->obtencao($demon, $fusoes, $receitas),
        ];
    }

    /**
     * Tudo derivado dos próprios dados — não existe coluna "tipo de obtenção":
     *
     *   fusao             -> sai de alguma combinação
     *   receita_especial  -> só por receita fixa (par/trio/pré-requisito)
     *   recrutamento      -> entra na party por negociação (receita kind=recruit)
     *   inimigo           -> nenhuma das anteriores: só chefe/inimigo
     */
    private function obtencao(array $demon, array $fusoes, array $receitas): string
    {
        if ($fusoes !== []) {
            return 'fusao';
        }

        foreach ($receitas as $r) {
            if (in_array($r['kind'], ['pair', 'trio', 'prereq'], true)) {
                return 'receita_especial';
            }
        }

        foreach ($receitas as $r) {
            if ($r['kind'] === 'recruit') {
                return 'recrutamento';
            }
        }

        return 'inimigo';
    }
}
