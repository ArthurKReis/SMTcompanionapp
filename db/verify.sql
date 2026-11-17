-- ============================================================================
--  verify.sql - Conferências e exemplos de consulta do banco SMT1
-- ----------------------------------------------------------------------------
--  Uso:  mysql -u root -p smt_db < verify.sql
--        (ou cole os blocos que quiser no phpMyAdmin / MySQL Workbench)
--
--  O arquivo é dividido em:
--     1) contagens (bate com o importador)
--     2) integridade / possíveis problemas
--     3) ficha de demônio (stats, resistências, skills)
--     4) fusões: "como obter X", "o que sai de A + B", chart raça x raça
--     5) exemplos de party (Task 2) - rodam em transação e são desfeitos
--     6) conferência das regras de fusão
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. CONTAGENS
-- ---------------------------------------------------------------------------
SELECT 'species' AS tabela, COUNT(*) AS linhas FROM species
UNION ALL SELECT 'races',              COUNT(*) FROM races
UNION ALL SELECT 'elements',           COUNT(*) FROM elements
UNION ALL SELECT 'demons',             COUNT(*) FROM demons
UNION ALL SELECT 'demon_resists',      COUNT(*) FROM demon_resists
UNION ALL SELECT 'skills',             COUNT(*) FROM skills
UNION ALL SELECT 'demon_skills',       COUNT(*) FROM demon_skills
UNION ALL SELECT 'fusion_chart',       COUNT(*) FROM fusion_chart
UNION ALL SELECT 'fusion_species_chart', COUNT(*) FROM fusion_species_chart
UNION ALL SELECT 'dark_race_recipes',  COUNT(*) FROM dark_race_recipes
UNION ALL SELECT 'element_chart',      COUNT(*) FROM element_chart
UNION ALL SELECT 'element_fusions',    COUNT(*) FROM element_fusions
UNION ALL SELECT 'special_fusions',    COUNT(*) FROM special_fusions
UNION ALL SELECT 'fusions',            COUNT(*) FROM fusions;

-- esperado (commit 0b207612): species 13, races 37, elements 19, demons 244,
-- demon_resists 3416, skills 105, demon_skills 689, fusion_chart 239,
-- fusion_species_chart 224, dark_race_recipes 10, element_chart 152,
-- element_fusions 28, special_fusions 49, fusions 13985

-- fusões por regra
SELECT rule, COUNT(*) AS total FROM fusions
GROUP BY rule ORDER BY FIELD(rule, 'normal', 'species', 'dark', 'element');
-- esperado: normal 11403 | species 1036 | element 1546
-- ("dark" fica vazio de propósito: o site atual do SMT1 não usa essas regras)

-- demônios por raça (maiores primeiro)
SELECT r.name AS raca, s.name AS grupo, COUNT(*) AS demonios
FROM demons d
JOIN races r   ON r.id = d.race_id
JOIN species s ON s.id = r.species_id
GROUP BY r.id ORDER BY demonios DESC, raca;

-- ---------------------------------------------------------------------------
-- 2. INTEGRIDADE
-- ---------------------------------------------------------------------------
-- resultados de fusão órfãos (deve dar 0)
SELECT COUNT(*) AS fusoes_orfas
FROM fusions f
LEFT JOIN demons a ON a.id = f.ingredient_a_id
LEFT JOIN demons b ON b.id = f.ingredient_b_id
WHERE a.id IS NULL OR b.id IS NULL;

-- resistências duplicadas (deve dar 0)
SELECT COUNT(*) AS resistencias_duplicadas FROM (
  SELECT demon_id, element_id FROM demon_resists
  GROUP BY demon_id, element_id HAVING COUNT(*) > 1
) t;

-- demônios sem nenhuma skill inata (esperado: 2)
SELECT d.name FROM demons d
LEFT JOIN demon_skills ds ON ds.demon_id = d.id
WHERE ds.demon_id IS NULL;

