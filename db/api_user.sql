-- ============================================================================
--  api_user.sql - Usuário de banco com privilégio mínimo para a API
-- ----------------------------------------------------------------------------
--  O importador (import.php) precisa de privilégios amplos, mas a API não:
--  ela só LÊ o catálogo e ESCREVE em parties/party_demons.
--
--  Uso:  mysql -u root -p < api_user.sql
--  Depois ajuste api/config.php com este usuário/senha.
-- ----------------------------------------------------------------------------

CREATE USER IF NOT EXISTS 'smt_api'@'localhost' IDENTIFIED BY 'smt_api';

-- leitura de todo o catálogo (tabelas e views)
GRANT SELECT ON `smt_db`.* TO 'smt_api'@'localhost';

-- escrita apenas nas tabelas de party
GRANT INSERT, UPDATE, DELETE ON `smt_db`.`parties`      TO 'smt_api'@'localhost';
GRANT INSERT, UPDATE, DELETE ON `smt_db`.`party_demons` TO 'smt_api'@'localhost';

-- mesma coisa para conexões TCP em 127.0.0.1 (evita depender de resolução reversa)
CREATE USER IF NOT EXISTS 'smt_api'@'127.0.0.1' IDENTIFIED BY 'smt_api';
GRANT SELECT ON `smt_db`.* TO 'smt_api'@'127.0.0.1';
GRANT INSERT, UPDATE, DELETE ON `smt_db`.`parties`      TO 'smt_api'@'127.0.0.1';
GRANT INSERT, UPDATE, DELETE ON `smt_db`.`party_demons` TO 'smt_api'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Conferência rápida:
--   SHOW GRANTS FOR 'smt_api'@'localhost';
