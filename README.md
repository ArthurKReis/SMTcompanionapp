# SMT1 · Administração de Party

Aplicação web para **Shin Megami Tensei 1 (SNES)** — e somente ele — que permite
consultar os demônios do jogo, montar e salvar parties e consultar as fusões
possíveis a partir de cada demônio e da tabela raça × raça.

**Pilha:** PHP 8 (API escrita do zero, sem framework) · MySQL/MariaDB · Bootstrap 5
· jQuery 3 · dados extraídos do site
[aqiu384.github.io/megaten-fusion-tool/smt1](https://aqiu384.github.io/megaten-fusion-tool/smt1).

---

> **Para instalar e rodar:** siga o `COMO-RODAR.md`, que tem o passo a passo do
> XAMPP, a importação do banco e a solução dos problemas mais comuns.

## Estrutura

```
smt-app/
├── db/          Extração + banco (concluído)
│   ├── schema.sql · import.php · smt_db.sql · verify.sql · README.md
│   ├── migrate_002_party.sql · migrate_003_api_reads.sql
│   ├── migrate_004_drop_fusion_kind.sql · api_user.sql
│   └── data/    cache dos 8 JSONs de origem
├── src/         Núcleo da API (sem HTML)
│   ├── Database.php · Request.php · Response.php · Router.php
│   ├── Validator.php · ApiException.php
│   └── Repositories/  DemonRepository · PartyRepository · FusionRepository
├── api/
│   ├── index.php       único ponto de entrada (rotas por querystring)
│   ├── bootstrap.php   autoload + tratamento global de erros
│   └── config.php      credenciais (config.example.php é o modelo)
├── public/      Front (Bootstrap + jQuery)
│   ├── index.html · css/app.css
│   ├── js/app.js · js/fusions.js · js/parties.js
│   └── vendor/  jQuery e Bootstrap locais (funciona sem internet)
├── tests/
│   ├── api_test.py     87 verificações de contrato da API (Python, sem dependências)
│   ├── edge_test.py    23 verificações de borda/abuso (SQLi, limites, unicode…)
│   └── ui_test.js      40 verificações de interface (Playwright + Chromium)
└── docs/
    ├── arquitetura.md   re-avaliação do projeto + desenho da API
    ├── modelo-party.md  modelo de party fechado (decisões)
    ├── reavaliacao-3.md auditoria antes da Task 3 (achados e medições)
    ├── auditoria-final.md  auditoria completa + as 6 decisões resolvidas
    ├── api.md           referência dos 19 endpoints
    └── img/             capturas usadas na documentação
```

O `COMO-RODAR.md` na raiz traz a instalação passo a passo.

---

## Como rodar

### 1. Banco

```bash
mysql -u root < db/smt_db.sql                     # dump pronto (244 demônios, 13.985 fusões)
mysql -u root < db/api_user.sql                   # opcional: usuário smt_api (privilégio mínimo)
```

> `db/api_user.sql` é opcional: por padrão a API usa **`root` sem senha**, como no
> XAMPP. Quem preferir o usuário restrito troca em `api/config.php`.
>
> Para atualizar o catálogo depois: `php import.php` dentro de `db/`
> (`--user=root --pass= --host=127.0.0.1 --db=smt_db --offline`).

### 2. API + front

```bash
cp api/config.example.php api/config.php   # ajuste usuário/senha se precisar
```

> O `config.example.php` **já vem no padrão do XAMPP**: usuário `root` e senha vazia —
> em geral não é preciso editar nada. Para usar o usuário restrito, rode
> `db/api_user.sql` e ajuste `user`/`pass`.
>
> `debug` vem **ligado** por padrão: um erro 500 mostra a causa real
> (`SQLSTATE`, arquivo e linha). Para esconder, defina `SMT_DEBUG=false` no ambiente.
>
> O `api/config.php` é ignorado pelo `.gitignore` (contém credenciais).

**No XAMPP:** copie a pasta para `htdocs/smt-app` e abra
`http://localhost/smt-app/public/`.

**Sem Apache:**

```bash
php -S 0.0.0.0:8080 -t .
# front: http://localhost:8080/   (redireciona para public/)
# API:   http://localhost:8080/api/index.php?rota=health
```

---

## O que já está pronto

| Etapa | Entrega |
| --- | --- |
| **1. Extração** dos dados do site | `db/import.php` — 8 JSONs, idempotente, `--offline`/`--refresh` |
| **2. Banco** normalizado | 17 tabelas, 6 views, 13.985 fusões validadas contra o algoritmo original |
| **3. API** do zero | 19 rotas, envelope JSON, validação por campo, 87 testes |
| **4. Front** Bootstrap + jQuery | listagem/ficha de demônios + CRUD completo de party |
| **5. Listagem de fusões** | três modos: retroativo, normal e tabela raça × raça |

### O que o app faz hoje

- **Demônios:** tabela com busca, filtros (raça, grupo, alinhamento, faixa de
  nível), ordenação e paginação; clique abre a ficha com atributos, parâmetros de
  combate, as 14 resistências decodificadas e as skills inatas.
- **Parties:** criar, editar, excluir e listar. Protagonista com nome, nível e
  alinhamento; até 5 demônios em posições (1 = frente); flag de COMP atualizado.
- **Avisos do jogo** em tempo real, sem bloquear o salvamento: demônio acima do
  nível do protagonista e party acima do limite do COMP (3, ou 4 com o upgrade).
- **Fusões**, em três modos:
  - **"Como obter"** (retroativo — escolho o demônio e vejo os pares que o produzem);
  - **"O que sai dele"** (normal — escolho o demônio e vejo o que ele gera com outros);
  - **"Tabela raça × raça"** (o mapa do jogo: 22 raças, 239 células, cabeçalho fixo,
    realce de linha/coluna e clique no elemental abre a ficha).

  Nos dois primeiros, cada resultado mostra raça e nível dos dois lados e a regra
  usada (raça × raça, mesmo grupo ou elemental). Demônio que não sai de fusão recebe
  um aviso explicando como é obtido (receita especial, recrutamento ou
  chefe/inimigo). As 9 receitas de trio fixas aparecem junto.

---

## Capturas

- `docs/img/tabela-raca-raca.png` — a terceira visão de Fusões (22 × 22, com realce).
- `docs/img/modal-exclusao.png` — confirmação de exclusão em modal Bootstrap.

---

## Decisões que moldaram o projeto

- **Só SMT1.** Nenhum campo de "jogo" no banco; nenhuma regra de outra obra da série.
- **Dados do JSON oficial**, não de scraping de HTML.
- **Fusões pré-calculadas** no banco (13.985 linhas) em vez de calculadas em tempo
  de execução — a API só consulta.
- **Sem login** e **sem autenticação de API**: uso local, uma pessoa.
- **Rotas por querystring**: roda em Apache, `php -S` e hospedagem compartilhada.
- **Demônios não têm nível "atual"**: no SMT1 eles não ganham EXP, então o nível é
  sempre o da ficha (`demons.level`). Ver `docs/modelo-party.md`.
- **Preço em macca não existe no app**: nenhuma tela, rota, tabela ou teste usa preço.
- **Erros mostram a causa real** por padrão (`debug` ligado em `api/config.php`);
  `SMT_DEBUG=false` volta à mensagem genérica.
- **Exclusão de party** pede confirmação em modal Bootstrap com o nome da party.

---

## Testes

```bash
# contrato da API (não precisa de nada além do Python)
python3 tests/api_test.py

# borda e abuso: injeção, limites, unicode, slots inválidos
python3 tests/edge_test.py

# integridade do banco + exemplos de consulta
mysql -u root -p smt_db < db/verify.sql

# interface (opcional: precisa de Node + Playwright)
npm init -y && npm i playwright && npx playwright install chromium
node tests/ui_test.js
```

Todos rodam sem deixar resíduo no banco. O `ui_test.js` inclui um teste de
**regressão de segurança**: um nome de party contendo `"` não pode injetar
atributos HTML na listagem.
