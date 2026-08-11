# Acompanha-Aí

Sistema de ocorrências escolares (Verde / Amarelo / Vermelho).

## Persistência (Render estático)

### Configurar a nuvem uma vez

1. Entre como **admin** → aba **Nuvem** → **Criar nuvem**.
2. Copie o código gerado.
3. No `index.html`, cole o código aqui:

```js
const CLOUD_BLOB_ID_FIXO = 'COLE-O-CODIGO-AQUI';
```

4. Publique de novo no Render.

Depois disso, **professores e todos os PCs sincronizam sozinhos** — não é preciso configurar máquina por máquina.

### Backup em arquivo

Na aba **Nuvem**: Exportar / Importar JSON.

## E-mail automático (EmailJS + Outlook)

1. Crie conta em [emailjs.com](https://www.emailjs.com/).
2. **Email Services** → Add Service → **Outlook / Office 365** e conecte o e-mail da escola.
3. **Email Templates** → Create Template:
   - **To Email:** `{{to_email}}`
   - **Subject:** `{{subject}}`
   - **Content:** use `{{message}}` (ou as variáveis individuais).
4. Variáveis disponíveis: `to_email`, `to_name`, `subject`, `protocolo`, `nivel`, `data`, `professor`, `aluno`, `ra`, `turma`, `tutor`, `tipo`, `descricao`, `message`.
5. No sistema: admin → aba **E-mail** → cole Public Key, Service ID e Template ID → **Salvar**.

Ao registrar ocorrência com e-mail do responsável, o aviso é enviado automaticamente. Também há o botão **E-mail** no detalhe para reenviar.
