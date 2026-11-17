<?php

declare(strict_types=1);

namespace SMT\Repositories;

use SMT\ApiException;
use SMT\Database;
use SMT\Validator;
use Throwable;

/**
 * CRUD de parties — a única parte do banco em que a API escreve.
 *
 * Regras do SMT1 aplicadas como AVISO (não bloqueiam o salvamento):
 *   - demônio com nível acima do protagonista
 *   - mais demônios do que o COMP permite (3, ou 4 com comp_upgraded = 1)
 */
final class PartyRepository
{
    private const ALIGNMENTS = ['Law', 'Neutral', 'Chaos'];
    private const MAX_DEMONS = 5;

    public function __construct(private readonly Database $db)
    {
    }

    /** Lista com contagem de demônios e os mesmos avisos da ficha. */
    public function list(): array
    {
        $rows = $this->db->all('SELECT * FROM v_parties_resumo ORDER BY updated_at DESC, party');

        // formação enxuta de todas as parties, para calcular os avisos de uma vez
        $formacao = [];
        foreach ($this->db->all(
            'SELECT pd.party_id, pd.slot, pd.demon_id, d.name, d.slug, r.name AS race, d.level
               FROM party_demons pd
               JOIN demons d  ON d.id = pd.demon_id
               JOIN races  r  ON r.id = d.race_id
              ORDER BY pd.party_id, pd.slot'
        ) as $d) {
            $formacao[(int) $d['party_id']][] = [
                'slot'        => (int) $d['slot'],
                'demon_id'    => (int) $d['demon_id'],
                'slug'        => $d['slug'],
                'demon'       => $d['name'],
                'race'        => $d['race'],
                'demon_level' => (float) $d['level'],
            ];
        }

        return array_map(function (array $row) use ($formacao): array {
            $id      = (int) $row['party_id'];
            $demons  = $formacao[$id] ?? [];
            $base    = [
                'id'                    => $id,
                'party'                 => $row['party'],
                'protagonist_name'      => $row['protagonist_name'],
                'protagonist_level'     => (int) $row['protagonist_level'],
                'protagonist_alignment' => $row['protagonist_alignment'],
                'comp_upgraded'         => (int) $row['comp_upgraded'],
                'human_partners'        => $row['human_partners'],
                'notes'                 => $row['notes'],
                'demon_count'           => count($demons),
                'comp_limit'            => (int) $row['comp_limit'],
                'created_at'            => $row['created_at'],
                'updated_at'            => $row['updated_at'],
                'demons'                => $demons,
            ];

            $base['avisos'] = $this->avisos($base);

            return $base;
        }, $rows);
    }

    /** Party completa: dados + formação ordenada + avisos. */
    public function find(int $id): array
    {
        $row = $this->db->one('SELECT * FROM v_parties_resumo WHERE party_id = ?', [$id]);

        if ($row === null) {
            throw ApiException::notFound("Party #$id não encontrada.");
        }

        $demons = array_map(function (array $d): array {
            return [
                'slot'        => (int) $d['slot'],
                'demon_id'    => (int) $d['demon_id'],
                'slug'        => $d['slug'],
                'demon'       => $d['demon'],
                'race'        => $d['race'],
                'demon_level' => (float) $d['demon_level'],
            ];
        }, $this->db->all(
            'SELECT slot, demon_id, slug, demon, race, demon_level
               FROM v_parties_completo WHERE party_id = ? ORDER BY slot',
            [$id]
        ));

        $party = [
            'id'                    => (int) $row['party_id'],
            'party'                 => $row['party'],
            'protagonist_name'      => $row['protagonist_name'],
            'protagonist_level'     => (int) $row['protagonist_level'],
            'protagonist_alignment' => $row['protagonist_alignment'],
            'comp_upgraded'         => (int) $row['comp_upgraded'],
            'human_partners'        => $row['human_partners'],
            'notes'                 => $row['notes'],
            'demon_count'           => count($demons),
            'comp_limit'            => (int) $row['comp_limit'],
            'created_at'            => $row['created_at'],
            'updated_at'            => $row['updated_at'],
            'demons'                => $demons,
        ];

        $party['avisos'] = $this->avisos($party);

        return $party;
    }

