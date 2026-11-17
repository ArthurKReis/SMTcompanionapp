-- ============================================================================
--  migrate_003_api_reads.sql - Modelo de leitura para a API
-- ----------------------------------------------------------------------------
--  Ajustes necessários para o front consumir os dados via API (ver
--  ../docs/arquitetura.md):
--    * índice em demons.level (ordenação da listagem)
--    * v_demons     -> expõe race_id e species_id (links e filtros)
--    * v_resistances-> expõe element_id (preserva a ordem dos 14 elementos)
--    * v_fusions    -> expõe ids e slugs dos três demônios
--    * v_parties_resumo (nova) -> party + contagem de demônios, inclui party vazia
--
--  Uso:  mysql -u root -p smt_db < migrate_003_api_reads.sql
--  (Se você ainda não importou nada, ignore: o smt_db.sql novo já traz tudo.)
-- ----------------------------------------------------------------------------

-- 1. índice para ordenar/filtrar por nível -----------------------------------
SET @tem_idx := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'demons'
                   AND index_name = 'idx_demons_level');
SET @sql := IF(@tem_idx = 0,
  'ALTER TABLE `demons` ADD INDEX `idx_demons_level` (`level`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. views do modelo de leitura ---------------------------------------------

CREATE OR REPLACE VIEW `v_demons` AS
SELECT d.`id`, d.`slug`, d.`name`,
       d.`race_id`, r.`name` AS `race`, sp.`id` AS `species_id`, sp.`name` AS `species`,
       d.`level`, d.`align_code`, d.`alignment`, r.`light_dark` AS `race_light_dark`,
       d.`drop_item`,
       d.`hp`, d.`mp`, d.`cp`, d.`strength`, d.`intelligence`, d.`magic`, d.`vitality`, d.`agility`, d.`luck`,
       d.`natk`, d.`nmin`, d.`nmax`, d.`patk`, d.`phit`, d.`defense`, d.`evasion`, d.`matk`, d.`mhit`
FROM `demons` d
JOIN `races` r    ON r.`id` = d.`race_id`
JOIN `species` sp ON sp.`id` = r.`species_id`;

CREATE OR REPLACE VIEW `v_resistances` AS
SELECT d.`id` AS `demon_id`, d.`slug`, d.`name` AS `demon`, r.`name` AS `race`,
       e.`id` AS `element_id`, e.`name` AS `element`, dr.`effect`, dr.`damage_pct`
FROM `demon_resists` dr
JOIN `demons` d   ON d.`id` = dr.`demon_id`
JOIN `races` r    ON r.`id` = d.`race_id`
JOIN `elements` e ON e.`id` = dr.`element_id`;

CREATE OR REPLACE VIEW `v_fusions` AS
SELECT f.`id`, f.`rule`,
       res.`id` AS `result_demon_id`, res.`slug` AS `result_slug`,
       res.`name` AS `result`, rr.`name` AS `result_race`, res.`level` AS `result_level`,
       ia.`id` AS `ingredient_a_id`, ia.`slug` AS `ingredient_a_slug`,
       ia.`name` AS `ingredient_a`, ia.`level` AS `ingredient_a_level`, ra.`name` AS `ingredient_a_race`,
       ib.`id` AS `ingredient_b_id`, ib.`slug` AS `ingredient_b_slug`,
       ib.`name` AS `ingredient_b`, ib.`level` AS `ingredient_b_level`, rb.`name` AS `ingredient_b_race`
FROM `fusions` f
JOIN `demons` res ON res.`id` = f.`result_demon_id`
JOIN `races` rr   ON rr.`id` = res.`race_id`
JOIN `demons` ia  ON ia.`id` = f.`ingredient_a_id`
JOIN `races` ra   ON ra.`id` = ia.`race_id`
JOIN `demons` ib  ON ib.`id` = f.`ingredient_b_id`
JOIN `races` rb   ON rb.`id` = ib.`race_id`;

CREATE OR REPLACE VIEW `v_parties_resumo` AS
SELECT p.`id` AS `party_id`, p.`name` AS `party`,
       p.`protagonist_name`, p.`protagonist_level`, p.`protagonist_alignment`,
       p.`comp_upgraded`, p.`human_partners`, p.`notes`,
       COUNT(pd.`id`) AS `demon_count`,
       CASE WHEN p.`comp_upgraded` = 1 THEN 4 ELSE 3 END AS `comp_limit`,
       p.`created_at`, p.`updated_at`
FROM `parties` p
LEFT JOIN `party_demons` pd ON pd.`party_id` = p.`id`
GROUP BY p.`id`;
