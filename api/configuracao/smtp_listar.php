<?php
/**
 * api/configuracao/smtp_listar.php
 * GET protegido (completo) - retorna as configurações de SMTP do .env.
 * A senha SMTP nunca é exposta: apenas se está configurada.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../app/Services/EnvManager.php';

use App\Services\EnvManager;

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$valores = EnvManager::ler();

$smtpPass = $valores['SMTP_PASS'] ?? '';

echo json_encode([
    'success'           => true,
    'smtp_host'         => $valores['SMTP_HOST'] ?? '',
    'smtp_port'         => $valores['SMTP_PORT'] ?? '',
    'smtp_secure'       => $valores['SMTP_SECURE'] ?? '',
    'smtp_user'         => $valores['SMTP_USER'] ?? '',
    'smtp_pass_configurada' => $smtpPass !== '',
    'smtp_from'         => $valores['SMTP_FROM'] ?? '',
    'smtp_from_name'    => $valores['SMTP_FROM_NAME'] ?? '',
    'cc_fixo'           => $valores['CC_FIXO'] ?? '',
    'envio_email_copia' => $valores['ENVIO_EMAIL_COPIA'] ?? '',
    'smtp_timeout'      => $valores['SMTP_TIMEOUT'] ?? '',
    'worker_intervalo_segundos' => $valores['WORKER_INTERVALO_SEGUNDOS'] ?? '',
    'allowed_extensions' => $valores['ALLOWED_EXTENSIONS'] ?? '',
]);