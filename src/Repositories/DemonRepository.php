<?php

declare(strict_types=1);

namespace SMT\Repositories;

use SMT\ApiException;
use SMT\Database;
use SMT\Request;

/**
 * Leitura do catálogo: demônios, fichas, raças, species, elementos e skills.
 */
final class DemonRepository
{
    private const SORTABLE = ['level', 'name', 'race', 'hp', 'mp', 'alignment'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Listagem paginada de demônios.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>}
     */
    public function list(Request $r, int $perPageDefault = 25, int $perPageMax = 100): array
    {
        $where = [];
        $args  = [];

        $q = $r->str('q');
        if ($q !== null && $q !== '') {
            $where[]       = 'name LIKE :q';
            $args['q']     = '%' . $q . '%';
        }

        $race = $r->str('race');
        if ($race !== null && $race !== '') {
            $where[]        = 'race = :race';
            $args['race']   = $race;
        }

        $species = $r->str('species');
        if ($species !== null && $species !== '') {
            $where[]          = 'species = :species';
            $args['species']  = $species;
        }

        $alignment = $r->str('alignment');
        if ($alignment !== null && $alignment !== '') {
            $where[]            = 'alignment = :alignment';
            $args['alignment']  = $alignment;
        }

        $levelMin = $r->float('level_min');
        if ($levelMin !== null) {
            $where[]            = 'level >= :level_min';
            $args['level_min']  = $levelMin;
        }

        $levelMax = $r->float('level_max');
        if ($levelMax !== null) {
            $where[]            = 'level <= :level_max';
            $args['level_max']  = $levelMax;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $sort  = (string) ($r->str('sort') ?? 'level');
        $sort  = in_array($sort, self::SORTABLE, true) ? $sort : 'level';
        $order = strtolower((string) ($r->str('order') ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $page    = max(1, (int) ($r->int('page') ?? 1));
        $perPage = (int) ($r->int('per_page') ?? $perPageDefault);
        $perPage = max(1, min($perPageMax, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM v_demons' . $clause, $args);

        $rows = $this->db->all(
            "SELECT * FROM v_demons{$clause} ORDER BY {$sort} {$order}, id LIMIT {$perPage} OFFSET {$offset}",
            $args
        );

        return [array_map([$this, 'cast'], $rows), [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]];
    }

    /** Lista enxuta com os 244 demônios — usada nos seletores do formulário de party. */
    public function options(): array
    {
        $rows = $this->db->all(
            'SELECT id, slug, name, race, level FROM v_demons ORDER BY name'
        );

        return array_map([$this, 'cast'], $rows);
    }

    /** Ficha completa: identificação + skills + resistências. */
    public function findBySlug(string $slug): array
    {
        $demon = $this->db->one('SELECT * FROM v_demons WHERE slug = ?', [$slug]);

        if ($demon === null) {
            throw ApiException::notFound("Demônio \"$slug\" não encontrado.");
        }

        $demon = $this->cast($demon);
        $demon['skills'] = $this->db->all(
            'SELECT skill_id, skill, element, power, cost_mp, target, effect, is_extra
               FROM v_demon_skills WHERE demon_id = ? ORDER BY slot',
            [$demon['id']]
        );
        $demon['resistances'] = $this->db->all(
            'SELECT element_id, element, effect, damage_pct
               FROM v_resistances WHERE demon_id = ? ORDER BY element_id',
            [$demon['id']]
        );

        foreach ($demon['resistances'] as &$res) {
            $res['damage_pct'] = (float) $res['damage_pct'];
        }
        unset($res);

        return $demon;
    }

    public function races(): array
    {
        return $this->db->all(
            'SELECT r.id, r.name, s.name AS species, r.light_dark,
                    COUNT(d.id) AS demon_count
               FROM races r
               JOIN species s ON s.id = r.species_id
               LEFT JOIN demons d ON d.race_id = r.id
              GROUP BY r.id
              ORDER BY r.name'
        );
    }

    public function species(): array
    {
        return $this->db->all(
            'SELECT s.id, s.name,
                    COUNT(DISTINCT r.id) AS race_count,
                    COUNT(d.id) AS demon_count
               FROM species s
               LEFT JOIN races r ON r.species_id = s.id
               LEFT JOIN demons d ON d.race_id = r.id
              GROUP BY s.id
              ORDER BY s.name'
        );
    }

    public function elements(): array
    {
        return $this->db->all(
            'SELECT e.id, e.name, COUNT(s.id) AS skill_count
               FROM elements e
               LEFT JOIN skills s ON s.element_id = e.id
              GROUP BY e.id
              ORDER BY e.id'
        );
    }

    public function skills(Request $r): array
    {
        $where = [];
        $args  = [];

        $q = $r->str('q');
        if ($q !== null && $q !== '') {
            $where[]   = 's.name LIKE :q';
            $args['q'] = '%' . $q . '%';
        }

        $element = $r->str('element');
        if ($element !== null && $element !== '') {
            $where[]       = 'e.name = :element';
            $args['element'] = $element;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return $this->db->all(
            "SELECT s.id, s.slug, s.name, e.name AS element, s.power, s.cost_mp,
                    s.target, s.effect, s.is_extra, s.is_enemy
               FROM skills s
               JOIN elements e ON e.id = s.element_id
               {$clause}
              ORDER BY s.name",
            $args
        );
    }

    /** Contagens gerais para o cabeçalho do app / /health. */
    public function stats(): array
    {
        return [
            'demons'  => (int) $this->db->scalar('SELECT COUNT(*) FROM demons'),
            'races'   => (int) $this->db->scalar('SELECT COUNT(*) FROM races'),
            'species' => (int) $this->db->scalar('SELECT COUNT(*) FROM species'),
            'skills'  => (int) $this->db->scalar('SELECT COUNT(*) FROM skills'),
            'fusions' => (int) $this->db->scalar('SELECT COUNT(*) FROM fusions'),
        ];
    }

    /** Última importação registrada (origem + data). */
    public function lastImport(): ?array
    {
        return $this->db->one(
            'SELECT imported_at, source_ref, demons_count, fusions_count
               FROM import_log ORDER BY id DESC LIMIT 1'
        );
    }

    /** Normaliza tipos: DECIMAL vem do PDO como string. */
    private function cast(array $row): array
    {
        foreach (['level', 'nmax'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (float) $row[$field];
            }
        }
        if (isset($row['damage_pct'])) {
            $row['damage_pct'] = (float) $row['damage_pct'];
        }

        return $row;
    }
}
