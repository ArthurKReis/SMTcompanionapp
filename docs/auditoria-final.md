# Auditoria completa do projeto — SMT1 · Administração de Party

> Escopo: **Shin Megami Tensei 1 apenas**. Revisão feita antes de qualquer avanço,
> cobrindo código, banco, testes, documentação e segurança.

---

## 1. Requisitos originais × estado atual

| # | Requisito | Situação | Evidência |
| --- | --- | --- | --- |
| 1 | Listar demônios e suas características | Atendido | lista com filtros/paginação + ficha (atributos, combate, 14 resistências, skills) |
| 2 | Criar / salvar / editar / excluir party (jogador + 5 demônios) | Atendido | CRUD completo + avisos de COMP e de nível |
| 3 | Listar fusões | Atendido | modo retroativo + modo normal + tabela raça × raça + 9 receitas de trio |
| 4 | Dados do site → banco → API do zero | Atendido | `import.php` + `smt_db` + API PHP sem framework |
| 5 | Front Bootstrap + jQuery | Atendido | front consome só JSON da API |

**Nada do escopo está faltando.** As decisões de refinamento que estavam
abertas foram todas tomadas e aplicadas (§5).

---

## 2. Defeitos encontrados nesta auditoria

### 2.1 Segurança — injeção de atributo HTML (corrigido)

`esc()` usava `$('<div>').text(x).html()`, que escapa `&`, `<` e `>` mas **não
aspas**. Como o nome da party é usado dentro de `data-name="…"`, um nome como

```
Party " onmouseover="alert(1)
```

**quebrava o atributo e injetava um `onmouseover`** no botão. Verificado em
Chromium: o elemento passava a ter os atributos `["data-name","onmouseover"]` e o
`data-name` era truncado.

Corrigido: `esc()` agora escapa `& < > " '` (serve para conteúdo **e** atributo).
Teste de regressão incluído em `tests/ui_test.js`. Depois da correção o nome
aparece literal, sem atributo extra, sem `<img>` injetada e sem alerta.

### 2.2 Documentação — contrato da API divergente (corrigido)

`docs/arquitetura.md` §6 documentava um contrato REST
(`GET /demons/{slug}/fusions`, `PUT /parties/{id}/demons`) que **não existe**: a
API foi implementada com querystring (`?rota=…`). Quem lesse o doc tentaria
chamar rotas inexistentes. Seção 6 reescrita para a realidade e apontando
`docs/api.md` como fonte única; seção 7 (antes "decisões que faltam") virou
"decisões tomadas".

### 2.3 Migração — perda de dado silenciosa (documentada)

`migrate_002_party.sql` apaga `party_demons.current_level`. Em um banco antigo
com valores preenchidos, esses valores **são perdidos** (não há destino: o nível
passa a vir de `demons.level`). O cabeçalho agora avisa explicitamente e informa
a ordem correta de aplicação (002 → 003).

**Teste real executado:** banco montado no formato antigo (com `current_level` e
uma party com 2 demônios) → `migrate_002` → `migrate_003` → exit 0, party e os 2
demônios preservados, 6 views criadas. *(Uma primeira tentativa de teste foi
descartada por medir o status do `head` em vez do `mysql`; refeita com a medição
correta.)*

### 2.4 Testes de UI não persistiam (corrigido)

As verificações de interface existiam apenas em `/tmp` e desapareciam. Agora
estão em `tests/ui_test.js` (40 verificações: demônios, fusões, parties,
validação e a regressão de segurança), com instruções no README.

### 2.5 Credenciais expostas em arquivo versionável (mitigado)

`api/config.php` contém usuário/senha do banco. Criado `.gitignore` ignorando
esse arquivo (e mantendo `public/vendor/` versionado de propósito, para o app
rodar offline).

### 2.6 Bancos de teste deixados para trás (limpo)

`smt_old`, `smt_old2`, `smt_old3` removidos. O servidor tem apenas `smt_db`.

---

## 3. Verificações que passaram

| Verificação | Resultado |
| --- | --- |
| Sintaxe de todos os arquivos PHP | sem erro |
| Banco restaurado do dump do zero | 17 tabelas, 6 views, 244 demônios, 13.985 fusões, 0,29 s |
| Contrato da API | **76/76** |
| Interface (Chromium real) | **29/29** |
| `db/verify.sql` completo | roda sem erro e sem deixar resíduo |
| Migrations 002+003 em banco antigo | dados preservados |
| API com o banco fora do ar | HTTP 500 com envelope JSON (não HTML) |
| XSS no nome da party | bloqueado após a correção |
| Mobile (390 px) na aba de fusões | sem overflow horizontal |

### Tempos de resposta medidos (servidor local)

