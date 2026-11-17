<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  import.php - Importador dos dados de Shin Megami Tensei (SMT1) para MySQL
 * =============================================================================
 *
 *  Fluxo:
 *    1. Baixa (ou usa do cache) os JSONs oficiais do repositório do site
 *       https://github.com/aqiu384/megaten-fusion-tool -> src/app/smt1/data/
 *    2. Executa o schema.sql (tabelas de catálogo são recriadas; party é preservada)
 *    3. Decodifica os códigos compactos do site (resistências, custo de skill...)
 *    4. Recalcula as fusões concretas com as MESMAS funções que o site usa nas abas
 *       "Normal Reverse Fissions" de cada demônio (porte de src/app/smt1/
 *       fusion-data.service.ts -> NormalFusionCalculator + as 4 rotinas de fissão):
 *         normal  -> chart raça x raça; o resultado é o demônio da raça-alvo cujo
 *                    nível cai em [2*(nívelAnterior-1.5), 2*(nívelAlvo-1.5)]
 *         species -> alvos cujas raças formam um grupo (Beast/Holy -> BEASTS...):
 *                    a janela usa a lista de níveis do grupo inteiro
 *         dark    -> regras de módulo 7/5/3 com raças dark; não gera linhas no SMT1
 *                    (darknessRecipes só existe em outros títulos, fora deste escopo)
 *         element -> elemental + demônio da mesma raça (+2/+1/-1) e, quando o alvo
 *                    É um elemental, os pares dentro da própria raça
 *       Total: 13.985 fusões. Detalhes e validação no README.md.
 *    5. Grava tudo em transação e registra a importação em import_log
 *
 *  Atenção: 6 demônios têm nível com casa decimal (ex.: Tam Lin 55.1). Isso é
 *  intencional no site (desempate de fusão) e o nível é preservado como está.
 *
 *  Uso (linha de comando):
 *    php import.php                                  # root@127.0.0.1, banco smt_db
 *    php import.php --user=root --pass=minhaSenha
 *    php import.php --host=localhost --db=smt_db --refresh
 *    php import.php --skip-schema                    # não roda o schema.sql
 *    php import.php --data-dir=./data --offline      # só usa JSONs já baixados
 *
 *  Também aceita variáveis de ambiente:
 *    SMT_DB_HOST, SMT_DB_PORT, SMT_DB_USER, SMT_DB_PASS, SMT_DB_NAME, SMT_DATA_DIR
 *
 *  Requisitos: PHP 8.0+ com PDO MySQL (extensão pdo_mysql) e cURL (ou
 *  allow_url_fopen) para a primeira importação; depois o cache em ./data resolve.
 * =============================================================================
 */

const RAW_BASE     = 'https://raw.githubusercontent.com/aqiu384/megaten-fusion-tool/master/src/app/smt1/data/';
const SOURCE_REF   = 'master';
const JSON_FILES   = [
    'alignments.json',
    'comp-config.json',
    'demon-data.json',
    'element-chart.json',
    'fusion-chart.json',
    'skill-data.json',
    'special-recipes.json',
    'triple-chart.json',
];
const LEVEL_MODIFIER  = 1.5;                       // normalLvlModifier do SMT1
const COST_BASE       = 1000;                      // 1003 => 3 MP
const RECRUIT_RACES   = ['Messian', 'Gaean'];      // só por recrutamento
const ENEMY_RACES     = ['Machine'];               // só como inimigo
const RESIST_EFFECTS  = [                          // 1o dígito do código de resistência
    1 => 'drain',    // absorve HP
    2 => 'repel',    // reflete
    3 => 'null',     // anula
    4 => 'resist',   // reduz o dano (12,5% a 87,5%)
    5 => 'neutral',  // 100%
    6 => 'weak',     // aumenta o dano (150% a 300%)
];
const BATCH_SIZE = 500;

// =============================================================================
//  Utilidades
// =============================================================================

function out(string $msg = ''): void { echo $msg, PHP_EOL; }

function step(string $msg): void { echo '  ', $msg, PHP_EOL; }

function abort(string $msg): void {
    fwrite(STDERR, PHP_EOL . 'ERRO: ' . $msg . PHP_EOL);
    exit(1);
}

function parseOptions(array $argv): array {
    $opts = [
        'host' => getenv('SMT_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('SMT_DB_PORT') ?: '3306',
        'user' => getenv('SMT_DB_USER') ?: 'root',
        'pass' => getenv('SMT_DB_PASS') ?: '',
        'db'   => getenv('SMT_DB_NAME') ?: 'smt_db',
        'data-dir'    => getenv('SMT_DATA_DIR') ?: __DIR__ . '/data',
        'schema'      => __DIR__ . '/schema.sql',
        'dump-rules'  => '',
        'refresh'     => false,
        'offline'     => false,
        'skip-schema' => false,
        'quiet'       => false,
        'help'        => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '-h' || $arg === '--help') { $opts['help'] = true; continue; }
        if (strncmp($arg, '--', 2) !== 0) { continue; }

        $body = substr($arg, 2);
        $eq   = strpos($body, '=');

        if ($eq === false) {
            $key = $body;
            $val = true;
        } else {
            $key = substr($body, 0, $eq);
            $val = substr($body, $eq + 1);
        }

        if (!array_key_exists($key, $opts)) { abort("opção desconhecida: --$key"); }
        $opts[$key] = $val;
    }

    return $opts;
}

function usage(): void {
    out('Importador dos dados de Shin Megami Tensei (SMT1) para MySQL/MariaDB.');
    out();
    out('Uso: php import.php [opções]');
    out();
    out('  --host=127.0.0.1     servidor MySQL                (env SMT_DB_HOST)');
    out('  --port=3306          porta                          (env SMT_DB_PORT)');
    out('  --user=root          usuário                        (env SMT_DB_USER)');
    out('  --pass=              senha (pode ser vazia)         (env SMT_DB_PASS)');
    out('  --db=smt_db          nome do banco                  (env SMT_DB_NAME)');
    out('  --data-dir=./data    pasta dos JSONs                (env SMT_DATA_DIR)');
    out('  --schema=./schema.sql  arquivo de schema');
    out('  --refresh            baixa os JSONs de novo');
    out('  --offline            não acessa a internet');
    out('  --skip-schema        não executa o schema.sql');
    out('  --quiet              imprime só o resumo final');
    out('  --dump-rules=arq.csv grava a conferência de fusões por demônio (debug)');
    out();
}

