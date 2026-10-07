# Instalar o Acompanha-Aí na Hostinger

Com isso, **qualquer computador da escola** abre o mesmo site e já vê alunos, usuários e ocorrências atualizados. Não precisa clicar em backup.

O site e o MySQL ficam juntos na Hostinger. Envie `index.html`, `config.js`, `sw.js`, `manifest.webmanifest`, `.htaccess` e a pasta `api/`.

## 1. Criar o banco MySQL

No **hPanel** da Hostinger:

1. Abra **Bancos de Dados** → **Gerenciamento** (ou **MySQL Databases**).
2. Crie um banco (ex.: `acompanha`).
3. Crie um usuário e uma senha forte.
4. **Adicione o usuário ao banco**, com todas as permissões.

Anote o **nome completo** que o hPanel mostra (quase sempre com prefixo), por exemplo:

- Banco: `u123456789_acompanha`
- Usuário: `u123456789_user`
- Host: `localhost`

## 2. Enviar os arquivos

No **Gerenciador de Arquivos** (hPanel → Arquivos), envie para `public_html` (ou a pasta do domínio / subdomínio):

- `index.html`
- `config.js`
- `sw.js`
- `manifest.webmanifest`
- `.htaccess` (evita os computadores da escola ficarem com versão velha em cache)
- pasta `api/` (inteira)

Ligue o SSL do domínio (`https://seudominio.com.br`) em **Segurança** → **SSL**.

PHP recomendado: **8.1** ou **8.2** (hPanel → Avançado → Configuração PHP). A extensão **mysqli** precisa estar marcada.

Depois de enviar os arquivos, em **cada computador da escola** abra o site com **Ctrl+F5** (uma vez). Isso limpa a página antiga que o Chrome guardou.

**Não apague** `api/config.php` ao atualizar o site. Esse arquivo só existe na Hostinger (não vai no GitHub) e sem ele o sistema não conecta no servidor.

## 3. Rodar o instalador

Abra no navegador:

`https://seudominio.com.br/api/ativar.php`

(Se a pasta estiver em um subdiretório, use o caminho correspondente, ex.: `https://seudominio.com.br/acompanha/api/ativar.php`.)

Preencha os dados do MySQL (nomes **completos**). Deixe **URL extra** vazia.

Clique em **Criar tabela e ativar**.

Depois:

1. Confirme no login a mensagem de sincronização ativa (status verde).
2. **Apague** `api/ativar.php`, `api/install.php` e `api/teste.php`.

### Se aparecer “página não encontrada”

1. Confirme que `ativar.php` está em `public_html/api/ativar.php` (não só no computador).
2. No Gerenciador de Arquivos, permissões da pasta `api/`: **755**.
3. Use a **instalação manual** no final da própria página do instalador (SQL + `config.php`).
4. Não use só o nome curto do banco — use o nome com prefixo do hPanel.

### Se o MySQL não conectar

1. Host: `localhost` (o PHP e o banco estão no mesmo servidor).
2. Banco e usuário com o prefixo completo (`u123456789_...`).
3. Usuário vinculado ao banco, com todas as permissões.
4. Em **Configuração PHP**, confirme que **mysqli** está ativo.

## 4. Primeiro uso

- Usuário: `superadmin`
- Senha: `Admin@123` (troque depois)

Cadastre professores e alunos **uma vez**. Nos outros PCs, abra o endereço da Hostinger.

## Instalação manual (se o formulário falhar)

1. hPanel → **phpMyAdmin** → seu banco → SQL:

```sql
CREATE TABLE IF NOT EXISTS acompanha_store (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  payload LONGTEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acompanha_push_vapid (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  public_key VARCHAR(255) NOT NULL,
  private_d VARCHAR(255) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS acompanha_push_subs (
  endpoint_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id VARCHAR(64) NOT NULL,
  escola_id VARCHAR(64) NOT NULL DEFAULT '',
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL,
  INDEX idx_user (user_id),
  INDEX idx_escola (escola_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

2. Copie `api/config.example.php` para `api/config.php` e preencha:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_acompanha');
define('DB_USER', 'u123456789_user');
define('DB_PASS', 'sua_senha');
define('API_TOKEN', 'cole-um-segredo-longo-aqui');
define('API_CORS_ORIGINS', '');
```

3. Em `api/public-token.js` e no `config.js` da raiz:

```js
window.ACOMPANHA_API_URL = 'api/index.php';
window.ACOMPANHA_API_TOKEN = 'cole-o-mesmo-segredo-longo-aqui';
```

4. Abra o site com Ctrl+F5.

## Servidor offline / erro 500

1. Abra o site pelo **https do domínio**, não pelo arquivo no computador.
2. Se a página inteira der erro 500, no Gerenciador de Arquivos **apague** o `.htaccess` que estiver em `public_html` (não o da pasta `api/`). O anti-cache do HTML continua valendo pelos scripts com `?v=`.
3. Abra `https://seudominio.com.br/api/teste.php`. Precisa aparecer `mysqli: OK`. Apague esse arquivo depois.
4. Abra `https://seudominio.com.br/api/ativar.php`, preencha o MySQL (nomes completos, host `localhost`) e ative.
5. Recarregue o sistema com **Ctrl+F5**. O status precisa ficar verde.
