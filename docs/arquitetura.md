# Re-avaliação do projeto e arquitetura alvo (API)

> Escopo: **Shin Megami Tensei 1 apenas**. Documento gerado antes da Task 2,
> reavaliando o que já existe à luz da proposta completa do projeto.

---

## 1. A proposta, em quatro etapas

| # | Etapa | O que significa |
| --- | --- | --- |
| 1 | **Extração** | Tirar os dados do site do SMT1 (`aqiu384.github.io/megaten-fusion-tool/smt1`) |
| 2 | **Banco** | Guardar tudo em MySQL/MariaDB normalizado |
| 3 | **API** | Uma API **construída do zero** que serve esses dados |
| 4 | **Front** | Bootstrap + jQuery consumindo a API (demônios, party, fusões) |

---

## 2. Onde o projeto está

| Etapa | Status | Detalhe |
| --- | --- | --- |
| 1. Extração | **pronta** | `import.php` baixa (ou usa cache) os 8 JSONs oficiais do site, decodifica resistências/skills/níveis e é idempotente (`--refresh`, `--offline`, `--skip-schema`) |
| 2. Banco | **pronta** | `smt_db`: 17 tabelas + 6 views, utf8mb4, InnoDB, MariaDB-safe, 13.985 fusões validadas contra o algoritmo original do site |
| 3. API | **não existia** na data desta re-avaliação | Nenhum endpoint, roteador ou contrato JSON tinha sido escrito ainda |
| 4. Front | **não existia** na data desta re-avaliação | Não havia HTML/JS |

**Conclusão da re-avaliação:** a base (1 e 2) está aderente e não precisa ser
refeita. O que **não** condizia era o plano inicial para a Task 2:
páginas PHP renderizando HTML no servidor. Se os dados têm que ser buscados
**por uma API**, o front não pode depender de HTML pronto — ele precisa receber
JSON. Isso muda a estrutura do projeto, não o banco.

---

## 3. Problemas encontrados agora (e já corrigidos)

Ao olhar o banco *como quem vai servir JSON*, apareceram seis furos. Todos foram
corrigidos e o `smt_db.sql` foi regerado e testado.

| # | Problema | Consequência | Correção |
| --- | --- | --- | --- |
| 1 | `v_fusions` não expunha `result_demon_id` nem `ingredient_a_id`/`ingredient_b_id` | a API não conseguiria montar links para a ficha de um demônio | view agora traz **ids e slugs** dos três demônios |
| 2 | `v_parties_completo` usa `INNER JOIN` | **party recém-criada, sem demônios, desapareceria** da listagem | nova view **`v_parties_resumo`** (`LEFT JOIN` + `demon_count` + `comp_limit`) |
| 3 | `v_resistances` sem `element_id` | a API retornaria os 14 elementos em ordem alfabética, não na ordem do jogo | coluna `element_id` adicionada |
| 4 | `v_demons` sem `race_id`/`species_id` | filtros e links do front teriam que cruzar por nome | ids adicionados |
| 5 | `demons` sem índice em `level` | ordenação da listagem fazia varredura (irrelevante com 244 linhas, mas é o padrão da API) | `idx_demons_level` |
| 6 | **Dump com `DEFINER='smt'@'%'` nas views** | em qualquer instalação sem esse usuário, `SELECT` na view falha com **ERROR 1449** — ou seja, o `smt_db.sql` quebraria no XAMPP de quem importasse como root | `DEFINER` removido do dump; restaurado e testado **sem o usuário `smt` existir** |

O item 6 era o mais sério: era um bug de portabilidade silencioso em um
artefato já "entregue". Agora o dump funciona numa instalação limpa.

Além disso, `db/api_user.sql` cria o usuário **`smt_api`** com privilégio mínimo
(`SELECT` no catálogo, `INSERT/UPDATE/DELETE` só em `parties` e `party_demons`),
para a API não rodar com as credenciais amplas que o importador precisa.

---

## 4. Arquitetura alvo

```
   site SMT1 (JSON)                MySQL/MariaDB                 Front
 ┌────────────────┐             ┌───────────────┐          ┌──────────────────┐
 │ import.php     │  INSERT     │   smt_db      │  JSON    │ Bootstrap        │
 │ (Task 1, ok)   ├────────────>│ 17 tabelas    │<─────────┤ jQuery           │
 └────────────────┘             │ 6 views       │  PDO     │ (AJAX para /api) │
                                └───────────────┘          └──────────────────┘
                                        ^                          ^
                                        │                          │
                                  ┌─────┴──────────────────────────┴────┐
                                  │   API PHP escrita do zero           │
                                  │   roteador + validação + repositórios│
                                  └─────────────────────────────────────┘
```

Cada camada conversa só com a vizinha: o front **nunca** abre conexão MySQL, e a
API **nunca** devolve HTML.

---

## 5. Estrutura de pastas proposta