/** Baixa/valida os JSONs de origem e devolve o conteúdo decodificado. */
function loadSourceData(string $dataDir, bool $refresh, bool $offline): array {
    if (!is_dir($dataDir) && !@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
        abort("não foi possível criar a pasta de dados: $dataDir");
    }

    $data   = [];
    $hashes = [];

    foreach (JSON_FILES as $file) {
        $path = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . $file;

        if ($refresh || !is_file($path)) {
            if ($offline) {
                abort("arquivo ausente no modo offline: $path");
            }

            $body = httpGet(RAW_BASE . $file);

            if ($body === null) {
                abort('falha ao baixar ' . RAW_BASE . $file
                    . ' (verifique a conexão ou use --offline apontando --data-dir para o cache)');
            }

            file_put_contents($path, $body);
        }

        $raw     = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            abort("JSON inválido em $path");
        }

        $data[$file]   = $decoded;
        $hashes[$file] = hash('sha256', $raw);
    }

    ksort($hashes);
    $data['__checksum'] = hash('sha256', implode('', $hashes));
    $data['__files']    = $hashes;

    return $data;
}

function httpGet(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'smt-db-import/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $code === 200) ? (string) $body : null;
    }

    $ctx  = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'smt-db-import/1.0']]);
    $body = @file_get_contents($url, false, $ctx);

    return $body === false ? null : $body;
}

/** Divide um arquivo .sql em comandos, respeitando strings, backticks e comentários. */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $buffer     = '';
    $len        = strlen($sql);
    $quote      = null;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($quote === null) {
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i  = $nl === false ? $len : $nl;
                continue;
            }
            if ($ch === '#') {
                $nl = strpos($sql, "\n", $i);
                $i  = $nl === false ? $len : $nl;
                continue;
            }
            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote  = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $stmt = trim($buffer);
                if ($stmt !== '') { $statements[] = $stmt; }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
            continue;
        }

        $buffer .= $ch;

        if ($ch === '\\' && $quote !== '`') {
            $buffer .= $sql[$i + 1] ?? '';
            $i++;
            continue;
        }
        if ($ch === $quote) {
            if (($sql[$i + 1] ?? '') === $quote) {
                $buffer .= $quote;
                $i++;
                continue;
            }
            $quote = null;
        }
    }

    $stmt = trim($buffer);
    if ($stmt !== '') { $statements[] = $stmt; }

    return $statements;
}

