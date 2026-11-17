-- ============================================================================
--  migrate_004_drop_fusion_kind.sql
-- ----------------------------------------------------------------------------
--  Remove `demons.fusion_kind`.
--
--  Por quê: a coluna não vinha dos dados de origem — ela era DERIVADA no
--  importador a partir da raça e das receitas especiais, e o resultado era
--  enganoso ("normal" para 22 demônios que não saem de fusão nenhuma).
--  A API agora calcula "como se obtém" a partir dos dados reais:
--    - existe em `fusions`            -> fusão
--    - receita kind pair/trio/prereq   -> receita especial
--    - receita kind recruit            -> recrutamento
--    - nenhuma das anteriores          -> chefe/inimigo
--
--  Nenhuma informação é perdida: recrutáveis continuam identificáveis pelas
--  10 receitas `kind='recruit'` e os demais pelo que existe em `fusions`.
--
--  Uso:  mysql -u root -p smt_db < migrate_004_drop_fusion_kind.sql
--  Ordem em banco antigo: 002 -> 003 -> 004.
-- ----------------------------------------------------------------------------

-- 1. remove o índice (se a coluna sair, o índice cai junto, mas garantimos) ---
SET @tem_idx := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'demons'
                   AND index_name = 'idx_demons_fusion');
SET @sql := IF(@tem_idx > 0, 'ALTER TABLE `demons` DROP INDEX `idx_demons_fusion`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. remove a coluna ---------------------------------------------------------
SET @tem_col := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'demons'
                   AND column_name = 'fusion_kind');
SET @sql := IF(@tem_col > 0, 'ALTER TABLE `demons` DROP COLUMN `fusion_kind`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. recria v_demons sem a coluna -------------------------------------------
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

-- 4. corrige o comentário estale do chart (236 -> 239) ----------------------
ALTER TABLE `fusion_chart`
  COMMENT = 'Chart de fusão normal raça x raça: 239 células preenchidas do chart do site (224 pares + 15 de mesma raça, que geram o elemental). Par normalizado: race_a_id < race_b_id';