-- demônios que NÃO podem ser resultado de fusão normal (49 no SMT1)
SELECT
  SUM(f.n IS NOT NULL)                                  AS obtiveis_por_fusao,
  SUM(f.n IS NULL AND r.n IS NOT NULL)                   AS so_por_receita,
  SUM(f.n IS NULL AND r.n IS NULL)                       AS nao_obteniveis,
  COUNT(*)                                               AS total
FROM demons d
LEFT JOIN (SELECT result_demon_id, COUNT(*) n FROM fusions GROUP BY result_demon_id) f
  ON f.result_demon_id = d.id
LEFT JOIN (SELECT target_demon_id, COUNT(*) n FROM special_fusions GROUP BY target_demon_id) r
  ON r.target_demon_id = d.id;
-- normal = entram como resultado de fusão; recruit/enemy = recrutados/inimigos

-- células do chart sem nenhum demônio de nível compatível (curiosidade)
SELECT COUNT(*) AS celulas_com_elemental
FROM fusion_chart WHERE result_type = 'element_demon';

-- ---------------------------------------------------------------------------
-- 3. FICHA DE DEMÔNIO
-- ---------------------------------------------------------------------------
-- visão geral (usa a view v_demons)
SELECT * FROM v_demons WHERE name = 'Cerberus';

-- resistências do demônio
SELECT e.name AS elemento, res.effect AS efeito, res.damage_pct AS dano_pct
FROM demon_resists res
JOIN demons   d ON d.id = res.demon_id
JOIN elements e ON e.id = res.element_id
WHERE d.name = 'Cerberus'
ORDER BY e.sort_order;

-- skills inatas (usa a view v_demon_skills)
SELECT slot, skill, element, cost_mp, target, effect
FROM v_demon_skills WHERE demon = 'Cerberus' ORDER BY slot;

-- todos os demônios que aprendem uma skill
SELECT demon, level, slot FROM v_demon_skills WHERE skill = 'Samarecarm';

-- quem é fraco a 'expel' (efeito weak), ordenado por nível
SELECT d.name, d.level, r.name AS raca, res.damage_pct
FROM demon_resists res
JOIN demons d   ON d.id = res.demon_id
JOIN races  r   ON r.id = d.race_id
JOIN elements e ON e.id = res.element_id
WHERE e.name = 'expel' AND res.effect = 'weak'
ORDER BY d.level DESC LIMIT 15;

-- ---------------------------------------------------------------------------
-- 4. FUSÕES
-- ---------------------------------------------------------------------------
-- "como obter X" (reverse fissions) - compara com a aba do site
SELECT rule AS regra, ingredient_a AS ingrediente_1, ingredient_b AS ingrediente_2
FROM v_fusions WHERE result = 'Cerberus'
ORDER BY regra, ingrediente_1, ingrediente_2;

-- contagem por regra para um demônio
SELECT rule, COUNT(*) FROM fusions f
JOIN demons d ON d.id = f.result_demon_id
WHERE d.name = 'Indrajit' GROUP BY rule;

-- "o que sai de A + B" (forward) - par normalizado: a < b
SELECT res.name AS resultado, f.rule AS regra
FROM fusions f
JOIN demons res ON res.id = f.result_demon_id
JOIN demons a   ON a.id = f.ingredient_a_id
JOIN demons b   ON b.id = f.ingredient_b_id
WHERE (a.name = 'Unicorn' AND b.name = 'Bai Ze')
   OR (a.name = 'Bai Ze' AND b.name = 'Unicorn');

-- fusões só com um ingrediente (ex.: tudo que sai de fusing Cerberus com alguém)
SELECT res.name AS resultado, f.rule,
       IF(a.name = 'Cerberus', b.name, a.name) AS outro_ingrediente
FROM fusions f
JOIN demons res ON res.id = f.result_demon_id
JOIN demons a   ON a.id = f.ingredient_a_id
JOIN demons b   ON b.id = f.ingredient_b_id
WHERE a.name = 'Cerberus' OR b.name = 'Cerberus'
ORDER BY res.name;