function slugify(string $name): string {
    $slug = strtolower(trim($name));
    $slug = str_replace(["'", '’', '.'], '', $slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

    return trim($slug, '-');
}

function alignmentFromCode(string $code): string {
    $letter = strtolower($code[1] ?? 'n');

    return $letter === 'l' ? 'Law' : ($letter === 'c' ? 'Chaos' : 'Neutral');
}

/** Como no site: 'l' = light (1), 'd' = dark (-1), 'D' = dark extremo (-2). */
function lightDarkFromCode(string $code): int {
    $letter = $code[0] ?? 'n';

    if ($letter === 'l') { return 1; }
    if ($letter === 'd') { return -1; }
    if ($letter === 'D') { return -2; }

    return 0;
}

/** Decodifica um caractere de resistência em [efeito, percentual]. */
function resistFromCode(float $code): array {
    $class = (int) ($code / 1000);
    $pct   = $code - $class * 1000;

    if (!isset(RESIST_EFFECTS[$class])) {
        abort("código de resistência desconhecido: $code");
    }

    return [RESIST_EFFECTS[$class], $pct];
}

/** Insere linhas em lote. */
function insertRows(PDO $pdo, string $table, array $columns, array $rows, int $chunk = BATCH_SIZE): int {
    if ($rows === []) { return 0; }

    $cols     = '`' . implode('`, `', $columns) . '`';
    $single   = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $inserted = 0;
    $total    = count($rows);

    for ($offset = 0; $offset < $total; $offset += $chunk) {
        $slice  = array_slice($rows, $offset, $chunk);
        $sql    = "INSERT INTO `$table` ($cols) VALUES " . implode(', ', array_fill(0, count($slice), $single));
        $params = [];

        foreach ($slice as $row) {
            foreach ($row as $value) { $params[] = $value; }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted += count($slice);
    }

    return $inserted;
}

// =============================================================================
//  Montagem do catálogo (tudo o que sai direto dos JSONs)
// =============================================================================

function buildCatalog(array $json): array {
    $comp       = $json['comp-config.json'];
    $alignments = $json['alignments.json']['races'];
    $demonData  = $json['demon-data.json'];
    $skillData  = $json['skill-data.json'];
    $fusionJson = $json['fusion-chart.json'];
    $tripleJson = $json['triple-chart.json'];
    $elemJson   = $json['element-chart.json'];
    $specialRaw = $json['special-recipes.json'];

    // ---------------------------------------------------------------- grupos (species)
    $tripleGroups = $tripleJson['races'];
    $speciesRows  = [];
    $speciesId    = [];

    foreach ($comp['species'] as $index => $group) {
        $name = $group[0];
        $id   = $index + 1;
        $speciesId[$name] = $id;
        $speciesRows[] = [$id, $name, in_array($name, $tripleGroups, true) ? 1 : 0, $id];
    }

    // ---------------------------------------------------------------- raças
    $normalRaces = $fusionJson['races'];
    $darkRaces   = array_keys($fusionJson['darks']);
    $raceRows    = [];
    $raceId      = [];
    $raceOrder   = 0;

    foreach ($comp['species'] as $group) {
        $speciesName = $group[0];

        foreach (array_slice($group, 1) as $raceName) {
            $raceOrder++;

            if (!isset($alignments[$raceName])) { abort("raça sem alinhamento definido: $raceName"); }

            $code = $alignments[$raceName];
            $raceId[$raceName] = $raceOrder;
            $raceRows[] = [
                $raceOrder,
                $raceName,
                $speciesId[$speciesName],
                $code,
                alignmentFromCode($code),
                lightDarkFromCode($code),
                in_array($raceName, $normalRaces, true) ? 1 : 0,
                $raceOrder,
            ];
        }
    }

    // ---------------------------------------------------------------- elementos
    $elemRows  = [];
    $elemId    = [];
    $elemOrder = 0;

    foreach ($comp['resistElems'] as $name) {
        $elemOrder++;
        $elemId[$name] = $elemOrder;
        $elemRows[] = [$elemOrder, $name, 1, 1, $elemOrder];
    }

    foreach ($comp['skillElems'] as $name) {
        if (isset($elemId[$name])) { continue; }
        $elemOrder++;
        $elemId[$name] = $elemOrder;
        $elemRows[] = [$elemOrder, $name, 0, 1, $elemOrder];
    }

    // ---------------------------------------------------------------- skills
    $skillRows  = [];
    $skillId    = [];
    $skillOrder = 0;

    foreach ($skillData as $name => $row) {
        $skillOrder++;
        $skillId[$name] = $skillOrder;

        $cost = $row['cost'] ?? null;
        $mp   = $cost === null ? null : (int) $cost - COST_BASE;

        if (!isset($elemId[$row['element']])) { abort("elemento de skill desconhecido: {$row['element']} ($name)"); }

        $skillRows[] = [
            $skillOrder,
            slugify($name),
            $name,
            $elemId[$row['element']],
            $row['power'] ?? null,
            $mp,
            $cost === null ? 1 : 0,
            $row['target'] ?? null,
            $row['effect'] ?? null,
            empty($row['enemy']) ? 0 : 1,
        ];
    }

    // ---------------------------------------------------------------- demônios
    $demonRows      = [];
    $demonIdByName  = [];
    $demonMeta      = [];
    $resistRows     = [];
    $demonSkillRows = [];
    $usedSlugs      = [];
    $demonOrder     = 0;

    foreach ($demonData as $name => $row) {
        $demonOrder++;
        $raceName = $row['race'];

        if (!isset($raceId[$raceName])) { abort("raça desconhecida no demônio $name: $raceName"); }

        $stats = $row['stats'];
        $atks  = $row['atks'];
        $code  = $row['align'];
        // usado SÓ em memória: define quem pode ser RESULTADO de fusão
        // (não é mais gravado em coluna — ver migrate_004)
        $kind  = fusionKindFor($name, $raceName, $specialRaw);

        $slug = slugify($name);
        if (isset($usedSlugs[$slug])) {
            $suffix = 2;
            while (isset($usedSlugs[$slug . '-' . $suffix])) { $suffix++; }
            $slug .= '-' . $suffix;
        }
        $usedSlugs[$slug] = true;

        $demonRows[] = [
            $demonOrder, $slug, $name, $raceId[$raceName], $row['lvl'] + 0, $code, alignmentFromCode($code),
            $row['drop'] ?? null,
            $stats[0], $stats[1], $stats[2], $stats[3], $stats[4], $stats[5], $stats[6], $stats[7], $stats[8],
            $atks[0], $atks[1], $atks[2], $atks[3], $atks[4], $atks[5], $atks[6], $atks[7], $atks[8],
        ];

        $demonIdByName[$name] = $demonOrder;
        $demonMeta[$name] = [
            'id'      => $demonOrder,
            'race_id' => $raceId[$raceName],
            'race'    => $raceName,
            'lvl'     => $row['lvl'] + 0,   // preserva níveis fracionários (ex.: 55.1), usados como desempate
            'kind'    => $kind,
        ];

        // resistências: 14 caracteres, um por elemento, na ordem de comp-config
        $chars = str_split($row['resists']);
        $codes = $comp['resistCodes'];

        foreach ($comp['resistElems'] as $index => $elemName) {
            $char = $chars[$index] ?? '-';
            if (!isset($codes[$char])) { abort("código de resistência desconhecido '$char' no demônio $name"); }

            [$effect, $pct] = resistFromCode((float) $codes[$char]);
            $resistRows[] = [$demonOrder, $elemId[$elemName], $effect, $pct];
        }

        // skills inatas
        $slot = 0;
        foreach ($row['skills'] as $skillName) {
            if ($skillName === '') { continue; }
            if (!isset($skillId[$skillName])) { abort("skill desconhecida '$skillName' no demônio $name"); }
            $slot++;
            $demonSkillRows[] = [$demonOrder, $skillId[$skillName], $slot];
        }
    }

    // ---------------------------------------------------------------- chart raça x raça
    $chartRows = [];
    $chart     = [];   // [raçaA][raçaB] = valor cru do JSON
    $racesList = $fusionJson['races'];
    $tableRows = $fusionJson['table'];
    $inverted  = count($tableRows[0]) === 1;

    foreach ($tableRows as $rIndex => $row) {
        $raceA  = $racesList[$rIndex];
        $offset = $inverted ? 0 : count($racesList) - count($row);

        foreach ($row as $cIndex => $value) {
            if ($value === '-') { continue; }
            $raceB = $racesList[$cIndex + $offset];
            $chart[$raceA][$raceB] = $value;
        }
    }

    // conversões das raças dark (mapa "darks" do JSON): preservadas em tabela própria;
    // NÃO entram no chart normal, pois o site atual do SMT1 não usa darknessRecipes
    $darkRecipeRows = [];

    foreach ($fusionJson['darks'] as $darkRaceName => $resultRaceName) {
        if (!isset($raceId[$darkRaceName], $raceId[$resultRaceName])) { continue; }
        $darkRecipeRows[] = [$raceId[$darkRaceName], $raceId[$resultRaceName]];
    }

    $seenChart = [];

    foreach ($chart as $raceA => $cells) {
        foreach ($cells as $raceB => $value) {
            $idA = $raceId[$raceA];
            $idB = $raceId[$raceB];
            $key = min($idA, $idB) . '|' . max($idA, $idB);

            if (isset($seenChart[$key])) { continue; }
            $seenChart[$key] = true;

            $type = $raceRes = $speciesRes = $demonRes = null;

            if (isset($raceId[$value])) {
                $type    = 'race';
                $raceRes = $raceId[$value];
            } elseif (isset($speciesId[$value])) {
                $type       = 'species';
                $speciesRes = $speciesId[$value];
            } elseif (isset($demonIdByName[$value])) {
                $type     = 'element_demon';
                $demonRes = $demonIdByName[$value];
            } else {
                abort("valor desconhecido no chart de fusão: '$value' ($raceA x $raceB)");
            }

            $chartRows[] = [min($idA, $idB), max($idA, $idB), $type, $raceRes, $speciesRes, $demonRes];
        }
    }

    // ---------------------------------------------------------------- chart de species (fusões triplas)
    // O arquivo traz 1 linha por par de grupos (28 pares) e 8 colunas (o 3o grupo):
    // [grupo A] + [grupo B] + [3o grupo] = raça resultante.
    $speciesChartRows = [];
    $groupNames       = $tripleJson['races'];
    $pairs            = [];

    for ($n1 = 0; $n1 < count($groupNames); $n1++) {
        for ($n2 = $n1 + 1; $n2 < count($groupNames); $n2++) {
            $pairs[] = [$groupNames[$n1], $groupNames[$n2]];
        }
    }

    if (count($tripleJson['table']) !== count($pairs)) {
        abort('formato inesperado do triple-chart.json: '
            . count($tripleJson['table']) . ' linhas para ' . count($pairs) . ' pares de grupos');
    }

    foreach ($tripleJson['table'] as $rIndex => $row) {
        [$speciesA, $speciesB] = $pairs[$rIndex];

        foreach ($row as $cIndex => $value) {
            $speciesC = $groupNames[$cIndex];
            $result   = ($value === '?') ? null : ($raceId[$value] ?? null);

            if ($value !== '?' && $result === null) {
                abort("raça desconhecida no chart de species: '$value'");
            }

            $speciesChartRows[] = [$speciesId[$speciesA], $speciesId[$speciesB], $speciesId[$speciesC], $result];
        }
    }

    // ---------------------------------------------------------------- elementais
    $elemChartRows  = [];
    $elemFusionRows = [];
    $elemDemonName  = $elemJson['elems'];

    foreach ($elemJson['table'] as $rIndex => $row) {
        $raceName = $elemJson['races'][$rIndex];

        foreach ($row as $cIndex => $value) {
            if (!$value) { continue; }
            $elemChartRows[] = [
                $demonIdByName[$elemDemonName[$cIndex]],
                $raceId[$raceName],
                (int) $value,
            ];
        }
    }

    foreach ($elemJson['pairs'] as $rIndex => $row) {
        foreach ($row as $cIndex => $value) {
            if ($value === '-') { continue; }
            $elemFusionRows[] = [
                $demonIdByName[$elemDemonName[$rIndex]],
                $demonIdByName[$elemDemonName[$cIndex]],
                $demonIdByName[$value],
            ];
        }
    }

    // ---------------------------------------------------------------- receitas especiais
    $specialRows = [];
    $merged      = $specialRaw;

    foreach ($demonData as $name => $row) {
        if (in_array($row['race'], RECRUIT_RACES, true)) {
            $merged[$name] = ['fusion' => 'recruit', 'prereq' => 'Recruitment only'];
        } elseif (in_array($row['race'], ENEMY_RACES, true)) {
            $merged[$name] = ['fusion' => 'enemy', 'prereq' => 'Enemy only'];
        }
    }

    foreach ($merged as $target => $recipe) {
        if (!isset($demonIdByName[$target])) { continue; }

        $targetId = $demonIdByName[$target];
        $kind     = null;
        $texts    = [];

        if (!empty($recipe['pairs'])) {
            $kind  = 'pair';
            $texts = $recipe['pairs'];
        } elseif (!empty($recipe['trios'])) {
            $kind  = 'trio';
            $texts = $recipe['trios'];
        } elseif (!empty($recipe['prereq'])) {
            $fusionKind = $recipe['fusion'] ?? '';
            $kind  = $fusionKind === 'recruit' ? 'recruit' : ($fusionKind === 'enemy' ? 'enemy' : 'prereq');
            $texts = [$recipe['prereq']];
        }

        if ($kind === null) { continue; }

        foreach ($texts as $text) {
            $parts = preg_split('/\s+x\s+/u', (string) $text) ?: [];
            $ids   = [null, null, null];

            foreach ($parts as $index => $partName) {
                if ($index > 2) { break; }
                $ids[$index] = $demonIdByName[$partName] ?? null;
            }

            $specialRows[] = [$targetId, $kind, (string) $text, $ids[0], $ids[1], $ids[2]];
        }
    }

    return [
        'species'        => $speciesRows,
        'races'          => $raceRows,
        'elements'       => $elemRows,
        'skills'         => $skillRows,
        'demons'         => $demonRows,
        'resists'        => $resistRows,
        'demonSkills'    => $demonSkillRows,
        'fusionChart'    => $chartRows,
        'speciesChart'   => $speciesChartRows,
        'elementChart'   => $elemChartRows,
        'elementFusions' => $elemFusionRows,
        'specialFusions' => $specialRows,
        // contexto usado pelo cálculo das fusões
        'raceId'         => $raceId,
        'speciesId'      => $speciesId,
        'demonIdByName'  => $demonIdByName,
        'demonMeta'      => $demonMeta,
        'darkRecipes'    => $darkRecipeRows,
    ];
}

/**
 * Espelha o `demon.fusion` do site — usado APENAS durante o cálculo, não é
 * gravado no banco:
 *   - raças de recrutamento (Messian/Gaean) -> recruit
 *   - Machine -> enemy
 *   - demônios com receita fixa cujo campo `fusion` diz "normal" -> normal
 *   - demais -> normal
 * Regra do site: só demônios com fusion "normal" entram como RESULTADO.
 */
function fusionKindFor(string $name, string $race, array $specialRaw): string {
    if (in_array($race, RECRUIT_RACES, true)) { return 'recruit'; }
    if (in_array($race, ENEMY_RACES, true))   { return 'enemy'; }

    if (isset($specialRaw[$name])) {
        $fusion = (string) ($specialRaw[$name]['fusion'] ?? '');

        return $fusion !== '' ? $fusion : 'special';
    }

    return 'normal';
}

// =============================================================================
//  Cálculo das fusões concretas
//
//  Porte 1:1 do que o site faz na aba "Normal Reverse Fissions" de cada demônio
//  (src/app/smt1/fusion-data.service.ts):
//
//      fissionCalculator = NormalFusionCalculator(
//          [ splitWithDiffRace, splitWithSpecies, splitWithDarkRace, splitWithElement ],
//          [ splitElement ]);
//
//  Fontes: compendium/fusions/smt-nonelem-fissions.ts, snes-dark-fusions.ts,
//          smt-element-fissions.ts, smt1/models/compendium.ts, smt1/models/fusion-chart.ts
//
//  Detalhes verificados contra o site (commit 0b207612):
//   * só demônios com fusion = 'normal' entram na lista de RESULTADOS de fusão;
//   * a chart usada é a normal (22 raças), SEM as raças dark — a regra "dark"
//     fica sem linhas no SMT1 atual, pois darknessRecipes não é habilitado;
//   * a exclusão de raças no agrupamento de species testa apenas 'd' minúsculo;
//     as raças dark do SMT1 usam 'D' (Dc/Dl/Dn) e por isso NÃO são excluídas;
//   * demônios elementais usam somente splitElement (pares dentro da própria raça).
// =============================================================================

/** findBin do site: devolve o índice do maior bin menor que n (ou o último). */
function findBin(int|float $n, array $bins): int {
    if ($bins === []) { return -1; }

    $index = 0;

    foreach ($bins as $bin) {
        if ($n > $bin) { $index++; }
    }

    return $index === count($bins) ? $index - 1 : $index;
}

function buildFusions(array $catalog, array $json, array &$targetCounts = []): array {
    $comp       = $json['comp-config.json'];
    $alignments = $json['alignments.json']['races'];
    $chartJson  = $json['fusion-chart.json'];
    $elemJson   = $json['element-chart.json'];

    /// speciesLookup: raça -> grupo (species)
    $speciesLookup = [];

    foreach ($comp['species'] as $group) {
        foreach (array_slice($group, 1) as $raceName) { $speciesLookup[$raceName] = $group[0]; }
    }

    // ---------------------------------------------------------- índices do compendium
    // (updateDerivedData do smt1/models/compendium.ts)
    $inv = [];   // [raça ou grupo][nível] = nome do demônio (último cadastrado vence)
    $ing = [];   // [raça ou grupo]      = níveis dos ingredientes (todos)
    $res = [];   // [raça ou grupo]      = níveis dos resultados (fusion = 'normal')

    foreach ($catalog['demonMeta'] as $name => $info) {
        $raceName = $info['race'];
        $lvl      = $info['lvl'];

        $inv[$raceName][$lvl] = $name;
        $ing[$raceName][]     = $lvl;

        if ($info['kind'] === 'normal') { $res[$raceName][] = $lvl; }
    }

    // agrupamento por species: percorre as raças de trás para frente, como o site
    foreach ($comp['species'] as $group) {
        $speciesName = $group[0];
        $memberRaces = array_slice($group, 1);

        $inv[$speciesName] = [];
        $ing[$speciesName] = [];
        $res[$speciesName] = [];

        for ($i = count($memberRaces); $i > 0; $i--) {
            $raceName = $memberRaces[$i - 1];

            if (($alignments[$raceName][0] ?? '') === 'd') { continue; }

            foreach ($inv[$raceName] ?? [] as $lvl => $demonName) { $inv[$speciesName][$lvl] = $demonName; }
            $ing[$speciesName] = array_merge($ing[$speciesName], $ing[$raceName] ?? []);
            $res[$speciesName] = array_merge($res[$speciesName], $res[$raceName] ?? []);
        }
    }

    foreach (array_keys($ing) as $raceName) { sort($ing[$raceName]); }
    foreach (array_keys($res) as $raceName) { sort($res[$raceName]); }

    // ---------------------------------------------------------- chart normal (FusionChart)
    $chartRaces = $chartJson['races'];
    $elements   = $elemJson['elems'];

    $fusionTable  = [];   // [raçaA][raçaB]  = valor da célula
    $fissionTable = [];   // [valor][raçaA]  = [raçaB, ...]

    $table    = $chartJson['table'];
    $inverted = count($table[0]) === 1;

    foreach ($table as $rIndex => $row) {
        $raceA  = $chartRaces[$rIndex];
        $offset = $inverted ? 0 : count($chartRaces) - count($row);

        foreach ($row as $cIndex => $value) {
            if ($value === '-') { continue; }

            $raceB = $chartRaces[$cIndex + $offset];

            $fusionTable[$raceA][$raceB] = $value;
            $fusionTable[$raceB][$raceA] = $value;
            $fissionTable[$value][$raceA][] = $raceB;
        }
    }

    // useSpeciesFusion: cada raça com linha de fissão recebe a chave "2<grupo>"
    foreach ($chartRaces as $raceName) {
        if (!isset($fissionTable[$raceName])) { continue; }

        $speciesName = $speciesLookup[$raceName] ?? null;

        if ($speciesName === null) { continue; }

        $fissionTable[$raceName]['2' . $speciesName] = ['2' . $speciesName];
    }

    // ---------------------------------------------------------- modificadores dos elementais
    $elemTable = [];   // [elemental][raça] = mod

    foreach ($elemJson['table'] as $rIndex => $row) {
        $raceName = $elemJson['races'][$rIndex];

        foreach ($row as $cIndex => $mod) {
            if (!$mod) { continue; }
            $elemTable[$elements[$cIndex]][$raceName] = (int) $mod;
        }
    }

    $elemModifiers = [];   // [raça][mod] = [nomes dos elementais]

    foreach ($elements as $elemName) {
        foreach ($elemTable[$elemName] ?? [] as $raceName => $mod) {
            $elemModifiers[$raceName][$mod][] = $elemName;
        }
    }

    foreach ($elemModifiers as $raceName => $byMod) {
        ksort($byMod);
        $elemModifiers[$raceName] = $byMod;
    }

    // ---------------------------------------------------------- helpers
    $lookup = static function (string $raceName, int|float|null $lvl) use (&$inv): ?string {
        if ($lvl === null) { return null; }

        return $inv[$raceName][$lvl] ?? null;
    };

    $lightDarkOf = static function (string $raceName) use ($alignments): int {
        $char = $alignments[$raceName][0] ?? 'n';

        if ($char === 'l') { return 1; }
        if ($char === 'd') { return -1; }
        if ($char === 'D') { return -2; }

        return 0;
    };

    $demonIdByName = $catalog['demonIdByName'];

    $fusions = [];
    $counts  = ['normal' => 0, 'species' => 0, 'dark' => 0, 'element' => 0];
    $skipped = 0;

    $add = static function (?string $nameA, ?string $nameB, string $targetName, string $rule)
        use (&$fusions, &$counts, &$targetCounts, &$skipped, $demonIdByName): void
    {
        if ($nameA === null || $nameB === null) { $skipped++; return; }

        $resultId = $demonIdByName[$targetName];
        $idA      = $demonIdByName[$nameA];
        $idB      = $demonIdByName[$nameB];
        $key      = $rule . '|' . $resultId . '|' . min($idA, $idB) . '|' . max($idA, $idB);

        if (isset($fusions[$key])) { return; }

        $fusions[$key] = [$resultId, min($idA, $idB), max($idA, $idB), $rule];
        $counts[$rule]++;
        $targetCounts[$resultId][$rule] = ($targetCounts[$resultId][$rule] ?? 0) + 1;
    };

    foreach ($catalog['demonMeta'] as $name => $info) {
        $lvl  = $info['lvl'];
        $race = $info['race'];

        // ================================================ elementais: splitElement
        if (in_array($name, $elements, true)) {
            foreach ($fissionTable[$name] ?? [] as $raceA => $raceBs) {
                $lvls  = $ing[$raceA] ?? [];
                $total = count($lvls);

                for ($i = 0; $i < $total; $i++) {
                    for ($j = $i + 1; $j < $total; $j++) {
                        $add($lookup($raceA, $lvls[$i]), $lookup($raceA, $lvls[$j]), $name, 'element');
                    }
                }
            }

            continue;
        }

        // ================================================ regra 1: normal (splitWithDiffRace)
        $resultLvls = $res[$race] ?? [];
        $index      = array_search($lvl, $resultLvls, true);

        if ($index !== false) {
            $minResultLvl = $index >= 1 ? 2 * ($resultLvls[$index - 1] - LEVEL_MODIFIER) : 0;
            $maxResultLvl = isset($resultLvls[$index + 1]) ? 2 * ($lvl - LEVEL_MODIFIER) : 200;

            foreach ($fissionTable[$race] ?? [] as $raceA => $raceBs) {
                foreach ($ing[$raceA] ?? [] as $lvlA) {
                    $minLvlB = $minResultLvl - $lvlA;
                    $maxLvlB = $maxResultLvl - $lvlA;

                    foreach ($raceBs as $raceB) {
                        foreach ($ing[$raceB] ?? [] as $lvlB) {
                            if ($minLvlB < $lvlB && $lvlB <= $maxLvlB && ($raceA !== $raceB || $lvlA < $lvlB)) {
                                $add($lookup($raceA, $lvlA), $lookup($raceB, $lvlB), $name, 'normal');
                            }
                        }
                    }
                }
            }
        }

        // ================================================ regra 2: species (splitWithSpecies)
        $twoSpecies = null;

        foreach (array_keys($fissionTable[$race] ?? []) as $key) {
            if (is_string($key) && $key !== '' && $key[0] === '2') { $twoSpecies = $key; break; }
        }

        if ($twoSpecies !== null) {
            $targetSpecies = substr($twoSpecies, 1);
            $resultLvls    = $res[$targetSpecies] ?? [];
            $index         = array_search($lvl, $resultLvls, true);

            if ($index !== false) {
                $pr1ResultLvl = $index >= 2 ? $resultLvls[$index - 2] : 0;
                $pr2ResultLvl = $index >= 1 ? $resultLvls[$index - 1] : 0;
                $maxResultLvl = isset($resultLvls[$index + 1]) ? 2 * $lvl : 200;

                foreach ($fissionTable[$targetSpecies] ?? [] as $raceA => $raceBs) {
                    foreach ($ing[$raceA] ?? [] as $lvlA) {
                        if ($lvlA === $lvl) { continue; }

                        foreach ($raceBs as $raceB) {
                            foreach ($ing[$raceB] ?? [] as $lvlB) {
                                if ($lvlB === $lvl) { continue; }

                                $minResultLvl = ($lvlA === $pr2ResultLvl || $lvlB === $pr2ResultLvl)
                                    ? $pr1ResultLvl : $pr2ResultLvl;
                                $resultLvl = $lvlA + $lvlB + LEVEL_MODIFIER;

                                if (2 * $minResultLvl < $resultLvl && $resultLvl <= $maxResultLvl) {
                                    $add($lookup($raceA, $lvlA), $lookup($raceB, $lvlB), $name, 'species');
                                }
                            }
                        }
                    }
                }
            }
        }

        // ================================================ regra 3: dark (splitWithDarkRace)
        $lvlAs = array_merge([0, 0], $res[$race] ?? [], [100, 100]);
        $indR  = array_search($lvl, $lvlAs, true);

        if ($lightDarkOf($race) >= 0 && $indR !== false) {
            $lvlA7 = $indR >= 2 ? $lvlAs[$indR - 2] : 0;
            $lvlA5 = $indR >= 1 ? $lvlAs[$indR - 1] : 0;
            $lvlA3 = $lvlAs[$indR + 1] ?? 100;

            $nameA7 = $lvlA7 !== 0 ? $lookup($race, $lvlA7) : null;
            $nameA5 = $lvlA5 !== 0 ? $lookup($race, $lvlA5) : null;
            $nameA3 = $lvlA3 !== 100 ? $lookup($race, $lvlA3) : null;

            foreach ($chartRaces as $raceB) {
                if ($lightDarkOf($raceB) >= 0) { continue; }

                foreach ($ing[$raceB] ?? [] as $lvlB) {
                    $nameB = $lookup($raceB, $lvlB);

                    if ($lvlA7 >= $lvlB && ($lvlA7 + $lvlB) % 7 === 0) {
                        $add($nameA7, $nameB, $name, 'dark');
                    }
                    if ($lvlA5 >= $lvlB && ($lvlA5 + $lvlB) % 7 !== 0 && ($lvlA5 + $lvlB) % 5 === 0) {
                        $add($nameA5, $nameB, $name, 'dark');
                    }
                    if ($lvlA3 >= $lvlB && ($lvlA3 + $lvlB) % 7 !== 0 && ($lvlA3 + $lvlB) % 5 !== 0 && ($lvlA3 + $lvlB) % 3 === 0) {
                        $add($nameA3, $nameB, $name, 'dark');
                    }
                }
            }
        }

        // ================================================ regra 4: elementais (splitWithElement)
        $modifiers = $elemModifiers[$race] ?? [];

        if ($modifiers === []) { continue; }

        $baseResultLvls = array_merge([0, 0], $res[$race] ?? [], [100, 100]);

        foreach ($ing[$race] ?? [] as $ingLvl) {
            $resultLvls = $baseResultLvls;

            if (array_search($ingLvl, $resultLvls, true) === false) {
                $resultLvls[] = $ingLvl;
                sort($resultLvls);
            }

            $ingLvlIndex = array_search($ingLvl, $resultLvls, true);

            foreach ($modifiers as $offset => $elemNames) {
                $shift = $ingLvlIndex + $offset;

                if (!isset($resultLvls[$shift]) || $resultLvls[$shift] !== $lvl) { continue; }

                foreach ($elemNames as $elemName) {
                    $add($lookup($race, $ingLvl), $elemName, $name, 'element');
                }
            }
        }
    }

    return [array_values($fusions), $counts, $skipped];
}

// =============================================================================
//  Main
// =============================================================================

function main(array $argv): void {
    $opts  = parseOptions($argv);
    $quiet = (bool) $opts['quiet'];

    if ($opts['help']) { usage(); exit(0); }

    $show = function (string $msg) use ($quiet): void { if (!$quiet) { step($msg); } };

    out('==========================================================================');
    out(' Importacao SMT1 -> MySQL   |   origem: ' . RAW_BASE);
    out('==========================================================================');

    // 1) dados de origem ------------------------------------------------------
    out();
    out('[1/6] Dados de origem');
    $json = loadSourceData((string) $opts['data-dir'], (bool) $opts['refresh'], (bool) $opts['offline']);
    $show('origem: ' . count(JSON_FILES) . ' arquivos JSON em ' . $opts['data-dir']);
    $show('checksum sha256: ' . substr((string) $json['__checksum'], 0, 32) . '...');

    // 2) catálogo -------------------------------------------------------------
    out();
    out('[2/6] Montando catalogo');
    $catalog = buildCatalog($json);
    $show('species: ' . count($catalog['species']) . ' | raças: ' . count($catalog['races']) . ' | elementos: ' . count($catalog['elements']));
    $show('demônios: ' . count($catalog['demons']) . ' | skills: ' . count($catalog['skills'])
          . ' | resistências: ' . count($catalog['resists']) . ' | skills inatas: ' . count($catalog['demonSkills']));
    $show('chart raça x raça: ' . count($catalog['fusionChart']) . ' | chart triplo (species): ' . count($catalog['speciesChart'])
          . ' | receitas especiais: ' . count($catalog['specialFusions']));

    // 3) fusões ---------------------------------------------------------------
    out();
    out('[3/6] Calculando fusoes pre-calculadas (4 regras)');
    $targetCounts = [];
    [$fusions, $ruleCounts, $skippedFusions] = buildFusions($catalog, $json, $targetCounts);
    $show('normal: ' . $ruleCounts['normal'] . ' | species: ' . $ruleCounts['species']
          . ' | dark: ' . $ruleCounts['dark'] . ' | element: ' . $ruleCounts['element']);
    $show('total de fusões concretas: ' . count($fusions));

    if ($skippedFusions > 0) {
        $show('AVISO: ' . $skippedFusions . ' pares sem nome resolvido foram descartados');
    }

    if (($opts['dump-rules'] ?? '') !== '') {
        $nameById  = [];
        $raceById  = [];
        foreach ($catalog['demons'] as $row) { $nameById[$row[0]] = $row[2]; }
        foreach ($catalog['races'] as $row)  { $raceById[$row[0]] = $row[1]; }
        $raceOfDemon = [];
        foreach ($catalog['demons'] as $row) { $raceOfDemon[$row[0]] = $raceById[$row[3]]; }

        $handle = fopen((string) $opts['dump-rules'], 'w');
        foreach ($targetCounts as $id => $byRule) {
            foreach ($byRule as $rule => $total) {
                fwrite($handle, "$id,{$nameById[$id]},{$raceOfDemon[$id]},$rule,$total\n");
            }
        }
        fclose($handle);
        $show('conferência de regras gravada em ' . $opts['dump-rules']);
    }

    // 4) banco ----------------------------------------------------------------
    out();
    out('[4/6] Preparando o banco ' . $opts['db'] . ' em ' . $opts['host'] . ':' . $opts['port']);

    $connect = function (bool $withDb) use ($opts): PDO {
        $dsn = 'mysql:host=' . $opts['host'] . ';port=' . $opts['port']
             . ($withDb ? ';dbname=' . $opts['db'] : '')
             . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, (string) $opts['user'], (string) $opts['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            abort('não foi possível conectar ao MySQL: ' . $e->getMessage());
        }

        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    };

    // o nome do banco vem da linha de comando e entra no DDL: só aceita identificador simples
    if (!preg_match('/^[A-Za-z0-9_$]+$/', (string) $opts['db'])) {
        abort('nome de banco inválido: ' . $opts['db']);
    }

    if (!$opts['skip-schema']) {
        $schemaPath = (string) $opts['schema'];
        if (!is_file($schemaPath)) { abort("schema não encontrado: $schemaPath"); }

        // schema.sql cria e seleciona `smt_db`; com --db=outro uso o nome pedido
        $schemaSql = (string) file_get_contents($schemaPath);
        if ((string) $opts['db'] !== 'smt_db') {
            $schemaSql = str_replace('`smt_db`', '`' . $opts['db'] . '`', $schemaSql);
        }

        $statements = splitSqlStatements($schemaSql);
        $pdo        = $connect(false);

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $show('schema.sql executado (' . count($statements) . ' comandos)');
    } else {
        $show('schema.sql ignorado (--skip-schema)');
    }

    $pdo = $connect(true);

    // 5) gravação -------------------------------------------------------------
    out();
    out('[5/6] Gravando dados');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $catalogTables = [
        'fusions', 'special_fusions', 'element_fusions', 'element_chart', 'fusion_species_chart', 'fusion_chart',
        'dark_race_recipes',
        'demon_skills', 'skills', 'demon_resists', 'demons', 'elements', 'races', 'species',
    ];

    foreach ($catalogTables as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
    }

    $pdo->beginTransaction();

    try {
        $counts = [];
        $counts['species']              = insertRows($pdo, 'species', ['id', 'name', 'is_triple_chart', 'sort_order'], $catalog['species']);
        $counts['races']                = insertRows($pdo, 'races', ['id', 'name', 'species_id', 'align_code', 'alignment', 'light_dark', 'in_normal_chart', 'sort_order'], $catalog['races']);
        $counts['elements']             = insertRows($pdo, 'elements', ['id', 'name', 'is_resist_elem', 'is_skill_elem', 'sort_order'], $catalog['elements']);
        $counts['demons']               = insertRows($pdo, 'demons', [
            'id', 'slug', 'name', 'race_id', 'level', 'align_code', 'alignment', 'drop_item',
            'hp', 'mp', 'cp', 'strength', 'intelligence', 'magic', 'vitality', 'agility', 'luck',
            'natk', 'nmin', 'nmax', 'patk', 'phit', 'defense', 'evasion', 'matk', 'mhit',
        ], $catalog['demons']);
        $counts['skills']               = insertRows($pdo, 'skills', ['id', 'slug', 'name', 'element_id', 'power', 'cost_mp', 'is_extra', 'target', 'effect', 'is_enemy'], $catalog['skills']);
        $counts['demon_resists']        = insertRows($pdo, 'demon_resists', ['demon_id', 'element_id', 'effect', 'damage_pct'], $catalog['resists']);
        $counts['demon_skills']         = insertRows($pdo, 'demon_skills', ['demon_id', 'skill_id', 'slot'], $catalog['demonSkills']);
        $counts['dark_race_recipes']    = insertRows($pdo, 'dark_race_recipes', ['dark_race_id', 'result_race_id'], $catalog['darkRecipes']);
        $counts['fusion_chart']         = insertRows($pdo, 'fusion_chart', ['race_a_id', 'race_b_id', 'result_type', 'result_race_id', 'result_species_id', 'result_demon_id'], $catalog['fusionChart']);
        $counts['fusion_species_chart'] = insertRows($pdo, 'fusion_species_chart', ['species_a_id', 'species_b_id', 'species_c_id', 'result_race_id'], $catalog['speciesChart']);
        $counts['element_chart']        = insertRows($pdo, 'element_chart', ['element_demon_id', 'race_id', 'level_offset'], $catalog['elementChart']);
        $counts['element_fusions']      = insertRows($pdo, 'element_fusions', ['element_a_demon_id', 'element_b_demon_id', 'result_demon_id'], $catalog['elementFusions']);
        $counts['special_fusions']      = insertRows($pdo, 'special_fusions', ['target_demon_id', 'kind', 'recipe_text', 'ingredient_1_id', 'ingredient_2_id', 'ingredient_3_id'], $catalog['specialFusions']);
        $counts['fusions']              = insertRows($pdo, 'fusions', ['result_demon_id', 'ingredient_a_id', 'ingredient_b_id', 'rule'], $fusions);

        $log = $pdo->prepare(
            'INSERT INTO import_log
               (source_url, source_ref, checksum_sha256, demons_count, skills_count, races_count, fusions_count, special_fusions_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $log->execute([
            RAW_BASE,
            SOURCE_REF,
            (string) $json['__checksum'],
            $counts['demons'],
            $counts['skills'],
            $counts['races'],
            $counts['fusions'],
            $counts['special_fusions'],
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        abort('falha ao gravar os dados (rollback feito): ' . $e->getMessage());
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    foreach ($counts as $table => $total) {
        $show('gravado: ' . str_pad($table, 22) . $total . ' linhas');
    }

    // 6) conferência ----------------------------------------------------------
    out();
    out('[6/6] Conferencia rapida no banco');

    $checks = [
        'demônios'           => 'SELECT COUNT(*) FROM demons',
        'skills'             => 'SELECT COUNT(*) FROM skills',
        'resistências'       => 'SELECT COUNT(*) FROM demon_resists',
        'skills inatas'      => 'SELECT COUNT(*) FROM demon_skills',
        'fusões'             => 'SELECT COUNT(*) FROM fusions',
        'chart raça x raça'  => 'SELECT COUNT(*) FROM fusion_chart',
        'chart triplo'       => 'SELECT COUNT(*) FROM fusion_species_chart',
        'elementais'         => 'SELECT COUNT(*) FROM element_chart',
        'pares de elementais' => 'SELECT COUNT(*) FROM element_fusions',
        'receitas especiais' => 'SELECT COUNT(*) FROM special_fusions',
        'conversões dark'    => 'SELECT COUNT(*) FROM dark_race_recipes',
    ];

    foreach ($checks as $label => $sql) {
        $show(str_pad($label, 22) . ' ' . $pdo->query($sql)->fetchColumn());
    }

    $dupes = $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT demon_id, element_id FROM demon_resists GROUP BY demon_id, element_id HAVING COUNT(*) > 1
         ) t'
    )->fetchColumn();

    $orphans = $pdo->query(
        'SELECT COUNT(*) FROM fusions f
          LEFT JOIN demons a ON a.id = f.ingredient_a_id
          LEFT JOIN demons b ON b.id = f.ingredient_b_id
          WHERE a.id IS NULL OR b.id IS NULL'
    )->fetchColumn();

    foreach ($pdo->query('SELECT rule, COUNT(*) AS total FROM fusions GROUP BY rule ORDER BY FIELD(rule, \'normal\', \'species\', \'dark\', \'element\')') as $row) {
        $show(str_pad('   regra ' . $row['rule'], 22) . ' ' . $row['total']);
    }

    $show(str_pad('resistências duplicadas', 22) . ' ' . $dupes);
    $show(str_pad('fusões órfãs', 22) . ' ' . $orphans);

    out();
    out('Importacao concluida com sucesso.');
    out('Banco: ' . $opts['db'] . ' | fusões: ' . $counts['fusions'] . ' | demônios: ' . $counts['demons']);
    out();
    out('Dicas:');
    out('  * consultas de exemplo e conferências estão em verify.sql');
    out('  * o dump pronto para phpMyAdmin é smt_db.sql');
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    main($argv);
}
