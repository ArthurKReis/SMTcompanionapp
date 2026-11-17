-- =============================================================================
--  Shin Megami Tensei (SMT1) - Schema do banco de dados
--  Projeto: site full-stack PHP + Bootstrap + jQuery
--  Tarefa 1: importar os dados de demônios / skills / fusões para o MySQL
--
--  Compatível com: MySQL 5.7+, MySQL 8.x e MariaDB 10.x (XAMPP)
--  Engine: InnoDB | Charset: utf8mb4 / utf8mb4_unicode_ci
--
--  Origem dos dados: https://github.com/aqiu384/megaten-fusion-tool
--                    (src/app/smt1/data/*.json)
--
--  Como este arquivo é usado:
--    * Tabelas de CATÁLOGO (seções 1 a 5) são DROPADAS e recriadas: elas são
--      um espelho fiel dos dados de origem, reconstruído por import.php.
--    * Tabelas de PARTY (seção 6) e de controle (seção 7) usam CREATE TABLE
--      IF NOT EXISTS: elas guardam dados do usuário e NÃO podem ser perdidas
--      quando os dados do jogo são reimportados.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `smt_db`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `smt_db`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. Catálogo: grupos (species), raças e elementos
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `species`;
CREATE TABLE `species` (
  `id`              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(20)      NOT NULL COMMENT 'Nome do grupo (DEITIES, ICONS, NETHERS, AERIALS, DRAGONS, AVIANS, BEASTS, BRUTES, ELEMENTS, SPIRITS, FOULS, HUMANS, MACHINES)',
  `is_triple_chart` TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = aparece no chart de fusões triplas',
  `sort_order`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_species_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Agrupamento de raças usado pelas regras de fusão (antigo "species")';

DROP TABLE IF EXISTS `races`;
CREATE TABLE `races` (
  `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(20)      NOT NULL,
  `species_id`      TINYINT UNSIGNED NOT NULL,
  `align_code`      CHAR(2)          NOT NULL COMMENT 'Alinhamento da raça: 1a letra = light/neutral/dark (l/n/d; "D" = dark extremo), 2a letra = law/neutral/chaos (l/n/c)',
  `alignment`       ENUM('Law','Neutral','Chaos') NOT NULL COMMENT 'Tradução da 2a letra do align_code',
  `light_dark`      TINYINT          NOT NULL COMMENT '1 = light, 0 = neutral, -1 = dark, -2 = dark extremo (raças "D*")',
  `in_normal_chart` TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = a raça participa do chart de fusão normal',
  `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_races_name` (`name`),
  KEY `idx_races_species` (`species_id`),
  CONSTRAINT `fk_races_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Raças dos demônios (Deity, Beast, Fairy, Drake...)';

DROP TABLE IF EXISTS `elements`;
CREATE TABLE `elements` (
  `id`             TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(12)      NOT NULL,
  `is_resist_elem` TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = elemento usado na tabela de resistências',
  `is_skill_elem`  TINYINT(1)       NOT NULL DEFAULT 0 COMMENT '1 = elemento usado pelas skills',
  `sort_order`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_elements_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Elementos: 14 de resistência (sword, gun, fire...) + 5 só de skill (almighty, energy, recovery, support, special)';

-- -----------------------------------------------------------------------------
-- 2. Demônios e suas características
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `demons`;
CREATE TABLE `demons` (
  `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         VARCHAR(60)       NOT NULL COMMENT 'Identificador para URL (ex.: baek-young)',
  `name`         VARCHAR(60)       NOT NULL,
  `race_id`      SMALLINT UNSIGNED NOT NULL,
  `level`        DECIMAL(4,1)      NOT NULL COMMENT 'Nível do demônio. Pode ter casa decimal (ex.: 55.1) quando o site usa o nível como critério de desempate nas fusões; a ficha exibe o valor como está',
  `align_code`   CHAR(2)           NOT NULL COMMENT 'Alinhamento do demônio (normalmente igual ao da raça)',
  `alignment`    ENUM('Law','Neutral','Chaos') NOT NULL,
  `drop_item`    VARCHAR(40)       DEFAULT NULL COMMENT 'Item dropado (NULL = nenhum)',
  -- atributos (stats) -------------------------------------------------------------------
  `hp`           SMALLINT UNSIGNED NOT NULL,
  `mp`           SMALLINT UNSIGNED NOT NULL,
  `cp`           TINYINT UNSIGNED  NOT NULL COMMENT 'CP exibido na ficha',
  `strength`     TINYINT UNSIGNED  NOT NULL COMMENT 'St',
  `intelligence` TINYINT UNSIGNED  NOT NULL COMMENT 'In',
  `magic`        TINYINT UNSIGNED  NOT NULL COMMENT 'Ma',
  `vitality`     TINYINT UNSIGNED  NOT NULL COMMENT 'Vi',
  `agility`      TINYINT UNSIGNED  NOT NULL COMMENT 'Ag',
  `luck`         TINYINT UNSIGNED  NOT NULL COMMENT 'Lu',
  -- parâmetros de combate (bloco "Attacks" do site) -------------------------------------
  `natk`         SMALLINT UNSIGNED NOT NULL COMMENT 'NAtk - ataque físico base',
  `nmin`         TINYINT UNSIGNED  NOT NULL COMMENT 'NMin - número mínimo de golpes',
  `nmax`         DECIMAL(3,1)      NOT NULL COMMENT 'NMax - número máximo de golpes (aceita fração, ex.: 0.8)',
  `patk`         SMALLINT UNSIGNED NOT NULL COMMENT 'PAtk - poder físico',
  `phit`         TINYINT UNSIGNED  NOT NULL COMMENT 'PHit - acerto físico',
  `defense`      TINYINT UNSIGNED  NOT NULL COMMENT 'Def',
  `evasion`      TINYINT UNSIGNED  NOT NULL COMMENT 'Eva',
  `matk`         TINYINT UNSIGNED  NOT NULL COMMENT 'MAtk - poder mágico',
  `mhit`         TINYINT UNSIGNED  NOT NULL COMMENT 'MHit - acerto mágico',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_demons_name` (`name`),
  UNIQUE KEY `uq_demons_slug` (`slug`),
  KEY `idx_demons_race_lvl` (`race_id`, `level`),
  KEY `idx_demons_level` (`level`),
  CONSTRAINT `fk_demons_race` FOREIGN KEY (`race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Os 244 demônios do jogo: nível, raça, atributos e parâmetros de combate';

DROP TABLE IF EXISTS `demon_resists`;
CREATE TABLE `demon_resists` (
  `demon_id`   SMALLINT UNSIGNED NOT NULL,
  `element_id` TINYINT UNSIGNED  NOT NULL,
  `effect`     ENUM('drain','repel','null','neutral','resist','weak') NOT NULL
               COMMENT 'drain = absorve, repel = reflete, null = anula, neutral = 100%, resist = 12,5% a 87,5%, weak = 150% a 300%',
  `damage_pct` DECIMAL(5,1)      NOT NULL COMMENT 'Percentual de dano recebido conforme o código de origem do site',
  PRIMARY KEY (`demon_id`, `element_id`),
  KEY `idx_resists_element` (`element_id`),
  KEY `idx_resists_effect` (`effect`),
  CONSTRAINT `fk_resists_demon` FOREIGN KEY (`demon_id`)   REFERENCES `demons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resists_elem`  FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Resistências: 1 linha por demônio x elemento (244 x 14 = 3.416 linhas)';

-- -----------------------------------------------------------------------------
-- 3. Skills e o vínculo com os demônios
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `skills`;
CREATE TABLE `skills` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(50)       NOT NULL,
  `name`       VARCHAR(50)       NOT NULL,
  `element_id` TINYINT UNSIGNED  NOT NULL,
  `power`      SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Poder/dano base (NULL = skill sem poder numérico)',
  `cost_mp`    TINYINT UNSIGNED  DEFAULT NULL COMMENT 'Custo em MP (NULL = custa um turno, "Extra")',
  `is_extra`   TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = skill "Extra": não gasta MP, consome o turno',
  `target`     VARCHAR(30)       DEFAULT NULL COMMENT 'Alvo (1 Foe, 1 Row of Foes, All Foes, 1 Ally, Random Foes...)',
  `effect`     VARCHAR(160)      DEFAULT NULL COMMENT 'Efeito descritivo (Freeze, Absorb HP, Insta Death...)',
  `is_enemy`   TINYINT(1)        NOT NULL DEFAULT 0 COMMENT '1 = skill usada por inimigos',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skills_name` (`name`),
  UNIQUE KEY `uq_skills_slug` (`slug`),
  KEY `idx_skills_element` (`element_id`),
  KEY `idx_skills_cost` (`cost_mp`),
  CONSTRAINT `fk_skills_element` FOREIGN KEY (`element_id`) REFERENCES `elements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='As 105 skills do jogo';

DROP TABLE IF EXISTS `demon_skills`;
CREATE TABLE `demon_skills` (
  `demon_id` SMALLINT UNSIGNED NOT NULL,
  `skill_id` SMALLINT UNSIGNED NOT NULL,
  `slot`     TINYINT UNSIGNED  NOT NULL COMMENT 'Ordem em que a skill aparece na ficha do demônio',
  PRIMARY KEY (`demon_id`, `skill_id`),
  KEY `idx_ds_skill` (`skill_id`),
  CONSTRAINT `fk_ds_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ds_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Skills inatas (689 vínculos demônio x skill)';

-- -----------------------------------------------------------------------------
-- 4. Tabelas de apoio às fusões (as regras do jogo)
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `fusion_chart`;
CREATE TABLE `fusion_chart` (
  `race_a_id`         SMALLINT UNSIGNED NOT NULL,
  `race_b_id`         SMALLINT UNSIGNED NOT NULL,
  `result_type`       ENUM('race','species','element_demon') NOT NULL
                      COMMENT 'O cruzamento gera: uma raça, um grupo (species) ou um elemental específico',
  `result_race_id`    SMALLINT UNSIGNED DEFAULT NULL,
  `result_species_id` TINYINT UNSIGNED  DEFAULT NULL,
  `result_demon_id`   SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`race_a_id`, `race_b_id`),
  KEY `idx_fc_result_race` (`result_race_id`),
  KEY `idx_fc_result_species` (`result_species_id`),
  KEY `idx_fc_result_demon` (`result_demon_id`),
  CONSTRAINT `fk_fc_race_a` FOREIGN KEY (`race_a_id`)         REFERENCES `races` (`id`),
  CONSTRAINT `fk_fc_race_b` FOREIGN KEY (`race_b_id`)         REFERENCES `races` (`id`),
  CONSTRAINT `fk_fc_res_ra` FOREIGN KEY (`result_race_id`)    REFERENCES `races` (`id`),
  CONSTRAINT `fk_fc_res_sp` FOREIGN KEY (`result_species_id`) REFERENCES `species` (`id`),
  CONSTRAINT `fk_fc_res_de` FOREIGN KEY (`result_demon_id`)   REFERENCES `demons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chart de fusão normal raça x raça: 239 células preenchidas do chart do site (224 pares + 15 de mesma raça, que geram o elemental). Par normalizado: race_a_id < race_b_id';

DROP TABLE IF EXISTS `dark_race_recipes`;
CREATE TABLE `dark_race_recipes` (
  `dark_race_id`   SMALLINT UNSIGNED NOT NULL COMMENT 'Raça "dark" (Vile, Tyrant, Drake, Raptor, Foul, Haunt, Spirit, Undead, Jaki, Wilder)',
  `result_race_id` SMALLINT UNSIGNED NOT NULL COMMENT 'Raça resultante quando dois demônios da mesma raça dark são fundidos',
  PRIMARY KEY (`dark_race_id`),
  KEY `idx_drr_result` (`result_race_id`),
  CONSTRAINT `fk_drr_dark`   FOREIGN KEY (`dark_race_id`)   REFERENCES `races` (`id`),
  CONSTRAINT `fk_drr_result` FOREIGN KEY (`result_race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Conversões das 10 raças dark (mapa "darks" do fusion-chart.json). SMT1 mantém o dado, mas a versão atual do site NÃO usa essas regras na listagem de fusões — tabela preservada para consulta/futuro';

DROP TABLE IF EXISTS `fusion_species_chart`;
CREATE TABLE `fusion_species_chart` (
  `species_a_id`   TINYINT UNSIGNED  NOT NULL COMMENT '1o grupo do par que forma o demônio intermediário',
  `species_b_id`   TINYINT UNSIGNED  NOT NULL COMMENT '2o grupo do par (species_a_id < species_b_id)',
  `species_c_id`   TINYINT UNSIGNED  NOT NULL COMMENT 'Grupo do 3o demônio que fecha a fusão tripla',
  `result_race_id` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Raça resultante; NULL = célula "?" (indefinida no jogo)',
  PRIMARY KEY (`species_a_id`, `species_b_id`, `species_c_id`),
  KEY `idx_fsc_result` (`result_race_id`),
  CONSTRAINT `fk_fsc_sp_a` FOREIGN KEY (`species_a_id`)   REFERENCES `species` (`id`),
  CONSTRAINT `fk_fsc_sp_b` FOREIGN KEY (`species_b_id`)   REFERENCES `species` (`id`),
  CONSTRAINT `fk_fsc_sp_c` FOREIGN KEY (`species_c_id`)   REFERENCES `species` (`id`),
  CONSTRAINT `fk_fsc_race` FOREIGN KEY (`result_race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Chart de fusões triplas: 28 pares de grupos x 8 terceiros grupos = 224 células';

DROP TABLE IF EXISTS `element_chart`;
CREATE TABLE `element_chart` (
  `element_demon_id` SMALLINT UNSIGNED NOT NULL COMMENT 'Elemental (Salamander, Undine, Sylph, Gnome, Flaemis, Aquans, Aeros, Erthys)',
  `race_id`          SMALLINT UNSIGNED NOT NULL,
  `level_offset`     TINYINT           NOT NULL COMMENT 'Deslocamento aplicado ao índice de nível da raça: +2, +1 ou -1',
  PRIMARY KEY (`element_demon_id`, `race_id`),
  KEY `idx_ec_race` (`race_id`),
  CONSTRAINT `fk_ec_elem` FOREIGN KEY (`element_demon_id`) REFERENCES `demons` (`id`),
  CONSTRAINT `fk_ec_race` FOREIGN KEY (`race_id`)          REFERENCES `races` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Como cada elemental desloca o nível quando fundido com um demônio de determinada raça';

DROP TABLE IF EXISTS `element_fusions`;
CREATE TABLE `element_fusions` (
  `element_a_demon_id` SMALLINT UNSIGNED NOT NULL,
  `element_b_demon_id` SMALLINT UNSIGNED NOT NULL,
  `result_demon_id`    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`element_a_demon_id`, `element_b_demon_id`),
  KEY `idx_ef_result` (`result_demon_id`),
  CONSTRAINT `fk_ef_elem_a` FOREIGN KEY (`element_a_demon_id`) REFERENCES `demons` (`id`),
  CONSTRAINT `fk_ef_elem_b` FOREIGN KEY (`element_b_demon_id`) REFERENCES `demons` (`id`),
  CONSTRAINT `fk_ef_result` FOREIGN KEY (`result_demon_id`)    REFERENCES `demons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fusões fixas entre dois elementais (ex.: Salamander x Undine = Itzamna)';

DROP TABLE IF EXISTS `special_fusions`;
CREATE TABLE `special_fusions` (
  `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_demon_id` SMALLINT UNSIGNED NOT NULL,
  `kind`            ENUM('pair','trio','prereq','recruit','enemy') NOT NULL,
  `recipe_text`     VARCHAR(120)      NOT NULL COMMENT 'Receita como aparece no site (ex.: "Barong x Rangda")',
  `ingredient_1_id` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Ingrediente resolvido quando existe um demônio com esse nome',
  `ingredient_2_id` SMALLINT UNSIGNED DEFAULT NULL,
  `ingredient_3_id` SMALLINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_special` (`target_demon_id`, `kind`, `recipe_text`),
  KEY `idx_sf_ing1` (`ingredient_1_id`),
  KEY `idx_sf_ing2` (`ingredient_2_id`),
  KEY `idx_sf_ing3` (`ingredient_3_id`),
  CONSTRAINT `fk_sf_target` FOREIGN KEY (`target_demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sf_ing1`   FOREIGN KEY (`ingredient_1_id`) REFERENCES `demons` (`id`),
  CONSTRAINT `fk_sf_ing2`   FOREIGN KEY (`ingredient_2_id`) REFERENCES `demons` (`id`),
  CONSTRAINT `fk_sf_ing3`   FOREIGN KEY (`ingredient_3_id`) REFERENCES `demons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Receitas especiais/fixas: pares, trios, pré-requisitos, recrutamento e inimigos';

-- -----------------------------------------------------------------------------
-- 5. Fusões pré-calculadas (a "lista das possíveis fusões")
-- -----------------------------------------------------------------------------

DROP TABLE IF EXISTS `fusions`;
CREATE TABLE `fusions` (
  `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `result_demon_id` SMALLINT UNSIGNED NOT NULL COMMENT 'Demônio resultante da fusão',
  `ingredient_a_id` SMALLINT UNSIGNED NOT NULL,
  `ingredient_b_id` SMALLINT UNSIGNED NOT NULL,
  `rule`            ENUM('normal','species','dark','element') NOT NULL COMMENT 'Regra que gera a fusão',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fusions` (`result_demon_id`, `ingredient_a_id`, `ingredient_b_id`, `rule`),
  KEY `idx_fusions_a` (`ingredient_a_id`),
  KEY `idx_fusions_b` (`ingredient_b_id`),
  KEY `idx_fusions_rule` (`rule`),
  CONSTRAINT `fk_fusions_result` FOREIGN KEY (`result_demon_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fusions_a`      FOREIGN KEY (`ingredient_a_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fusions_b`      FOREIGN KEY (`ingredient_b_id`) REFERENCES `demons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fusões concretas pré-calculadas pelas regras do SMT1 (13.985 linhas): normal ~11.4k, species ~1k, element ~1.5k (inclui os pares entre elementais). "dark" existe no ENUM por fidelidade ao modelo de dados, mas o SMT1 não gera nenhuma linha com essa regra. Par de ingredientes normalizado: ingredient_a_id < ingredient_b_id';

-- -----------------------------------------------------------------------------
-- 6. Party (dados do usuário - preservados entre importações)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `parties` (
  `id`                    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                  VARCHAR(40)       NOT NULL COMMENT 'Nome dado pelo jogador à party',
  `protagonist_name`      VARCHAR(40)       DEFAULT NULL COMMENT 'Nome do personagem do jogador',
  `protagonist_level`     TINYINT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'Nível do protagonista (1 a 99)',
  `protagonist_alignment` ENUM('Law','Neutral','Chaos') DEFAULT NULL
    COMMENT 'Alinhamento do protagonista; no SMT1 ele afeta negociação e fusão',
  `comp_upgraded`         TINYINT(1)        NOT NULL DEFAULT 0
    COMMENT '0 = COMP inicial (3 demônios convocados); 1 = atualizado por Stephen (4 demônios)',
  `human_partners`        VARCHAR(80)       DEFAULT NULL
    COMMENT 'Parceiros humanos na formação, texto livre (Law Hero, Chaos Hero, Heroína)',
  `notes`                 VARCHAR(255)      DEFAULT NULL,
  `created_at`            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parties_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Party do SMT1: protagonista (nome, nível, alinhamento) + até 5 demônios. comp_upgraded guarda o limite 3->4 do jogo';

CREATE TABLE IF NOT EXISTS `party_demons` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id`      SMALLINT UNSIGNED NOT NULL,
  `slot`          TINYINT UNSIGNED  NOT NULL COMMENT 'Posição na formação: 1 = frente, 5 = fundo (a posição muda o dano recebido)',
  `demon_id`      SMALLINT UNSIGNED NOT NULL COMMENT 'O nível vem de demons.level: no SMT1 demônios não ganham EXP',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_party_slot` (`party_id`, `slot`),
  UNIQUE KEY `uq_party_demon` (`party_id`, `demon_id`),
  KEY `idx_pd_demon` (`demon_id`),
  CONSTRAINT `chk_pd_slot` CHECK (`slot` BETWEEN 1 AND 5),
  CONSTRAINT `fk_pd_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pd_demon` FOREIGN KEY (`demon_id`) REFERENCES `demons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Até 5 demônios por party. O SMT1 convoca 3 (4 com o COMP atualizado): o excedente é permitido, mas o app avisa';

-- -----------------------------------------------------------------------------
-- 7. Controle de importação (dados do usuário - preservados)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `import_log` (
  `id`                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `source_url`            VARCHAR(255)      NOT NULL COMMENT 'Pasta de origem dos JSONs',
  `source_ref`            VARCHAR(120)      NOT NULL COMMENT 'Branch/commit usado como origem',
  `imported_at`           TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checksum_sha256`       CHAR(64)          NOT NULL COMMENT 'Hash dos arquivos de origem',
  `demons_count`          SMALLINT UNSIGNED NOT NULL,
  `skills_count`          SMALLINT UNSIGNED NOT NULL,
  `races_count`           SMALLINT UNSIGNED NOT NULL,
  `fusions_count`         INT UNSIGNED      NOT NULL,
  `special_fusions_count` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico das importações feitas por import.php';

-- -----------------------------------------------------------------------------
-- 8. Views de consulta (facilitam o site e os relatórios)
-- -----------------------------------------------------------------------------

DROP VIEW IF EXISTS `v_demons`;
CREATE VIEW `v_demons` AS
SELECT d.`id`, d.`slug`, d.`name`,
       d.`race_id`, r.`name` AS `race`, sp.`id` AS `species_id`, sp.`name` AS `species`,
       d.`level`, d.`align_code`, d.`alignment`, r.`light_dark` AS `race_light_dark`,
       d.`drop_item`,
       d.`hp`, d.`mp`, d.`cp`, d.`strength`, d.`intelligence`, d.`magic`, d.`vitality`, d.`agility`, d.`luck`,
       d.`natk`, d.`nmin`, d.`nmax`, d.`patk`, d.`phit`, d.`defense`, d.`evasion`, d.`matk`, d.`mhit`
FROM `demons` d
JOIN `races` r    ON r.`id` = d.`race_id`
JOIN `species` sp ON sp.`id` = r.`species_id`;

DROP VIEW IF EXISTS `v_demon_skills`;
CREATE VIEW `v_demon_skills` AS
SELECT d.`id` AS `demon_id`, d.`slug`, d.`name` AS `demon`, d.`level`,
       s.`id` AS `skill_id`, s.`name` AS `skill`, e.`name` AS `element`,
       s.`power`, s.`cost_mp`, s.`is_extra`, s.`target`, s.`effect`, s.`is_enemy`,
       ds.`slot`
FROM `demon_skills` ds
JOIN `demons` d   ON d.`id` = ds.`demon_id`
JOIN `skills` s   ON s.`id` = ds.`skill_id`
JOIN `elements` e ON e.`id` = s.`element_id`;

DROP VIEW IF EXISTS `v_resistances`;
CREATE VIEW `v_resistances` AS
SELECT d.`id` AS `demon_id`, d.`slug`, d.`name` AS `demon`, r.`name` AS `race`,
       e.`id` AS `element_id`, e.`name` AS `element`, dr.`effect`, dr.`damage_pct`
FROM `demon_resists` dr
JOIN `demons` d   ON d.`id` = dr.`demon_id`
JOIN `races` r    ON r.`id` = d.`race_id`
JOIN `elements` e ON e.`id` = dr.`element_id`;

DROP VIEW IF EXISTS `v_fusions`;
CREATE VIEW `v_fusions` AS
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

DROP VIEW IF EXISTS `v_parties_completo`;
CREATE VIEW `v_parties_completo` AS
SELECT p.`id` AS `party_id`, p.`name` AS `party`,
       p.`protagonist_name`, p.`protagonist_level`, p.`protagonist_alignment`,
       p.`comp_upgraded`, p.`human_partners`,
       pd.`slot`, d.`id` AS `demon_id`, d.`slug`, d.`name` AS `demon`,
       r.`name` AS `race`, d.`level` AS `demon_level`
FROM `parties` p
JOIN `party_demons` pd ON pd.`party_id` = p.`id`
JOIN `demons` d        ON d.`id` = pd.`demon_id`
JOIN `races` r         ON r.`id` = d.`race_id`;

DROP VIEW IF EXISTS `v_parties_resumo`;
CREATE VIEW `v_parties_resumo` AS
SELECT p.`id` AS `party_id`, p.`name` AS `party`,
       p.`protagonist_name`, p.`protagonist_level`, p.`protagonist_alignment`,
       p.`comp_upgraded`, p.`human_partners`, p.`notes`,
       COUNT(pd.`id`) AS `demon_count`,
       CASE WHEN p.`comp_upgraded` = 1 THEN 4 ELSE 3 END AS `comp_limit`,
       p.`created_at`, p.`updated_at`
FROM `parties` p
LEFT JOIN `party_demons` pd ON pd.`party_id` = p.`id`
GROUP BY p.`id`;

SET FOREIGN_KEY_CHECKS = 1;
