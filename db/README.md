# Banco de dados — Shin Megami Tensei (SMT1)

Base de dados MySQL/MariaDB com **todos os dados do jogo** usados pelo site
[aqiu384.github.io/megaten-fusion-tool/smt1](https://aqiu384.github.io/megaten-fusion-tool/smt1):
demônios, skills, resistências, o chart de fusão e **todas as fusões possíveis já
pré-calculadas** (13.985 linhas).

Esta é a entrega da **Task 1** do projeto (o banco). As tabelas de *party* já
sustentaram a Task 2 (CRUD de party) e a Task 3 (listagem de fusões) — ambas
já implementadas na API e no front.

---

## 1. Arquivos

| Arquivo | Para que serve |
| --- | --- |
| `schema.sql` | Cria o banco `smt_db`: 17 tabelas + 6 views, com comentários em PT-BR. Pode ser executado quantas vezes quiser (recria só as tabelas de catálogo). |
| `import.php` | Importador de linha de comando: baixa (ou usa do cache) os JSONs de origem, executa o `schema.sql`, decodifica os dados, calcula as fusões e grava tudo em transação. |
| `smt_db.sql` | Dump **já populado** (mysqldump) — o caminho mais rápido para ter o banco funcionando. |
| `verify.sql` | Consultas de conferência e exemplos de uso (contagens, integridade, fichas, fusões, party, regras). |
| `data/` | Cache dos 8 JSONs de origem. É de onde o importador lê primeiro (permite rodar sem internet). |
| `migrate_002_party.sql` | Adequa um banco antigo ao modelo de party fechado (ver `../docs/modelo-party.md`). |
| `migrate_003_api_reads.sql` | Índices e views exigidos pela API (ver `../docs/arquitetura.md`). |
| `api_user.sql` | Cria o usuário `smt_api`, com privilégio mínimo, para a API usar. |

Requisitos: **PHP 8.0+** com a extensão `pdo_mysql` e **MySQL 5.7+/MariaDB 10.4+**
(o MariaDB que vem no XAMPP serve). Testado com MariaDB 11.8 e PHP 8.4.
Codificação `utf8mb4` / `utf8mb4_unicode_ci`, engine InnoDB — sem nenhuma sintaxe
exclusiva do MySQL 8.

---

## 2. Como colocar no ar

### Opção A — importar o dump (mais rápido)

Pelo phpMyAdmin: **Importar** → escolher `smt_db.sql` → **Executar**.

Ou pela linha de comando:

```bash
mysql -u root -p < smt_db.sql
```

> O dump recria o banco `smt_db` (`DROP DATABASE` + `CREATE DATABASE`), então
> ele substitui qualquer versão anterior — inclusive as tabelas de party.

As views do dump **não fixam `DEFINER`**, então são criadas com o usuário que
importou (root no XAMPP). Se elas viessem com `DEFINER='smt'@'%'`, qualquer
`SELECT` falharia com `ERROR 1449` em instalações sem esse usuário.

### Opção B — rodar o importador (recomendado para atualizar os dados)

Com o PHP no PATH, dentro desta pasta:

```bash
php import.php --user=root --pass=suasenha
```

Se o banco ainda não existir, o importador cria. No fim ele imprime o resumo, a
conferência [6/6] e grava uma linha em `import_log` (origem, data e checksum).

### Opção C — conferir se está tudo certo

```bash
mysql -u root -p smt_db < verify.sql
```

---

## 3. O importador (`import.php`)

```
php import.php [opções]

  --host=127.0.0.1   servidor MySQL             (env SMT_DB_HOST)
  --port=3306        porta                      (env SMT_DB_PORT)
  --user=root        usuário                    (env SMT_DB_USER)
  --pass=            senha                      (env SMT_DB_PASS)
  --db=smt_db        nome do banco              (env SMT_DB_NAME)
  --data-dir=./data  pasta dos JSONs            (env SMT_DATA_DIR)
  --schema=./schema.sql  arquivo de schema
  --refresh          baixa os JSONs de novo (ignora o cache)
  --offline          não acessa a internet (usa só o cache)
  --skip-schema      não executa o schema.sql
  --quiet            imprime só o resumo final
  --dump-rules=arq.csv   grava a contagem de fusões por demônio/regra (debug)
```

O que ele faz, em ordem:

1. **Dados de origem** — valida/baixa os 8 JSONs, calcula um checksum SHA-256 do conjunto.
2. **Catálogo** — monta raças, grupos (species), elementos, skills, demônios, resistências e chart.
3. **Fusões** — recalcula as fusões concretas com as mesmas regras do site (ver seção 5).
4. **Banco** — executa o `schema.sql` (as tabelas de party **não** são apagadas).
   O `schema.sql` cria e seleciona o banco `smt_db`; se você passar `--db=outro`,
   o importador troca o nome no script antes de executar — é o que permite testar
   a importação numa base paralela sem tocar na principal.
5. **Gravação** — `TRUNCATE` + `INSERT` em lote dentro de uma transação (com rollback em caso de erro).
6. **Conferência** — contagens, duplicatas e órfãos; registra em `import_log`.

Conferido na última bateria de testes: uma base criada do zero pelo importador
sai com conteúdo **idêntico** ao do dump `smt_db.sql` (mesmo MD5 nas 13.985
fusões), e rodar o importador de novo mantém as contagens, não cria duplicatas e
**preserva** as parties existentes.

Rodar de novo é seguro e idempotente: o resultado é sempre o mesmo enquanto os
JSONs de origem não mudarem (o checksum permite detectar mudanças).

---

## 4. Origem dos dados

Os JSONs vêm do repositório do próprio site (branch `master`, commit `0b207612`):

```
https://raw.githubusercontent.com/aqiu384/megaten-fusion-tool/master/src/app/smt1/data/
    alignments.json  comp-config.json  demon-data.json  element-chart.json
    fusion-chart.json  skill-data.json  special-recipes.json  triple-chart.json
```

Checksum SHA-256 do conjunto (gravado em `import_log`):

```
2c9937e15a97bc8407814c8a990ced0438a3a5b98a7eb4231a5169a9357f2e15
```

Nomes de demônios e skills ficam **em inglês** (como no jogo e no site); apenas
comentários, tabelas e documentação estão em português.

---

## 5. Tabelas

| Tabela | Linhas | Conteúdo |
| --- | ---: | --- |
| `species` | 13 | Grupos de raças (DEITIES, ICONS, … MACHINES) |
| `races` | 37 | Raças (Deity, Beast, Fairy, …), com alinhamento e light/dark |
| `elements` | 19 | 14 elementos de resistência + 5 só de skill |
| `demons` | 244 | Ficha completa: nível, raça, stats, ataques, drop, tipo de fusão |
| `demon_resists` | 3.416 | 1 linha por demônio × elemento (efeito + % de dano) |
| `skills` | 105 | Elemento, poder, custo em MP, alvo, efeito |
| `demon_skills` | 689 | Skills inatas (com a ordem em que aparecem) |
| `fusion_chart` | 239 | O chart raça × raça (raça, grupo ou elemental como resultado) |
| `fusion_species_chart` | 224 | Chart de fusões triplas (28 pares de grupos × 8 terceiros) |
| `dark_race_recipes` | 10 | Conversões das raças "dark" (dado preservado — ver 5.3) |
| `element_chart` | 152 | Deslocamento de nível por elemental × raça |
| `element_fusions` | 28 | Fusões diretas entre dois elementais |
| `special_fusions` | 49 | Receitas fixas (pares, trios, pré-requisitos, recrutamento, inimigo) |
| `fusions` | **13.985** | **Todas as fusões possíveis, pré-calculadas** |
| `parties` | — | Parties (nome, protagonista: nome + nível + alinhamento, `comp_upgraded`) |
| `party_demons` | — | Até 5 demônios por party, slots 1..5 (o nível vem de `demons.level`) |
| `import_log` | — | Histórico de importações |

Views prontas para consulta: `v_demons`, `v_demon_skills`, `v_resistances`,
`v_fusions` (fusões com nomes **e ids** legíveis), `v_parties_completo` e
`v_parties_resumo` (party + contagem de demônios; inclui party ainda vazia).

Exemplos rápidos:

```sql
-- como obter Cerberus
SELECT rule, ingredient_a, ingredient_b FROM v_fusions WHERE result = 'Cerberus';

-- o que sai de Unicorn + Bai Ze
SELECT result, rule FROM v_fusions
WHERE (ingredient_a = 'Bai Ze' AND ingredient_b = 'Unicorn')
   OR (ingredient_a = 'Unicorn' AND ingredient_b = 'Bai Ze');

-- demônios do grupo BEASTS com seus stats
SELECT name, level, hp, mp, strength, magic FROM v_demons WHERE species = 'BEASTS' ORDER BY level;
```

### 5.1 Níveis com casa decimal (atenção!)

Seis demônios têm nível terminando em `.1` (`Lakshmi 64.1`, `Tam Lin 55.1`,
`Orthrus 38.1`, `Gandharva 15.1`, `Fenrir 62.1`, `Bodyconian 7.1`). Isso **não é
erro**: o site usa esses níveis como critério de desempate quando dois demônios da
mesma raça têm o mesmo nível. O valor é usado no cálculo das fusões e exibido como
está. A coluna é `DECIMAL(4,1)`; se a interface quiser mostrar só o inteiro, use
`FLOOR(level)`.

### 5.2 Resistências decodificadas

Cada demônio tem 14 caracteres de resistência, um por elemento, nesta ordem:
`sword, gun, fire, ice, elec, force, nerve, expel, death, curse, bind, rush,
punch, flying`. Cada caractere foi traduzido para duas colunas legíveis —
`effect` e `damage_pct` (percentual de dano recebido):

| código | `effect` | `damage_pct` |
| --- | --- | --- |
| `d` | drain | 100 % |
| `r` | repel | 100 % |
| `R` | repel | 50 % |
| `n` | null | 100 % |
| `1` `2` `3` `s` `5` `6` `7` | resist | 12,5 / 25 / 37,5 / 50 / 62,5 / 75 / 87,5 % |
| `-` | neutral | 100 % |
| `w` / `W` / `x` / `X` | weak | 150 / 200 / 250 / 300 % |

Exemplo (Cerberus, elemento *fire*): no JSON o código é `r` → no banco fica
`repel` / `100.0`.

### 5.3 Por que não existem linhas `rule = 'dark'`?

O SMT1 tem um mapa de conversão das raças "dark" (Vile + Vile = Tenma, e assim
por diante), e ele está no arquivo `fusion-chart.json`. Porém a **versão de
referência do site não aplica essas regras na listagem de fusões do SMT1** — o
recurso `darknessRecipes` só é ativado em outros títulos da série (SMT if…, SMT
II), que estão fora do escopo deste app. Para o banco refletir exatamente o SMT1, a
regra `dark` fica sem linhas — e o dado original foi preservado na tabela
`dark_race_recipes`, caso você queira usar depois.

---

## 6. Como as fusões foram calculadas

As 13.985 linhas de `fusions` são o resultado do **mesmo algoritmo do site**
(aba *Normal Reverse Fissions* de cada demônio), não de uma aproximação. As regras:

| Regra | Total | Como funciona |
| --- | ---: | --- |
| `normal` | 11.403 | Chart raça × raça. O resultado é o demônio da raça-alvo cujo nível cai na janela `[2 × (nível anterior − 1,5), 2 × (nível-alvo − 1,5)]`; pares da mesma raça exigem `nívelA < nívelB`. |
| `species` | 1.036 | Para raças que pertencem a um grupo (ex.: `Beast`/`Holy` → `BEASTS`), a janela usa a lista de níveis do grupo inteiro; ingredientes com o nível do próprio resultado são descartados; usa o modificador +1,5. |
| `element` | 1.546 | Elemental + demônio da mesma raça, deslocando o índice de nível em ±1/±2 (dado do `element_chart`). Inclui também os pares fixos que **produzem** um elemental (ex.: Nandi + Narasimha = Salamander). |
| `dark` | 0 | Não usado pelo SMT1 atual (ver 5.3). |

Detalhes que valem registrar:

* Só demônios de origem `normal` podem ser **resultado** de fusão (49 ficam de fora:
  recrutáveis, inimigos e receitas fixas). Esse rótulo **não** é gravado no banco — ele
  é calculado no importador só para montar as combinações e depois descartado; o tipo de
  obtenção consultável (`fusao`, `receita_especial`, `recrutamento`, `inimigo`) é
  derivado pela API a partir das próprias tabelas de fusão e receita.
* As receitas fixas (pares/trios) ficam em `special_fusions`, separadas das fusões
  calculadas — no site elas aparecem na mesma tabela.
* O par de ingredientes é sempre normalizado (`ingredient_a_id < ingredient_b_id`)
  e há `UNIQUE(resultado, ingrediente A, ingrediente B, regra)`, o que permite
  re-importar sem duplicar.

### Migração 004 (remoção de `fusion_kind`)

`db/migrate_004_drop_fusion_kind.sql` remove a coluna `demons.fusion_kind` (e seu
índice) de uma base criada antes da revisão, recria `v_demons` sem o campo e é
idempotente. Ordem de atualização de uma base antiga: `002` → `003` → `004`.
Uma instalação nova já sai sem a coluna (`schema.sql` atual) — não precisa da 004.

### Validação feita

* As listas foram conferidas linha a linha contra as páginas do site:
  **Cerberus 47/47**, **Indrajit 75/75**, **Salamander 6/6**.
* O cálculo em PHP foi comparado com o código TypeScript original do site
  (executado no Node como referência): resultado **idêntico para os 244 demônios**
  e para as 13.985 linhas — nenhuma linha a mais, nenhuma a menos.

---

## 7. Próximos passos (Tasks 2 e 3)

* **Task 2 — Party:** usar `parties` + `party_demons` (5 slots) e a view
  `v_parties_completo` para listar. Os exemplos de INSERT/UPDATE/DELETE estão em
  `verify.sql` (seção 5), incluindo as duas validações do jogo: demônio acima do
  nível do protagonista e party acima do limite do COMP. O modelo está fechado em
  `../docs/modelo-party.md` (SMT1: 3 demônios, ou 4 com o COMP atualizado; o
  nível do demônio nunca é gravado porque demônios não ganham EXP).
* **Task 3 — Fusões:** listar com `v_fusions` (por resultado ou por ingrediente),
  `fusion_chart` (a tabela clássica raça × raça) e `special_fusions`.

> O projeto completo é: **extração → banco → API → front**. As etapas 1 e 2 estão
> aqui; o desenho da API (rotas, envelope JSON, estrutura de pastas) está em
> `../docs/arquitetura.md`.
