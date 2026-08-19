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

## Hospedagem na Hostinger

O site (`index.html`) e os dados (MySQL + API PHP) ficam no **mesmo servidor** da Hostinger.

1. No hPanel: crie o banco MySQL e envie `index.html`, `config.js` e a pasta `api/` para `public_html`.
2. Abra `https://seudominio.com.br/api/ativar.php`.
3. Preencha o banco (host `localhost`, nomes **completos** do hPanel). Deixe a URL extra vazia.
4. Confirme o status verde no login e apague `api/ativar.php`.

Passo a passo: [INSTALAR-HOSTINGER.md](INSTALAR-HOSTINGER.md).

## Primeiro acesso (Admin full)

- Usuário: `superadmin`
- Senha: `Admin@123`

Fluxo:

1. Aba **Escolas / Licenças** → cadastre a escola e deixe a licença **ativa**.
2. Aba **Usuários** → crie o administrador da escola (vincule à escola; opcionalmente “também professor”).
3. O admin da escola cadastra professores e alunos.

Administradores antigos com login `admin` continuam válidos (escola migrada automaticamente).

## Persistência

Com a API na Hostinger, o MySQL é a fonte da verdade. O navegador só guarda um cache.

Backups extras (aba **Servidor**): Google Drive, exportar/importar JSON.

## E-mail automático (EmailJS + Outlook)

1. Crie conta em [emailjs.com](https://www.emailjs.com/).
2. **Email Services** → Add Service → **Outlook / Office 365**.
3. **Email Templates** → To Email `{{to_email}}`, Subject `{{subject}}`, Content `{{message}}`.
4. No sistema: admin → aba **E-mail** → Public Key, Service ID e Template ID → **Salvar**.
