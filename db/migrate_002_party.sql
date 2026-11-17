-- ============================================================================
--  migrate_002_party.sql - Adequa as tabelas de party ao modelo fechado do SMT1
-- ----------------------------------------------------------------------------
--  Use este arquivo SÓ se você já tinha importado o smt_db.sql antigo e
--  quer manter os dados (as tabelas de party estão vazias no dump, então o
--  caminho normal continua sendo reimportar o smt_db.sql novo).
--
--  O que muda (decisões registradas em ../docs/modelo-party.md):
--    * parties.protagonist_alignment  -> alinhamento do protagonista
--    * parties.comp_upgraded          -> limite de demônios 3 (0) ou 4 (1)
--    * parties.human_partners         -> parceiros humanos (texto livre)
--    * party_demons.current_level     -> REMOVIDO (no SMT1 o nível é fixo)
--    * party_demons.slot              -> CHECK 1..5
--    * v_parties_completo             -> recriada sem current_level
--
--  Uso:  mysql -u root -p smt_db < migrate_002_party.sql
--
--  ATENÇÃO:  a coluna current_level é APAGADA e o valor que estiver nela é
--  perdido (não há para onde migrar: o nível do demônio passa a vir sempre de
--  demons.level). As parties e os demônios em si continuam intactos.
--
--  Ordem de aplicação em um banco antigo: migrate_002 e depois migrate_003.
--  Testado nessa ordem: parties e party_demons preservados, 6 views no fim.
-- ----------------------------------------------------------------------------

-- 1. parties -----------------------------------------------------------------
ALTER TABLE `parties`
  MODIFY COLUMN `protagonist_level` TINYINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Nível do protagonista (1 a 99)',
  ADD COLUMN IF NOT EXISTS `protagonist_alignment` ENUM('Law','Neutral','Chaos')
    DEFAULT NULL COMMENT 'Alinhamento do protagonista; no SMT1 ele afeta negociação e fusão'
    AFTER `protagonist_level`,
  ADD COLUMN IF NOT EXISTS `comp_upgraded` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '0 = COMP inicial (3 demônios convocados); 1 = atualizado por Stephen (4 demônios)'
    AFTER `protagonist_alignment`,
  ADD COLUMN IF NOT EXISTS `human_partners` VARCHAR(80) DEFAULT NULL
    COMMENT 'Parceiros humanos na formação, texto livre (Law Hero, Chaos Hero, Heroína)'
    AFTER `comp_upgraded`,
  COMMENT = 'Party do SMT1: protagonista (nome, nível, alinhamento) + até 5 demônios. comp_upgraded guarda o limite 3->4 do jogo';

-- 2. party_demons ------------------------------------------------------------
ALTER TABLE `party_demons`
  DROP COLUMN IF EXISTS `current_level`,
  MODIFY COLUMN `slot` TINYINT UNSIGNED NOT NULL
    COMMENT 'Posição na formação: 1 = frente, 5 = fundo (a posição muda o dano recebido)',
  MODIFY COLUMN `demon_id` SMALLINT UNSIGNED NOT NULL
    COMMENT 'O nível vem de demons.level: no SMT1 demônios não ganham EXP',
  COMMENT = 'Até 5 demônios por party. O SMT1 convoca 3 (4 com o COMP atualizado): o excedente é permitido, mas o app avisa';

-- 3. CHECK de slot (idempotente: só cria se ainda não existir) ---------------
SET @tem_chk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE()
    AND table_name   = 'party_demons'
    AND constraint_name = 'chk_pd_slot'
);
SET @sql := IF(@tem_chk = 0,
  'ALTER TABLE `party_demons` ADD CONSTRAINT `chk_pd_slot` CHECK (`slot` BETWEEN 1 AND 5)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. view --------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_parties_completo` AS
SELECT p.`id` AS `party_id`, p.`name` AS `party`,
       p.`protagonist_name`, p.`protagonist_level`, p.`protagonist_alignment`,
       p.`comp_upgraded`, p.`human_partners`,
       pd.`slot`, d.`id` AS `demon_id`, d.`slug`, d.`name` AS `demon`,
       r.`name` AS `race`, d.`level` AS `demon_level`
FROM `parties` p
JOIN `party_demons` pd ON pd.`party_id` = p.`id`
JOIN `demons` d        ON d.`id` = pd.`demon_id`
JOIN `races` r         ON r.`id` = d.`race_id`;
