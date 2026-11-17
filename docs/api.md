# API — referência

API PHP escrita do zero, sem framework, servindo o banco `smt_db`.
Todas as rotas passam por **um único arquivo**: `api/index.php`.

```
GET  api/index.php                          -> mapa de rotas
GET  api/index.php?rota=demons&q=cerb       -> listagem
```

- **Roteamento:** por querystring (`?rota=...`), decisão do projeto — funciona em
  Apache (XAMPP), `php -S`, IIS e hospedagem compartilhada, sem `.htaccess`.
- **Autenticação:** nenhuma (uso local).
- **Escrita:** apenas em `parties` e `party_demons`. O catálogo só muda pelo
  `db/import.php`.
- **Formato:** JSON sempre. Sucesso `{"data":…,"meta":…}`, erro
  `{"error":{"code","message","details"}}`.

---

## 1. Envelope e códigos

| Situação | HTTP | `error.code` |
| --- | --- | --- |
| Sucesso | 200 / 201 / 204 | — |
| Validação (campo inválido) | 422 | `validacao` |
| Recurso inexistente | 404 | `nao_encontrado` |
| Rota existe, método não | 405 | `metodo_nao_permitido` |
| Corpo não é JSON | 400 | `requisicao_invalida` |
| Violação de integridade no banco | 409 | `conflito` |
| Erro inesperado | 500 | `erro_interno` |

Erro de validação devolve **todos** os campos com problema de uma vez:

```json
{"error":{"code":"validacao","message":"Dados inválidos.",
 "details":{"name":"já existe uma party com esse nome"}}}
```

---

## 2. Catálogo (somente leitura)

### `GET ?rota=health`
Status + contagens + última importação.
```json
{"data":{"status":"ok","api":"v1",
  "catalogo":{"demons":244,"races":37,"species":13,"skills":105,"fusions":13985}},
 "meta":{"importacao":{"imported_at":"2026-10-27 00:54:52","source_ref":"master"}}}
```

### `GET ?rota=demons`
Listagem paginada.

| Parâmetro | Tipo | Padrão | Observação |
| --- | --- | --- | --- |
| `q` | texto | — | busca parcial por nome |
| `race` | texto | — | nome exato da raça |
| `species` | texto | — | grupo (BEASTS, NETHER…) |
| `alignment` | `Law`/`Neutral`/`Chaos` | — | |
| `level_min`, `level_max` | número | — | |
| `sort` | `level`/`name`/`race`/`hp`/`mp`/`alignment` | `level` | |
| `order` | `asc`/`desc` | `asc` | |
| `page` | inteiro | 1 | |
| `per_page` | inteiro | 25 | máx. 100 |

`meta` devolve `total`, `page`, `per_page`, `pages`.

### `GET ?rota=demons_lista`
Os 244 demônios enxutos (`id, slug, name, race, level`) — usado nos seletores.

### `GET ?rota=demon&slug=cerberus`
Ficha completa: todos os campos de `v_demons` + `skills[]` + `resistances[]`
(estas já ordenadas pelos 14 elementos na ordem do jogo).

### `GET ?rota=races` · `?rota=species` · `?rota=elements` · `?rota=skills`
Listas de apoio para filtros. `skills` aceita `q` e `element`.

---

## 3. Parties (leitura e escrita)

### `GET ?rota=parties`
Lista com formação e avisos embutidos (sem N+1):

```json
{"id":22,"party":"Cathedral Run","protagonist_name":"Kazuya",
 "protagonist_level":45,"protagonist_alignment":"Neutral","comp_upgraded":1,
 "human_partners":"Heroína","notes":"time principal",
 "demon_count":4,"comp_limit":4,
 "demons":[{"slot":1,"demon_id":35,"slug":"cerberus","demon":"Cerberus",
            "race":"Beast","demon_level":43.0}],
 "avisos":{"comp":{"demonios":4,"limite":4,"excedido":false},
           "acima_do_nivel":[],"tem_avisos":false}}
```

### `POST ?rota=parties`
Cria a party. Corpo:

```json
{"name":"Cathedral Run","protagonist_name":"Kazuya","protagonist_level":45,
 "protagonist_alignment":"Neutral","comp_upgraded":1,
 "human_partners":"Heroína","notes":"...",
 "demons":[{"slot":1,"demon_id":35},{"slot":2,"demon_id":18}]}
```
`demons` é opcional (party vazia é válida). Responde **201** com a party criada.

### `GET ?rota=party&id=22`
Party completa + `avisos` detalhados (`acima_do_nivel` traz slot, demônio,
nível e o excesso em relação ao protagonista).

### `PATCH ?rota=party&id=22`
Atualiza só os campos enviados (mesmo corpo do POST, sem `demons`).

### `DELETE ?rota=party&id=22`
Exclui a party — `party_demons` cai junto pelo `ON DELETE CASCADE`. **204**.

### `PUT ?rota=party_demons&id=22`
**Substitui a formação inteira**:
```json
{"demons":[{"slot":1,"demon_id":35},{"slot":4,"demon_id":18}]}
```
Tudo roda em uma transação: apaga as linhas da party e reinsere na ordem
recebida. É assim porque `UNIQUE (party_id, slot)` tornaria uma troca de
posições um quebra-cabeça de `UPDATE` temporários.

### `DELETE ?rota=party_demon&id=22&slot=3`
Remove um integrante. 404 se o slot estiver vazio.


---

## 4. Fusões

