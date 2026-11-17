# Modelo de party — **fechado**

> Escopo: **Shin Megami Tensei 1 (SNES), apenas**. Nenhum outro título da série
> entra neste modelo.

Decisões tomadas e já aplicadas no banco (`schema.sql`, `smt_db.sql`, `verify.sql`).

---

## 1. Decisões

| # | Questão | Decisão |
| --- | --- | --- |
| 1 | Estrutura da party | **Meio-termo (opção C)**: `party_demons` com **5 slots** (1 = frente, 5 = fundo), mais os campos de contexto do jogo — `comp_upgraded` e `human_partners` |
| 2 | `current_level` por demônio | **Removida.** O nível vem sempre de `demons.level`: no SMT1 demônios não ganham EXP |
| 3 | Dados do protagonista | **Nome + nível + alinhamento** (`Law` / `Neutral` / `Chaos`) |
| 4 | Custo de MAG por passo (Σ CP ÷ 10) | **Não entra.** Fica fora do escopo |

---

## 2. Modelo final

```sql
parties
  id                    SMALLINT UNSIGNED AUTO_INCREMENT
  name                  VARCHAR(40)  UNIQUE          -- nome dado pelo jogador
  protagonist_name      VARCHAR(40)  NULL
  protagonist_level     TINYINT UNSIGNED NOT NULL DEFAULT 1
  protagonist_alignment ENUM('Law','Neutral','Chaos') NULL
  comp_upgraded         TINYINT(1)   NOT NULL DEFAULT 0  -- 0 = 3 demônios, 1 = 4
  human_partners        VARCHAR(80)  NULL            -- texto livre: Law Hero, Chaos Hero, Heroína
  notes                 VARCHAR(255) NULL
  created_at, updated_at

party_demons
  id        SMALLINT UNSIGNED AUTO_INCREMENT
  party_id  SMALLINT UNSIGNED  -> parties (ON DELETE CASCADE)
  slot      TINYINT UNSIGNED   CHECK (slot BETWEEN 1 AND 5)
  demon_id  SMALLINT UNSIGNED  -> demons
  UNIQUE (party_id, slot)      -- um demônio por posição
  UNIQUE (party_id, demon_id)  -- o mesmo demônio não se repete na party
```

View `v_parties_completo`: `party_id, party, protagonist_name, protagonist_level,
protagonist_alignment, comp_upgraded, human_partners, slot, demon_id, slug, demon,
race, demon_level`.

---

## 3. Regras do SMT1 que o app respeita

| Regra | Como aparece |
| --- | --- |
| Demônios têm nível **fixo** (não ganham EXP) | Nenhuma coluna de nível em `party_demons`; a tela lê `demons.level` |
| Máximo de **3 demônios** convocados; **4** depois que Stephen atualiza o COMP | `comp_upgraded`; o formulário **avisa** ao passar do limite, sem bloquear |
| Não se comanda demônio de **nível acima do protagonista** | Validação que **avisa** (lista os excedentes), sem bloquear |
| A formação tem **ordem** (1 = frente … 6 = fundo) e a posição muda o dano recebido | `slot` de 1 a 5, com reordenação explícita |
| Parceiros humanos ocupam lugar na formação | `human_partners` (informativo, não consome slot) |

As duas consultas de validação estão prontas em `verify.sql` §5.6 — dá para
reaproveitar direto no PHP.

---

## 4. O que mudou em relação ao modelo anterior

| Antes | Agora |
| --- | --- |
| `party_demons.current_level` | removida |
| `parties.protagonist_level TINYINT` (sem comentário de faixa) | idem, documentado como 1–99 |
| — | `protagonist_alignment`, `comp_upgraded`, `human_partners` |
| `slot` 1–5 só como comentário | `CHECK (slot BETWEEN 1 AND 5)` |
| view com `demon_base_level` + `current_level` | view só com `demon_level` |

Para quem já tinha o banco antigo importado: `db/migrate_002_party.sql` aplica
tudo isso sem perder as parties existentes (usa `ADD/DROP COLUMN IF [NOT] EXISTS`
e só cria o `CHECK` se ele ainda não existir).

---

## 5. Comportamento do formulário (implementado na Task 2)

- **Listagem:** cards ou tabela com nome, protagonista (nível + alinhamento),
  contagem de demônios (`2/3` ou `4/4` conforme o COMP) e a formação em ordem.
- **Criar/editar:** dados do protagonista + até 5 seletores de demônio (com busca
  por nome/raça) que gravam o `slot` pela ordem escolhida.
- **Reordenar:** troca de dois slots via `DELETE` + `INSERT` (o `UNIQUE
  (party_id, slot)` impede `UPDATE` direto — ver `verify.sql` §5.3).
- **Avisos em tempo real:** nível do demônio > protagonista; demônios >
  limite do COMP. Nenhum dos dois impede salvar.
- **Excluir:** `DELETE FROM parties`, com `party_demons` caindo junto pelo
  `ON DELETE CASCADE`.
