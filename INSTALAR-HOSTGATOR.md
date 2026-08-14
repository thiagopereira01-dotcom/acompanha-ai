# Instalar o Acompanha-Aí na HostGator

Com isso, **qualquer computador da escola** abre o mesmo site e já vê alunos, usuários e ocorrências atualizados. Não precisa clicar em backup.

Há dois jeitos:

- **Página no Render + dados aqui:** envie só a pasta `api/`. A tela fica no Render.
- **Tudo na HostGator:** envie `index.html`, `config.js` e a pasta `api/`.

## 1. Criar o banco MySQL

No **cPanel** da HostGator:

1. Abra **Bancos de Dados MySQL**.
2. Crie um banco (ex.: `acompanha`).
3. Crie um usuário e uma senha forte.
4. **Adicione o usuário ao banco**, com todas as permissões.

Anote o **nome completo** que o cPanel mostra (quase sempre com prefixo), por exemplo:

- Banco: `seucpanel_acompanha`
- Usuário: `seucpanel_user`
- Host: `localhost`

## 2. Enviar os arquivos

No **Gerenciador de Arquivos** (ou FTP), envie para `public_html` (ou a pasta do domínio):

- pasta `api/` (inteira) — **obrigatório**
- `index.html` e `config.js` — só se a página também ficar na HostGator

O SSL do domínio da API precisa estar ligado (`https://seudominio.com.br`).

## 3. Rodar o instalador

Abra no navegador:

`https://seudominio.com.br/api/ativar.php`

(Se a pasta estiver em um subdiretório, use o caminho correspondente, ex.: `https://seudominio.com.br/acompanha/api/ativar.php`.)

Preencha os dados do MySQL (nomes **completos**).

Se a página estiver no **Render**, preencha **URL do site no Render** (`https://seu-app.onrender.com`, sem barra no final). Depois baixe o `config.js` e publique esse arquivo junto com o `index.html` no Render.

Clique em **Criar tabela e ativar**.

Depois:

1. Confirme no login a mensagem de sincronização ativa.
2. **Apague** `api/ativar.php` e `api/install.php`.

### API já instalada (só liberar o Render)

Edite `api/config.php` e acrescente (ou altere) esta linha, com a URL exata do site no Render:

```php
define('API_CORS_ORIGINS', 'https://seu-app.onrender.com');
```

No `config.js` do Render:

```js
window.ACOMPANHA_API_URL = 'https://seudominio.com.br/api/index.php';
window.ACOMPANHA_API_TOKEN = 'o-mesmo-token-do-config.php';
```

### Se aparecer “página não encontrada”

1. Confirme que `ativar.php` está em `public_html/api/ativar.php` (não só no computador).
2. No Gerenciador de Arquivos, permissões da pasta `api/`: **755**.
3. Use a **instalação manual** no final da própria página do instalador (SQL + `config.php`).
4. Não use só o nome curto do banco — use o nome com prefixo do cPanel.

## 4. Primeiro uso

- Usuário: `superadmin`
- Senha: `Admin@123` (troque depois)

Cadastre professores e alunos **uma vez**. Nos outros PCs, abra o endereço do Render (ou o da HostGator, se a página estiver lá).

## Instalação manual (se o formulário falhar)

1. phpMyAdmin → seu banco → SQL:

```sql
CREATE TABLE IF NOT EXISTS acompanha_store (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

2. Copie `api/config.example.php` para `api/config.php` e preencha:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'seucpanel_acompanha');
define('DB_USER', 'seucpanel_user');
define('DB_PASS', 'sua_senha');
define('API_TOKEN', 'cole-um-segredo-longo-aqui');
define('API_CORS_ORIGINS', 'https://seu-app.onrender.com');
```

Deixe `API_CORS_ORIGINS` vazio (`''`) se a página também estiver na HostGator.

3. Em `api/public-token.js`:

```js
window.ACOMPANHA_API_TOKEN = 'cole-o-mesmo-segredo-longo-aqui';
```

4. Se a página estiver no Render, o `config.js` deve ter a URL da API e o mesmo token.

5. Abra o site com Ctrl+F5.