Três modos: **retroativo** (quero X, o que o gera?),
**normal** (tenho X, o que sai dele?) e a **tabela raça × raça** (o mapa cru do jogo).

O tipo de obtenção de cada demônio (`obtencao`, abaixo) **não** é uma coluna do banco:
a API o **deriva** dos fatos — se o demônio aparece em `fusions`, é `fusao`; se só
aparece numa receita fixa, `receita_especial`; se só numa receita de recrutamento,
`recrutamento`; caso contrário, `inimigo`. Derivar evita que o dado fique inconsistente
com as tabelas de fusão.

### `GET ?rota=demon_fusions&slug=cerberus` — retroativo
Lista os pares que **produzem** o demônio.

```json
{"data":[
  {"rule":"normal",
   "ingredient_a":{"slug":"bai-ze","name":"Bai Ze","race":"Holy","level":22.0},
   "ingredient_b":{"slug":"tam-lin","name":"Tam Lin","race":"Fairy","level":55.1}}],
 "meta":{"demon":{"slug":"cerberus","name":"Cerberus","race":"Beast","level":43.0},
         "total":47,
         "por_regra":{"normal":34,"species":5,"element":8,"dark":0},
         "receitas":[],
         "obtencao":"fusao"}}
```

`obtencao` responde "dá para obter este demônio?" — e, quando não dá, **por quê**:

| valor | significado |
| --- | --- |
| `fusao` | sai de alguma combinação |
| `receita_especial` | só por receita fixa (par/trio/pré-requisito) |
| `recrutamento` | entra na party por negociação em batalha |
| `inimigo` | não é obtenível: aparece só como chefe/inimigo |

### `GET ?rota=demon_fusions_out&slug=loki` — normal
Lista o que o demônio **produz** combinado com outros.

```json
{"data":[
  {"rule":"normal",
   "result":{"slug":"momunofu","name":"Momunofu","race":"Brute","level":24.0},
   "other":{"slug":"brownie","name":"Brownie","race":"Jirae","level":2.0}}],
 "meta":{"total":137,"por_regra":{"normal":132,"species":5,"element":0,"dark":0}, "...":"..."}}
```

`other` é sempre o **parceiro** — o próprio demônio nunca aparece como par.

### `GET ?rota=fusion_chart` — tabela raça × raça
O mapa do jogo: cada par de raças (ou a raça com ela mesma) gera outra raça, um
grupo (species) ou um elemental. A fonte é uma matriz **triangular** (linha *i* tem
*i+1* valores); a API devolve só as 239 células canônicas e o cliente espelha.

```json
{"data":{
   "races":[{"id":1,"name":"Deity","species":"DEITIES"},
            {"id":2,"name":"Megami","species":"DEITIES"}],
   "cells":[{"race_a":"Deity","race_b":"Megami","type":"species",
             "result":"DEITIES","demon_slug":null},
            {"race_a":"Femme","race_b":"Femme","type":"element_demon",
             "result":"Undine","demon_slug":"undine"}]},
 "meta":{"total":239,"races":22}}
```

| campo | observação |
| --- | --- |
| `races` | as 22 raças que participam, ordenadas por grupo (species) e nome |
| `cells[].type` | `race` (206) · `species` (18) · `element_demon` (15) |
| `cells[].result` | nome da raça, do grupo ou do elemental resultante |
| `cells[].demon_slug` | só para `element_demon`: permite abrir a ficha do elemental |

São 239 células: 224 pares de raças diferentes + 15 de raça com ela mesma. O cliente
renderiza 22 × 22 = 484 posições, das quais 463 preenchidas (as 224 duplas aparecem
espelhadas) e 21 vazias — combinações que o jogo não funde.

### `GET ?rota=special_fusions&kind=trio&slug=shiva`
As 49 receitas fixas (`pair` 29 · `trio` 9 · `prereq` 1 · `recruit` 10).
Filtros opcionais `kind` e `slug` (do demônio-alvo).

### Desempenho
As duas consultas filtram **por id**, nunca por nome: por nome o otimizador varre
`races`/`demons` e ignora `idx_fusions_a`/`idx_fusions_b`; por id ele resolve com
`index_merge`. Medido: 41 ms para a maior lista (47 linhas com todos os joins).

---

## 5. Regras do jogo que a API devolve como aviso

| Aviso | Campo | Bloqueia? |
| --- | --- | --- |
| Demônio acima do nível do protagonista | `avisos.acima_do_nivel[]` | **não** |
| Mais demônios do que o COMP permite (3, ou 4 com `comp_upgraded`) | `avisos.comp.excedido` | **não** |
| `tem_avisos` | resumo booleano | **não** |

O que **bloqueia** (422): nome vazio/duplicado, nível fora de 1–99,
alinhamento inválido, slot fora de 1–5, slot repetido, demônio repetido,
demônio inexistente, mais de 5 demônios.

---

## 6. Como subir

```bash
cp api/config.example.php api/config.php     # ajuste usuário/senha
mysql -u root -p < db/api_user.sql           # cria o usuário smt_api
php -S 0.0.0.0:8080 -t .                     # ou use o Apache do XAMPP
```

- **XAMPP:** copie a pasta para `htdocs/smt-app` e acesse
  `http://localhost/smt-app/public/`.
- **Servidor embutido:** `http://localhost:8080/` (a raiz redireciona para
  `public/`).

Testes: `python3 tests/api_test.py [url]` (87 verificações de contrato) e as
verificações de banco em `db/verify.sql`.