```
smt-app/
├── db/                      # Task 1 (pronta)
│   ├── schema.sql  import.php  smt_db.sql  verify.sql  README.md
│   ├── migrate_002_party.sql          # modelo de party
│   ├── migrate_003_api_reads.sql      # views/índices para a API
│   ├── api_user.sql                   # usuário restrito da API
│   └── data/                          # JSONs de origem (cache)
├── api/                     # Task 2/3 — a API, do zero
│   ├── index.php            # front controller (único ponto de entrada)
│   ├── config.php           # credenciais + CORS (fora do dump)
│   ├── .htaccess            # rewrite (Apache do XAMPP)
│   └── router.php           # equivalente para `php -S`
├── src/                     # núcleo reutilizável (sem HTML)
│   ├── Database.php         # PDO singleton, prepared statements
│   ├── Request.php          # query/body/params normalizados
│   ├── Response.php         # JSON + códigos HTTP
│   ├── Router.php           # rotas /api/v1/...
│   ├── Validator.php        # regras (slot 1..5, nível, limites)
│   └── Repositories/
│       ├── DemonRepository.php
│       ├── PartyRepository.php
│       └── FusionRepository.php
├── public/                  # front (Bootstrap + jQuery)
│   ├── index.html
│   ├── js/app.js  js/demons.js  js/parties.js  js/fusions.js
│   └── css/app.css
└── docs/
    ├── modelo-party.md
    └── arquitetura.md       (este arquivo)
```

---

## 6. Contrato da API (v1)

> **A referência vigente é `docs/api.md`.** Esta seção registra o desenho
> inicial; ele foi implementado com **rotas por querystring** (decisão tomada
> depois), e não com caminhos REST. A tabela abaixo já está atualizada para o
> que existe de fato.

Base: `api/index.php?rota=...`. Todas as respostas em JSON.

**Sucesso:** `{"data": ..., "meta": {...}}`
**Erro:** `{"error": {"code": "nao_encontrado", "message": "...", "details": {...}}}`

| Método | `rota=` | Para quê |
| --- | --- | --- |
| `GET` | `health` | status + contagens + última importação |
| `GET` | `demons` | listagem (`q, race, species, alignment, level_min, level_max, sort, order, page, per_page`) |
| `GET` | `demons_lista` | os 244 demônios enxutos (seletores) |
| `GET` | `demon&slug=` | ficha completa (stats, skills, resistências) |
| `GET` | `races` · `species` · `elements` · `skills` | listas de apoio |
| `GET` | `parties` | lista com formação e avisos |
| `POST` | `parties` | cria party (opcionalmente com `demons`) |
| `GET` | `party&id=` | party + avisos detalhados |
| `PATCH` | `party&id=` | atualiza protagonista/flags |
| `DELETE` | `party&id=` | exclui (cascade) |
| `PUT` | `party_demons&id=` | substitui a formação inteira |
| `DELETE` | `party_demon&id=&slot=` | remove um integrante |
| `GET` | **`demon_fusions&slug=`** | **modo retroativo** — o que gera este demônio |
| `GET` | **`demon_fusions_out&slug=`** | **modo normal** — o que sai deste demônio |
| `GET` | **`fusion_chart`** | **tabela raça × raça** — 22 raças, 239 células |
| `GET` | **`special_fusions&kind=&slug=`** | as 49 receitas fixas |

**Não implementado por decisão de escopo:** fusões triplas calculadas (valem só as
9 receitas de trio fixas de `special_fusions`). Todo o resto do catálogo tem rota:
19 no total.

**Por que `PUT /parties/{id}/demons` e não um POST por slot:** o formulário
inteiro já envia a formação completa; trocar dois integrantes de posição via
`PATCH` esbarraria no `UNIQUE (party_id, slot)`. A API resolve em uma transação:
apaga as linhas da party e reinsere na ordem recebida (`verify.sql` §5.3 mostra a
razão).

**Paginação:** `page` (1…) e `per_page` (padrão 25, máx. 100). `meta` devolve
`total, page, per_page, pages`. Necessário: `/fusions` tem 13.985 linhas.

---

## 7. Decisões tomadas (histórico)

1. **Autenticação** — **nenhuma** (uso local, uma pessoa).
2. **Origem** — **mesma origem**: front em `public/` e API em `api/`, ambos sob
   o mesmo host (XAMPP ou `php -S`). Sem CORS.
3. **Roteamento** — **querystring** (`index.php?rota=...`): funciona em Apache,
   `php -S` e hospedagem compartilhada, sem `.htaccess`.
4. **Escrita** — **só nas parties**. O catálogo muda apenas pelo `import.php`.
5. **Chart raça × raça** — virou a **terceira visão** da aba Fusões (rota
   `fusion_chart`), ao lado do modo retroativo e do modo normal. Antes ficava de
   fora; a decisão foi revista e ele entrou.
6. **`demons.fusion_kind`** — **removida**. Era derivável (e mentia: chamava de
   `normal` demônios que não saem de fusão nenhuma). O tipo de obtenção passou a ser
   derivado pela API a partir de `fusions` e `special_fusions`; no importador o
   rótulo só existe em memória, para a regra "só normal pode ser resultado".
7. **Preço em macca** — **nunca usado**: não há coluna, cálculo, rota nem tela com
   preço. A fórmula do site original fica só como referência documental.