| Rota | Tempo |
| --- | --- |
| `health` | 4 ms |
| `demons&per_page=25` | 3 ms |
| `demon&slug=cerberus` | 2 ms |
| `demons_lista` (244 itens) | 2 ms |
| `demon_fusions&slug=cerberus` (47 pares) | 2 ms |
| `demon_fusions_out&slug=cerberus` (139 pares) | 4 ms |
| `special_fusions` (49 receitas) | 1 ms |

---

## 4. Riscos conhecidos (aceitos ou a decidir)

| Risco | Situação |
| --- | --- |
| Dump `smt_db.sql` faz `DROP DATABASE` | documentado: apaga as parties de quem importar por cima |
| ~~`demons.fusion_kind` enganoso~~ | **resolvido**: coluna removida; `obtencao` é derivada das tabelas de fusão/receita |
| Sem autenticação | decidido (uso local) |
| Erro 500 genérico quando `debug=false` | **resolvido**: `debug` vem ligado por padrão (§5.1) |
| Sem tela de skills (o site tem uma lista) | fora do escopo declarado |
| Preço em macca nunca é calculado nem exibido | decidido; nada no banco, API ou interface usa o preço |

---

## 5. Decisões — resolvidas

As seis decisões abaixo foram tomadas e aplicadas. Ficam registradas com a
justificativa e o que mudou no código, para uma revisão futura não reabrir o assunto.

| # | Pergunta | Decisão | O que foi feito |
| --- | --- | --- | --- |
| 1 | Mensagem de erro 500 | **debug ligado por padrão** | `api/config.php` traz `'debug' => true` (`SMT_DEBUG=false` desliga). O envelope passou a devolver `details{tipo, arquivo, linha}` com a causa real — testado derrubando a tabela `fusions`: a resposta foi `500 erro_interno` com o `SQLSTATE[42S02]` completo. |
| 2 | `demons.fusion_kind` | **remover** (era derivável e mentia) | Coluna, índice e o campo em `v_demons` eliminados; `DemonRepository` e `FusionRepository` deixaram de usá-los. O rótulo continua sendo calculado **em memória** no importador, porque a regra "só demônio normal pode ser resultado" depende dele — mas não é gravado. `db/migrate_004_drop_fusion_kind.sql` atualiza bases antigas. |
| 3 | Chart raça × raça | **entra como terceira visão de Fusões** | Nova rota `fusion_chart` (239 células) e a tabela 22 × 22 na interface: cabeçalhos fixos, cores por tipo, realce de linha/coluna no hover e clique no elemental abre a ficha. |
| 4 | Preço em macca | **nunca usar** | Auditado: nenhuma tabela, rota, tela ou teste usa preço. A fórmula existe só como referência histórica na documentação. |
| 5 | Exclusão de party | **modal Bootstrap** | `confirm()` do navegador substituído por `#modal-confirm`, que mostra o nome da party e exige o clique em "Excluir". |
| 6 | Usuário do banco | **padrão do XAMPP** | `config.example.php` e `config.php` vêm com `root` e senha vazia; `db/api_user.sql` continua disponível para quem quiser o usuário restrito. |

### 5.1 Efeito do debug ligado

Com `debug = true` o erro continua com status 500 e código `erro_interno`, mas o
`message` passa a trazer a exceção crua e `details` aponta tipo, arquivo e linha.
É o comportamento desejado para uso local (XAMPP). Para expor a aplicação, basta
`SMT_DEBUG=false` no ambiente ou trocar a chave em `api/config.php` — aí o cliente
recebe apenas "Erro interno no servidor." e o detalhe fica no log.


---

## 6. Estado final depois das decisões

| Item | Número / resultado |
| --- | --- |
| Tabelas / views | 17 tabelas + 6 views (`schema.sql`), `demons` com 25 colunas |
| Demônios · raças · espécies · elementos · skills | 244 · 37 · 13 · 19 · 105 |
| Fusões calculadas | 13.985 (11.403 normal · 1.036 species · 1.546 element) |
| Células da tabela raça × raça | 239 (206 raça · 18 grupo · 15 elemental) |
| Receitas fixas | 49 (29 par · 9 trio · 1 pré-requisito · 10 recrutamento) |
| Rotas da API | 19 |
| Testes de API | 87 passando |
| Testes de interface | 40 passando (Chromium headless, 1440 px e 390 px) |
| Tempo de resposta | 1–4 ms por rota |
| Importação completa | ~6 s (`php import.php … --offline`) |

Conferências mantidas como referência de regressão: Cerberus → 47 pares
(34 normal / 5 species / 8 element); Loki → 137 resultados; Zombie → 0, com
`obtencao = inimigo`; Indrajit → 75 linhas; Salamander → exatamente 6 pares
Avatar × Avatar.
