# Nota de segurança (versão vitrine)

## O que foi removido/substituído
Esta versão é sanitizada. **Não** fazem parte do repositório: arquivos `.env`/backups, `vendor/`, `storage/` (boletos e logs), dumps SQL/CSV de fornecedores (dados pessoais — LGPD), PDFs de boletos, pacotes de deploy, documentos internos e imagens de assinatura. E-mails, hosts, telefones e nomes foram trocados por placeholders (`contato@exemplo.com.br`, `exemplo.com.br`).

Todos os segredos (banco, SMTP, `JWT_SECRET`) são lidos **somente** de variáveis de ambiente (`.env` fora do repositório); os valores padrão no código são vazios.

## Endurecimentos aplicados nesta versão
- **Usuário administrador:** o `schema.sql` original criava um administrador com senha padrão conhecida; foi removido. Crie o seu com `password_hash()`.
- **Upload:** nome do arquivo reduzido a `basename()` (evita *path traversal*) e validação do **conteúdo real** (`%PDF-`), além de extensão e tamanho.
- **JWT:** `JWT_SECRET` exige no mínimo **32 caracteres** em produção, além da rejeição de valores padrão/vazios.

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