    /** Cria a party (aceita `demons` opcional para já gravar a formação). */
    public function create(array $payload): array
    {
        $data = $this->normalizar($payload, false);

        if ($this->nomeEmUso((string) $data['name'])) {
            throw ApiException::invalid(['name' => 'já existe uma party com esse nome']);
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO parties (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $this->db->begin();
        try {
            $this->db->exec($sql, array_values($data));
            $id = (int) $this->db->pdo()->lastInsertId();

            if (array_key_exists('demons', $payload)) {
                $this->gravarFormacao($id, $payload['demons']);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->find($id);
    }

    /** Atualiza apenas os campos enviados (PATCH). */
    public function update(int $id, array $payload): array
    {
        $this->find($id);

        $data = $this->normalizar($payload, true);

        if ($data === []) {
            return $this->find($id);
        }

        if (isset($data['name']) && $this->nomeEmUso((string) $data['name'], $id)) {
            throw ApiException::invalid(['name' => 'já existe uma party com esse nome']);
        }

        $sets = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($data)));
        $args = array_values($data);
        $args[] = $id;

        $this->db->exec("UPDATE parties SET $sets WHERE id = ?", $args);

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $this->find($id);
        $this->db->exec('DELETE FROM parties WHERE id = ?', [$id]);
    }

    /** Substitui a formação inteira (mais simples que mexer slot a slot). */
    public function replaceDemons(int $id, mixed $lineup): array
    {
        $this->find($id);

        $this->db->begin();
        try {
            $this->gravarFormacao($id, $lineup);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $this->find($id);
    }

    public function deleteDemon(int $id, int $slot): array
    {
        $this->find($id);

        $removidos = $this->db->exec(
            'DELETE FROM party_demons WHERE party_id = ? AND slot = ?',
            [$id, $slot]
        );

        if ($removidos === 0) {
            throw ApiException::notFound("A party #$id não tem demônio na posição $slot.");
        }

        return $this->find($id);
    }

    // ---------------------------------------------------------------------
    // internos
    // ---------------------------------------------------------------------

    private function gravarFormacao(int $partyId, mixed $lineup): void
    {
        $demons = $this->normalizarFormacao($lineup);

        $this->db->exec('DELETE FROM party_demons WHERE party_id = ?', [$partyId]);

        foreach ($demons as $demon) {
            $this->db->exec(
                'INSERT INTO party_demons (party_id, slot, demon_id) VALUES (?, ?, ?)',
                [$partyId, $demon['slot'], $demon['demon_id']]
            );
        }
    }

    /** @return array<int, array{slot:int, demon_id:int}> */
    private function normalizarFormacao(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw ApiException::invalid(['demons' => 'deve ser uma lista de objetos {slot, demon_id}']);
        }

        if (count($raw) > self::MAX_DEMONS) {
            throw ApiException::invalid(['demons' => 'no máximo ' . self::MAX_DEMONS . ' demônios por party']);
        }

        $v      = new Validator();
        $out    = [];
        $slots  = [];
        $ids    = [];

        foreach (array_values($raw) as $i => $item) {
            if (!is_array($item)) {
                $v->add("demons[$i]", 'deve ser um objeto {slot, demon_id}');
                continue;
            }

            $slot     = (int) ($item['slot'] ?? 0);
            $demonId  = (int) ($item['demon_id'] ?? 0);

            if ($slot < 1 || $slot > self::MAX_DEMONS) {
                $v->add("demons[$i].slot", 'deve estar entre 1 e ' . self::MAX_DEMONS);
            } elseif (isset($slots[$slot])) {
                $v->add("demons[$i].slot", "posição $slot repetida");
            } else {
                $slots[$slot] = true;
            }

            if ($demonId <= 0) {
                $v->add("demons[$i].demon_id", 'informe um demônio válido');
            } elseif (isset($ids[$demonId])) {
                $v->add("demons[$i].demon_id", 'o mesmo demônio não pode repetir na party');
            } else {
                $ids[$demonId] = true;
            }

            $out[] = ['slot' => $slot, 'demon_id' => $demonId];
        }

        $v->throwIfFails();

        // party sem demônios é válida: não há nada a conferir
        if ($ids === []) {
            return [];
        }

        // existência dos demônios
        $marcadores = implode(', ', array_fill(0, count($ids), '?'));
        $existentes = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->all("SELECT id FROM demons WHERE id IN ($marcadores)", array_keys($ids))
        );

        $faltando = array_diff(array_keys($ids), $existentes);
        if ($faltando !== []) {
            throw ApiException::invalid([
                'demons' => 'demônio(s) inexistente(s): ' . implode(', ', $faltando),
            ]);
        }

        usort($out, static fn (array $a, array $b): int => $a['slot'] <=> $b['slot']);

        return $out;
    }

    /** @return array<string, mixed> */
    private function normalizar(array $in, bool $parcial): array
    {
        $v   = new Validator();
        $out = [];

        $tem = static fn (string $k): bool => !$parcial || array_key_exists($k, $in);

        if ($tem('name')) {
            $out['name'] = $v->text($in['name'] ?? null, 'name', 40, true);
        }
        if ($tem('protagonist_name')) {
            $out['protagonist_name'] = $v->text($in['protagonist_name'] ?? null, 'protagonist_name', 40);
        }
        if ($tem('protagonist_level')) {
            $out['protagonist_level'] = $v->integer($in['protagonist_level'] ?? null, 'protagonist_level', 1, 99, 1);
        }
        if ($tem('protagonist_alignment')) {
            $out['protagonist_alignment'] = $v->choice(
                $in['protagonist_alignment'] ?? null,
                'protagonist_alignment',
                self::ALIGNMENTS
            );
        }
        if ($tem('comp_upgraded')) {
            $out['comp_upgraded'] = $v->flag($in['comp_upgraded'] ?? null, 'comp_upgraded');
        }
        if ($tem('human_partners')) {
            $out['human_partners'] = $v->text($in['human_partners'] ?? null, 'human_partners', 80);
        }
        if ($tem('notes')) {
            $out['notes'] = $v->text($in['notes'] ?? null, 'notes', 255);
        }

        $v->throwIfFails();

        return $out;
    }

    private function nomeEmUso(string $name, ?int $ignorarId = null): bool
    {
        $sql  = 'SELECT COUNT(*) FROM parties WHERE name = ?';
        $args = [$name];

        if ($ignorarId !== null) {
            $sql   .= ' AND id <> ?';
            $args[] = $ignorarId;
        }

        return (int) $this->db->scalar($sql, $args) > 0;
    }

    private function avisos(array $party): array
    {
        $limite     = (int) $party['comp_limit'];
        $qtd        = count($party['demons']);
        $nivelHeroi = (int) $party['protagonist_level'];

        $acima = [];
        foreach ($party['demons'] as $demon) {
            if ((float) $demon['demon_level'] > $nivelHeroi) {
                $acima[] = [
                    'slot'        => $demon['slot'],
                    'demon'       => $demon['demon'],
                    'demon_level' => $demon['demon_level'],
                    'excesso'     => (float) $demon['demon_level'] - $nivelHeroi,
                ];
            }
        }

        return [
            'comp' => [
                'demonios' => $qtd,
                'limite'   => $limite,
                'excedido' => $qtd > $limite,
            ],
            'acima_do_nivel' => $acima,
            'tem_avisos'     => $qtd > $limite || $acima !== [],
        ];
    }
}
