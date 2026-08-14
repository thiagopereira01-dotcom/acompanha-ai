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

## Hospedagem na HostGator (recomendado)

1. Crie um banco MySQL no cPanel.
2. Envie `index.html` e a pasta `api/` para `public_html`.
3. Abra `https://seudominio.com.br/api/ativar.php` e preencha os dados do banco.
4. Apague `api/ativar.php` depois que o status ficar verde.

Passo a passo: [INSTALAR-HOSTGATOR.md](INSTALAR-HOSTGATOR.md).

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
