# Instalar o Acompanha-Aí na HostGator

Com isso, **qualquer computador da escola** abre o mesmo site e já vê alunos, usuários e ocorrências atualizados. Não precisa clicar em backup.

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

- `index.html`
- pasta `api/` (inteira)

O site deve abrir em `https://seudominio.com.br/` (SSL ligado).

## 3. Rodar o instalador

Abra no navegador:

`https://seudominio.com.br/api/ativar.php`

(Se a pasta estiver em um subdiretório, use o caminho correspondente, ex.: `https://seudominio.com.br/acompanha/api/ativar.php`.)

Preencha os dados do MySQL (nomes **completos**) e clique em **Criar tabela e ativar**.

Depois:

1. Confirme no login a mensagem **Servidor da escola conectado**.
2. **Apague** `api/ativar.php` e `api/install.php`.

### Se aparecer “página não encontrada”

1. Confirme que `ativar.php` está em `public_html/api/ativar.php` (não só no computador).
2. No Gerenciador de Arquivos, permissões da pasta `api/`: **755**.
3. Use a **instalação manual** no final da própria página do instalador (SQL + `config.php`).
4. Não use só o nome curto do banco — use o nome com prefixo do cPanel.

## 4. Primeiro uso

- Usuário: `admin`
- Senha: `Admin@123` (troque depois)

Cadastre professores e alunos **uma vez**. Nos outros PCs, abra o mesmo endereço.

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
```

3. Em `api/public-token.js`:

```js
window.ACOMPANHA_API_TOKEN = 'cole-o-mesmo-segredo-longo-aqui';
```

4. Abra o site com Ctrl+F5.