-- chart raça x raça (a "tabela de fusão" clássica)
SELECT ra.name AS raca_a, rb.name AS raca_b,
       COALESCE(rr.name, sp.name, dd.name) AS resultado,
       fc.result_type AS tipo
FROM fusion_chart fc
JOIN races ra ON ra.id = fc.race_a_id
JOIN races rb ON rb.id = fc.race_b_id
LEFT JOIN races   rr ON rr.id = fc.result_race_id
LEFT JOIN species sp ON sp.id = fc.result_species_id
LEFT JOIN demons  dd ON dd.id = fc.result_demon_id
WHERE ra.name IN ('Beast', 'Holy') AND rb.name IN ('Beast', 'Holy')
ORDER BY raca_a, raca_b;

-- receitas especiais (pares/trios fixos, recrutamento, inimigos)
SELECT d.name AS alvo, sf.kind AS tipo, sf.recipe_text AS receita
FROM special_fusions sf JOIN demons d ON d.id = sf.target_demon_id
ORDER BY sf.kind, alvo LIMIT 20;

-- ---------------------------------------------------------------------------
-- 5. PARTY (exemplos para a Task 2) - tudo em transação, desfeito no final
--
--  Modelo fechado (ver ../docs/modelo-party.md):
--    * protagonista = nome + nível + alinhamento
--    * party_demons = até 5 demônios em slots 1..5 (1 = frente)
--    * o nível do demônio NÃO é gravado: vem de demons.level (no SMT1 demônios
--      não ganham EXP, o nível é fixo)
--    * comp_upgraded = 0 -> o jogo convoca 3 demônios; 1 -> 4 (COMP atualizado)
-- ---------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO parties
  (name, protagonist_name, protagonist_level, protagonist_alignment,
   comp_upgraded, human_partners, notes)
VALUES
  ('Minha party', 'Kazuya', 45, 'Neutral', 1, 'Heroína', 'Party do meio do jogo');

SET @party := LAST_INSERT_ID();

-- 5.1 inserir demônios nos slots 1..4 (o slot 5 fica livre)
INSERT INTO party_demons (party_id, slot, demon_id)
SELECT @party, x.slot, d.id
FROM (
  SELECT 'Cerberus' AS nome, 1 AS slot UNION ALL
  SELECT 'Unicorn',          2           UNION ALL
  SELECT 'Bai Ze',           3           UNION ALL
  SELECT 'Orthrus',          4
) AS x
JOIN demons d ON d.name = x.nome;

-- 5.2 party completa (usa a view)
SELECT party, protagonist_name, protagonist_level, protagonist_alignment,
       comp_upgraded, human_partners, slot, demon, race, demon_level
FROM v_parties_completo
WHERE party = 'Minha party'
ORDER BY slot;

INSERT INTO party_demons (party_id, slot, demon_id)
SELECT @party, 5, d.id FROM demons d WHERE d.name = 'Flaemis';

SELECT slot, demon, demon_level FROM v_parties_completo
WHERE party = 'Minha party' ORDER BY slot;

-- 5.3 trocar dois integrantes de posição
--     UNIQUE (party_id, slot) impede o UPDATE direto (e o CHECK barra um slot
--     temporário fora de 1..5), então: guarda os ids, apaga e reinsere trocados.
SET @dA := (SELECT demon_id FROM party_demons WHERE party_id = @party AND slot = 1);
SET @dB := (SELECT demon_id FROM party_demons WHERE party_id = @party AND slot = 2);
DELETE FROM party_demons WHERE party_id = @party AND slot IN (1, 2);
INSERT INTO party_demons (party_id, slot, demon_id) VALUES (@party, 1, @dB), (@party, 2, @dA);

SELECT slot, demon FROM v_parties_completo WHERE party = 'Minha party' ORDER BY slot;

