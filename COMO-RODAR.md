# Como rodar este projeto

Aplicação web para Shin Megami Tensei 1: consulta de demônios, CRUD de parties e
consulta de fusões. PHP + MySQL/MariaDB no servidor, Bootstrap + jQuery no
navegador. Não precisa de internet para funcionar.

---

## 1. Requisitos

- PHP 8.0 ou superior com as extensões `pdo_mysql` e `mbstring`
  (o XAMPP já traz todas).
- MySQL 5.7+, MariaDB 10.x ou superior.
- Navegador atualizado.

O projeto foi testado com PHP 8.4.27 e MariaDB 11.8.6.

---

## 2. Instalação no XAMPP (Windows, Linux ou macOS)

### Passo 1 — copiar os arquivos

Extraia o conteúdo para a pasta `htdocs` do XAMPP, de modo que o caminho fique:

```
C:\xampp\htdocs\smt-app\
```

Dentro dela devem estar `api/`, `db/`, `public/`, `src/`, `tests/`, `docs/`,
`index.php` e este arquivo.

### Passo 2 — iniciar os serviços

Abra o **XAMPP Control Panel** e dê **Start** em **Apache** e em **MySQL**.

### Passo 3 — importar o banco

**Pelo phpMyAdmin (mais simples)**

1. Acesse `http://localhost/phpmyadmin`.
2. Aba **Importar**.
3. Escolha o arquivo `db/smt_db.sql`.
4. Clique em **Executar**.

O script cria o banco `smt_db` e carrega tudo: 244 demônios, 105 skills, 13.985
fusões, 239 células da tabela raça × raça e 49 receitas especiais. Leva cerca de
meio segundo.

**Pela linha de comando**

```bash
mysql -u root -p < db/smt_db.sql
```

### Passo 4 — abrir o app

```
http://localhost/smt-app/public/
```

Também funciona entrar por `http://localhost/smt-app/`, que redireciona para
`public/`.

Pronto. A tela inicial lista os demônios.

---

## 3. Sem XAMPP: servidor embutido do PHP

Com o banco já importado, dentro da pasta do projeto:

```bash
php -S 0.0.0.0:8080 -t .
```

- Front: `http://localhost:8080/`
- API: `http://localhost:8080/api/index.php?rota=health`

---

## 4. Credenciais do banco

O padrão é o do XAMPP: usuário `root`, sem senha. Isso já está configurado em
`api/config.php`, então normalmente **não é preciso editar nada**.

Se o seu MySQL tiver senha, abra `api/config.php` e ajuste:

```php
'user' => 'root',
'pass' => 'sua_senha',
```

Alternativas, sem editar o arquivo:

```bash
SMT_API_DB_USER=root SMT_API_DB_PASS=suasenha php -S 0.0.0.0:8080 -t .
```

Existe também um usuário de privilégio mínimo, opcional. Para criá-lo:

```bash
mysql -u root -p < db/api_user.sql
```

Depois troque em `api/config.php` para `'user' => 'smt_api'` e
`'pass' => 'smt_api'`. Esse usuário só lê o catálogo e escreve em `parties` e
`party_demons`.

---

## 5. Se algo der errado

**A página abre em branco ou sem dados**

Abra `http://localhost/smt-app/api/index.php?rota=health`. Se aparecer um erro
em JSON, a mensagem já traz a causa (o modo de depuração vem ligado). O caso mais
comum é o banco não importado ou a senha diferente da configurada.

**`Erro ao conectar: SQLSTATE[HY000] [1045] Access denied`**

Usuário ou senha errados em `api/config.php`.

**`Unknown database 'smt_db'`**

O passo 3 não foi concluído. Importe `db/smt_db.sql`.

**Porta 80 ocupada**

Outro programa está usando a porta. No XAMPP, mude a porta do Apache em
**Config > httpd.conf** (`Listen 80` para `Listen 8080`) e acesse
`http://localhost:8080/smt-app/public/`.

**Quero esconder os detalhes dos erros**

Coloque `'debug' => false` em `api/config.php` ou rode o PHP com
`SMT_DEBUG=false`. Com o modo desligado, o navegador recebe apenas "Erro interno
no servidor." e o detalhe vai para o log do Apache/PHP.

---

## 6. O que tem no pacote

```
smt-app/
├── index.php          redireciona a raiz para public/
├── api/               ponto de entrada da API + configuração
├── src/               núcleo da API (roteador, validação, repositórios)
├── public/            interface (HTML, CSS, JS, jQuery e Bootstrap locais)
├── db/                schema, importador, dump pronto, migrações, cache dos JSONs
├── tests/             testes de API, de borda e de interface
├── docs/              documentação técnica e capturas de tela
├── README.md          visão geral do projeto
└── COMO-RODAR.md      este arquivo
```

O banco já vem populado no dump. O `db/import.php` só é necessário para
reimportar os dados de origem (por exemplo, se quiser atualizar o catálogo).

---

## 7. Testes (opcional)

Com o app rodando em `http://127.0.0.1:8080`:

```bash
python3 tests/api_test.py        # contrato da API: 87 verificações
python3 tests/edge_test.py       # borda e abuso: 23 verificações
```

Os testes de interface usam Playwright e precisam de Node:

```bash
npm i playwright && npx playwright install chromium
node tests/ui_test.js            # 40 verificações
```

Todos usam dados temporários e apagam as parties que criam.

---

## 8. Como usar o app

**Demônios** — tabela com busca por nome, filtros de raça, grupo, alinhamento e
faixa de nível, ordenação por coluna e paginação. Clicar numa linha abre a ficha
com atributos, parâmetros de combate, as 14 resistências e as skills inatas.

**Fusões** — três modos:

- *Como obter*: escolha o demônio e veja os pares que o produzem.
- *O que sai dele*: escolha o demônio e veja o que ele gera com outros.
- *Tabela raça × raça*: o mapa do jogo, 22 raças por 22 raças, com realce de
  linha e coluna ao passar o mouse e clique no elemental para abrir a ficha.

**Parties** — criar, editar, excluir e listar. O protagonista tem nome, nível e
alinhamento; a formação aceita até 5 demônios em posições. O app avisa, sem
bloquear o salvamento, quando um demônio está acima do nível do protagonista ou
quando a party passa do limite do COMP (3, ou 4 com o upgrade).
