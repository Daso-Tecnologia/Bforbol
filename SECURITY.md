# Nota de segurança (versão vitrine)

## O que foi removido/substituído
Esta versão é sanitizada. **Não** fazem parte do repositório: arquivos `.env`/backups, `vendor/`, `storage/` (boletos e logs), dumps SQL/CSV de fornecedores (dados pessoais — LGPD), PDFs de boletos, pacotes de deploy, documentos internos e imagens de assinatura. E-mails, hosts, telefones e nomes foram trocados por placeholders (`contato@exemplo.com.br`, `exemplo.com.br`).

Todos os segredos (banco, SMTP, `JWT_SECRET`) são lidos **somente** de variáveis de ambiente (`.env` fora do repositório); os valores padrão no código são vazios.

## Endurecimentos aplicados nesta versão
- **Usuário administrador:** o `schema.sql` original criava um administrador com senha padrão conhecida; foi removido. Crie o seu com `password_hash()`.
- **Upload:** nome do arquivo reduzido a `basename()` (evita *path traversal*) e validação do **conteúdo real** (`%PDF-`), além de extensão e tamanho.
- **JWT:** `JWT_SECRET` exige no mínimo **32 caracteres** em produção, além da rejeição de valores padrão/vazios.
- **Autenticação:** somente e-mail e senha (hash `bcrypt`). O usuário é **reconfirmado no banco a cada requisição**: desativar ou rebaixar uma conta vale na hora, e o token nunca é aceito pela URL.
- **Telas (XSS):** todo dado vindo do servidor é escapado (`escHtml`) antes de entrar no HTML.
- **E-mail de teste:** restrito ao **administrador**, com limite por minuto (por IP) e por hora (por usuário), para que o SMTP não seja usado como relé de mensagens.
- **Erros:** falhas internas não expõem caminhos nem SQL ao cliente; o detalhe vai para o log com uma referência curta.
- **Servidor web:** enviar os cabeçalhos `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` e HSTS, e bloquear por HTTP `app/`, `config/`, `cron/`, `database/`, `vendor/` e `storage/`.
- **Respostas da API:** `Cache-Control: no-store`, para que dados de fornecedores e usuários não fiquem em cache de navegador ou proxy.
- **Validação de entrada:** campos de texto de login e cadastros conferem tipo e tamanho máximo das colunas (resposta 400 clara, em vez de erro do banco).
- **Dependências:** removido o pacote JWT sem uso (o token é próprio, em `config/security.php`); `composer audit` sem avisos.

## Controles já presentes
- JWT HS256 com expiração e comparação em tempo constante (`hash_equals`).
- Bloqueio de login por tentativas e *rate limit* por IP.
- Consultas com *prepared statements* (`PDO`); `display_errors` desligado em produção.
- Senhas de usuários com `password_hash`/`password_verify`.
- Cabeçalho `X-Frame-Options`.

## Recomendações para quem for implantar
Servir apenas por HTTPS (HSTS), adicionar `Content-Security-Policy`, manter `storage/` fora da raiz web e rotacionar periodicamente o `JWT_SECRET` e as credenciais de SMTP/banco.

## Reportar vulnerabilidade
Envie os detalhes de forma privada para o contato institucional, sem abrir *issue* pública.