-- 5.4 trocar um integrante por outro (DELETE + INSERT no mesmo slot)
DELETE pd FROM party_demons pd
JOIN demons d ON d.id = pd.demon_id
WHERE pd.party_id = @party AND d.name = 'Orthrus';

INSERT INTO party_demons (party_id, slot, demon_id)
SELECT @party, 4, d.id FROM demons d WHERE d.name = 'Kinnara';

SELECT slot, demon, demon_level FROM v_parties_completo
WHERE party = 'Minha party' ORDER BY slot;

-- 5.5 atualizar o protagonista
UPDATE parties
SET protagonist_level = 50, protagonist_alignment = 'Law', comp_upgraded = 0
WHERE id = @party;

-- 5.6 validações que o app vai avisar (avisam, mas não bloqueiam)
--     a) demônios acima do nível do protagonista (o jogo não deixa comandar)
SELECT p.name AS party, d.name AS demon, d.level AS demon_level,
       p.protagonist_level
FROM parties p
JOIN party_demons pd ON pd.party_id = p.id
JOIN demons d        ON d.id = pd.demon_id
WHERE d.level > p.protagonist_level
ORDER BY p.name, d.level;

--     b) demônios acima do que o COMP permite (3, ou 4 se comp_upgraded = 1)
SELECT p.name AS party, COUNT(*) AS demonios,
       CASE WHEN p.comp_upgraded = 1 THEN 4 ELSE 3 END AS limite_do_comp
FROM parties p
JOIN party_demons pd ON pd.party_id = p.id
GROUP BY p.id
HAVING demonios > limite_do_comp;

-- 5.7 excluir a party (party_demons cai junto pelo ON DELETE CASCADE)
DELETE FROM parties WHERE id = @party;

SELECT COUNT(*) AS parties_restantes FROM parties;

ROLLBACK;   -- nada disso fica no banco

-- ---------------------------------------------------------------------------
-- 6. CONFERÊNCIA DAS REGRAS DE FUSÃO
-- ---------------------------------------------------------------------------
-- Quantas fusões "normais" cada demônio tem (as maiores listas)
SELECT d.name AS resultado, COUNT(*) AS fusoes
FROM fusions f JOIN demons d ON d.id = f.result_demon_id
WHERE f.rule = 'normal'
GROUP BY d.id ORDER BY fusoes DESC LIMIT 10;

-- Nível máximo que sai de um par (regra normal: floor((lvlA + lvlB + 3) / 2),
-- respeitando o nível do resultado dentro da raça)
SELECT res.name AS resultado, res.level, a.level AS lvl_a, b.level AS lvl_b,
       FLOOR((a.level + b.level + 3) / 2) AS nivel_calculado
FROM fusions f
JOIN demons res ON res.id = f.result_demon_id
JOIN demons a   ON a.id = f.ingredient_a_id
JOIN demons b   ON b.id = f.ingredient_b_id
WHERE f.rule = 'normal'
ORDER BY a.level + b.level DESC LIMIT 5;

-- Elementais: como cada um desloca o nível (chart de elementos)
SELECT ed.name AS elemental, r.name AS raca, ec.level_offset AS deslocamento
FROM element_chart ec
JOIN demons ed ON ed.id = ec.element_demon_id
JOIN races  r  ON r.id = ec.race_id
ORDER BY ed.name, deslocamento DESC;

-- Elementais: fusões diretas entre pares de elementais
SELECT ea.name AS elemento_a, eb.name AS elemento_b, res.name AS resultado
FROM element_fusions ef
JOIN demons ea  ON ea.id = ef.element_a_demon_id
JOIN demons eb  ON eb.id = ef.element_b_demon_id
JOIN demons res ON res.id = ef.result_demon_id
ORDER BY elemento_a;

-- Quantos demônios cada regra consegue produzir
SELECT COUNT(DISTINCT result_demon_id) AS demonios_alcancados, rule
FROM fusions GROUP BY rule;
