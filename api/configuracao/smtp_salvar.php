<?php
/**
 * api/configuracao/smtp_salvar.php
 * POST protegido (completo) - salva as configurações de SMTP no .env.
 *
 * Segurança:
 *  - Somente administrador.
 *  - Exige a senha do próprio usuário logado como confirmação.
 *  - Apenas chaves SMTP de uma whitelist são alteradas (EnvManager).
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../app/Services/EnvManager.php';

use App\Services\EnvManager;

header('Content-Type: application/json');

$usuarioLogado = validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$novos = [
    'SMTP_HOST'         => (string) ($input['smtp_host'] ?? ''),
    'SMTP_PORT'         => (string) ($input['smtp_port'] ?? ''),
    'SMTP_SECURE'       => (string) ($input['smtp_secure'] ?? ''),
    'SMTP_USER'         => (string) ($input['smtp_user'] ?? ''),
    'SMTP_PASS'         => (string) ($input['smtp_pass'] ?? ''),
    'SMTP_FROM'         => (string) ($input['smtp_from'] ?? ''),
    'SMTP_FROM_NAME'    => (string) ($input['smtp_from_name'] ?? ''),
    'CC_FIXO'           => (string) ($input['cc_fixo'] ?? ''),
    'ENVIO_EMAIL_COPIA' => (string) ($input['envio_email_copia'] ?? ''),
    'SMTP_TIMEOUT'      => (string) ($input['smtp_timeout'] ?? ''),
    'WORKER_INTERVALO_SEGUNDOS' => (string) ($input['worker_intervalo_segundos'] ?? '5'),
    'ALLOWED_EXTENSIONS' => (string) ($input['allowed_extensions'] ?? 'pdf'),
];

$senhaConfirmacao = (string) ($input['senha_confirmacao'] ?? '');

if ($senhaConfirmacao === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Informe sua senha para confirmar a alteração.']);
    exit;
}

$resultado = EnvManager::salvar($novos, $senhaConfirmacao, (int) $usuarioLogado['id']);

if (!$resultado['ok']) {
    http_response_code(400);
    echo json_encode(['error' => $resultado['erro'] ?? 'Falha ao salvar as configurações.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Configurações de SMTP salvas com sucesso. As novas configurações valerão a partir do próximo envio.',
]);