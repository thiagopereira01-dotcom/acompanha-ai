# Acompanha-Aí

Sistema de ocorrências escolares (Verde / Amarelo / Vermelho).

## Papéis

| Perfil | O que faz |
|--------|-----------|
| **Admin full** (`superadmin`) | Cadastra escolas e libera/suspende licenças |
| **Administrador da escola** | Gestão da escola; pode também ser professor |
| **Professor** | Registra ocorrências |

Um usuário pode ser **administrador e professor** ao mesmo tempo (marque “Também atua como professor”). No topo, alterne entre **Gestão** e **Ocorrência**.

O sistema identifica a **escola** no topo da tela. Sem licença ativa, usuários daquela escola não entram.

## Hospedagem: página no Render + dados na HostGator

A tela (`index.html`) fica no Render. O MySQL e a API PHP ficam na HostGator.

1. Na HostGator: crie o banco MySQL e envie **somente a pasta `api/`**.
2. Abra `https://seudominio.com.br/api/ativar.php`.
3. Preencha o banco e, em **URL do site no Render**, cole `https://seu-app.onrender.com` (sem barra no final).
4. Baixe o `config.js` que o instalador gera.
5. No Render (Static Site), publique `index.html` e o `config.js` (com a URL da API e o token).
6. Apague `api/ativar.php` na HostGator.

Se a API já estava instalada, edite `api/config.php` e acrescente:

```php
define('API_CORS_ORIGINS', 'https://seu-app.onrender.com');
```

Passo a passo da HostGator: [INSTALAR-HOSTGATOR.md](INSTALAR-HOSTGATOR.md).

## Hospedagem só na HostGator

1. Crie um banco MySQL no cPanel.
2. Envie `index.html`, `config.js` e a pasta `api/` para `public_html`.
3. Abra `https://seudominio.com.br/api/ativar.php` e preencha os dados do banco (URL do Render vazia).
4. Apague `api/ativar.php` depois que o status ficar verde.

## Primeiro acesso (Admin full)

- Usuário: `superadmin`
- Senha: `Admin@123`

Fluxo:

1. Aba **Escolas / Licenças** → cadastre a escola e deixe a licença **ativa**.
2. Aba **Usuários** → crie o administrador da escola (vincule à escola; opcionalmente “também professor”).
3. O admin da escola cadastra professores e alunos.

Administradores antigos com login `admin` continuam válidos (escola migrada automaticamente).

## Persistência

Com a API na HostGator, o MySQL é a fonte da verdade. O navegador só guarda um cache.

Backups extras (aba **Servidor**): Google Drive, exportar/importar JSON.

## E-mail automático (EmailJS + Outlook)

1. Crie conta em [emailjs.com](https://www.emailjs.com/).
2. **Email Services** → Add Service → **Outlook / Office 365**.
3. **Email Templates** → To Email `{{to_email}}`, Subject `{{subject}}`, Content `{{message}}`.
4. No sistema: admin → aba **E-mail** → Public Key, Service ID e Template ID → **Salvar**.
