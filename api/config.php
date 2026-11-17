<?php

/**
 * Configuração da API.
 *
 * Os valores abaixo são os padrões do XAMPP (root, sem senha) e servem para uso
 * local. Qualquer chave pode ser trocada por variável de ambiente:
 *
 *   SMT_DB_HOST, SMT_DB_PORT, SMT_DB_NAME
 *   SMT_API_DB_USER, SMT_API_DB_PASS
 *   SMT_DEBUG
 *
 * O config.example.php é a cópia de referência; o config.php é ignorado pelo
 * .gitignore por conter credenciais.
 */
return [
    'db' => [
        'host'    => getenv('SMT_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('SMT_DB_PORT') ?: 3306),
        'name'    => getenv('SMT_DB_NAME') ?: 'smt_db',
        // padrão do XAMPP: root sem senha.
        // Se preferir um usuário restrito, rode db/api_user.sql e troque para
        //   'user' => 'smt_api', 'pass' => 'smt_api'
        'user'    => getenv('SMT_API_DB_USER') ?: 'root',
        'pass'    => getenv('SMT_API_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        // Exibe a causa real dos erros 500 (ex.: falha ao conectar no banco) em
        // vez de "Erro interno no servidor.". Ligado por padrão porque o uso é
        // local; desligue se o app for exposto na rede.
        'debug'    => filter_var(getenv('SMT_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL),
        'timezone' => 'America/Sao_Paulo',
    ],
];
