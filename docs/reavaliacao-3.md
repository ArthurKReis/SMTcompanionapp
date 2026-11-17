# Re-avaliação nº 3 — antes da Task 3 (fusões)

> Escopo: **Shin Megami Tensei 1 apenas**. Auditoria do que existe contra os três
> requisitos originais do projeto.

---

## 1. Onde estamos, requisito por requisito

| # | Requisito original | Situação |
| --- | --- | --- |
| 1 | Listar os demônios e suas características | pronto (lista + ficha completa) |
| 2 | Criar, salvar, editar e excluir party (jogador + 5 demônios) | pronto (CRUD completo, 21 testes de UI) |
| 3 | **Listar as fusões possíveis com base na raça de cada demônio** | pendente — nem endpoint, nem tela |

Os dados do item 3 estão no banco desde a Task 1 (13.985 fusões validadas), mas
**não há nenhum endpoint nem tela de fusão**: o app hoje só consulta demônios e
parties. É a única lacuna funcional do projeto.

---

## 2. Cinco questões que precisam ser decididas antes de codar

### Q1 — O que a "listagem de fusões" deve mostrar?

O banco permite quatro recortes diferentes, e o site tem os quatro:

| Recorte | Dado | Tamanho | Para que serve |
| --- | --- | --- | --- |
| **A. Como obter** (reverse) | `v_fusions` por `result` | 212 demônios | "quero o Cerberus: quais pares o produzem?" — 47 pares |
| **B. O que sai de** (forward) | `v_fusions` por ingrediente | 244 demônios | "tenho o Loki: com o que combino?" — 170 pares |
| **C. Chart raça × raça** | `fusion_chart` | 239 células | a tabela clássica: Beast × Holy → ? |
| **D. Receitas especiais** | `special_fusions` | 49 receitas | pares/trios fixos, pré-requisitos, recrutas |

O requisito diz literalmente "**com base na raça** de cada demônio", o que aponta
para **C**. Mas o uso real de quem joga é **A/B**. Minha recomendação é fazer
**A + B + C + D**, com abas — os quatro já estão prontos no banco.

### Q2 — 32 demônios não têm nenhuma fusão. Como tratar?

Isso **não é bug**: o próprio site responde "No fusions found!" para o Loki
(conferido na página dele). São eles:

- **22 "normais"**: toda a linha morta-viva (Zombie, Zombie Lady, Obattarion,
  Zombie Cop, Bodyconian, Army Zombie, Ghost, Mou-Ryo, Preta, Oshichi, Gremlin,
  Slime) + chefes (Loki, Mishaguji, Tezcatlipoca, Orcus, Taotie, Dagon, Bael,
  Mara, Beelzebub) + Corpses.
- **10 recrutáveis** (Gaean/Messian: Suicide Unit, Fanatic, Death Rider, Holy
  Knight, Fallen Monk, Magus, Oni Jorou, Dark Priest, Scanner, Assassin) — cada um
  tem **1 receita especial** de recrutamento.

Sem tratamento, a tela parece quebrada ("nenhum resultado"). Precisa de uma
mensagem explícita dizendo **como** o demônio é obtido.

### Q3 — `demons.fusion_kind` está enganoso

A coluna tem 234 `normal` + 10 `recruit`, mas **22 dos "normal" não têm fusão
nenhuma**. Ou seja, o campo não responde "dá para obter por fusão?" — ele marca
outra coisa (o tipo de entrada no site). Opções:

- (a) documentar o significado real e criar na view uma flag derivada
  `obtivel_por_fusao` (calculada com `EXISTS` em `fusions`);
- (b) renomear a coluna;
- (c) deixar como está e calcular no PHP.

Recomendo **(a)**: é dado derivado, não muda a extração.

### Q4 — Fusões triplas entram?

O site tem "Triple Forward Fusions" / "Triple Reverse Fissions", e o banco já
guarda o chart triplo (224 células, `fusion_species_chart`), mas **não** as fusões
triplas concretas (A+B+C → resultado). Fazer as triplas de verdade seria:
9 grupos × combinações com níveis — o mesmo cálculo, com outra janela de nível.
É escopo novo, não dado que já existe.

**Recomendação:** deixar triplas para depois de a listagem dupla estar fechada, e
por ora só exibir as **9 receitas de trio fixas** (`special_fusions.kind='trio'`).

### Q5 — Mostrar o preço da fusão?

O site original exibe uma coluna de preço, que não vem dos JSONs. **Decidido: o
preço não entra no projeto** — nem no banco, nem na API, nem na interface. Não é
um dado do jogo que a aplicação precise mostrar, e calcular algo que não será
usado só criaria superfície para divergir do site.

---

## 3. Furo técnico encontrado (e a correção)

Buscar fusões **pelo nome do ingrediente** gera plano ruim:

```sql
-- RUIM: o otimizador começa por `races` e varre demônios
SELECT * FROM v_fusions WHERE ingredient_a = 'Cerberus' OR ingredient_b = 'Cerberus';
```

Medido: o plano parte de `races` (37 linhas) e faz `ref` em `demons` por raça,
**ignorando** `idx_fusions_a`/`idx_fusions_b`.

```sql
-- BOM: filtrando por id, o MariaDB usa index_merge
SELECT * FROM v_fusions WHERE ingredient_a_id = 35 OR ingredient_b_id = 35;
-- index_merge  Using union(idx_fusions_a,idx_fusions_b)  rows=170
```

**Consequência para a API:** resolver `slug → id` antes de consultar e nunca
filtrar a view por nome. (As consultas por `result` já estão boas: plano
`const` + `ref`, 47 linhas.)

Tempos medidos no banco atual: as quatro consultas típicas somadas levam **32 ms**;
a listagem completa de 47 fusões com todos os joins leva **41 ms**. Não é preciso
desnormalizar nem criar tabela auxiliar.

---

## 4. Escopo proposto para a Task 3

**API (4 endpoints novos):**
- `GET ?rota=demon_fusions&slug=X` → como obter X
- `GET ?rota=demon_fusions_out&slug=X` → o que sai de X
- `GET ?rota=fusion_chart` → as 239 células (com filtro por raça)
- `GET ?rota=special_fusions` → as 49 receitas (filtro por `kind`)

**Front:**
- terceira aba **Fusões**, com o chart raça × raça e busca por demônio;
- na ficha do demônio, duas abas novas: **"Como obter"** e **"Combina com"**;
- aviso explícito nos 32 sem fusão, dizendo como obtê-los (recruta/receita).

**Validação:** comparar as listas geradas pela API com as páginas do site para 3–4
demônios já usados como referência (Cerberus 47, Indrajit 75, Salamander 6, Loki 0).
