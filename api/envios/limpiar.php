<?php
/**
 * api/envios/limpiar.php
 * POST protegido (completo) - limpieza da fila de envios/uploads.
 *
 * Acciones:
 *   - errores        : borra filas com estado 'erro' (envios_log + log_upload)
 *   - pendientes     : borra filas com estado 'pendente' (envios_log + log_upload), conserva os PDFs
 *   - reset_completo : borra pendientes + errores das duas tabelas, borra TODOS os log_upload
 *                      (para permitir re-upload dos mesmos boletos, item 25) e borra os PDFs
 *                      de storage/enviar/* e storage/boleto/* (re-upload desde zero).
 *
 * O histórico 'enviado' de envios_log se conserva sempre (base dos relatórios).
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = (string) ($input['accion'] ?? '');

if (!in_array($accion, ['errores', 'pendientes', 'reset_completo'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'accion inválida (errores | pendientes | reset_completo)']);
    exit;
}

/**
 * Borra TODOS os arquivos (recursivo) dentro de uma carpeta, conservando as subcarpetas.
 * @return int cantidad de archivos borrados
 */
function vaciarCarpeta(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $borrados = 0;
    foreach (scandir($dir) as $entrada) {
        if ($entrada === '.' || $entrada === '..') {
            continue;
        }
        $ruta = $dir . '/' . $entrada;
        if (is_dir($ruta)) {
            $borrados += vaciarCarpeta($ruta);
        } else {
            @unlink($ruta);
            $borrados++;
        }
    }
    return $borrados;
}

$pdo = getConnection();

$enviosBorrados   = 0;
$uploadsBorrados  = 0;
$archivosBorrados = 0;

if ($accion === 'errores' || $accion === 'reset_completo') {
    $enviosBorrados += dbExecute("DELETE FROM envios_log WHERE estado = 'erro'");
}
if ($accion === 'pendientes' || $accion === 'reset_completo') {
    $enviosBorrados += dbExecute("DELETE FROM envios_log WHERE estado = 'pendente'");
}
if ($accion === 'errores') {
    $uploadsBorrados += dbExecute("DELETE FROM log_upload WHERE status = 'erro'");
}
if ($accion === 'pendientes') {
    $uploadsBorrados += dbExecute("DELETE FROM log_upload WHERE status = 'pendente'");
}
if ($accion === 'reset_completo') {
    // Todos os log_upload: o bloqueo de duplicados (item 25) checa por arquivo_origem
    $uploadsBorrados += dbExecute('DELETE FROM log_upload');
    $archivosBorrados += vaciarCarpeta(UPLOAD_PATH);
    $archivosBorrados += vaciarCarpeta(BOLETO_PATH);
}

echo json_encode([
    'success'           => true,
    'message'           => 'Limpieza realizada: ' . $enviosBorrados . ' envio(s), ' . $uploadsBorrados . ' upload(s), ' . $archivosBorrados . ' arquivo(s) borrados.',
    'envios_borrados'   => $enviosBorrados,
    'uploads_borrados'  => $uploadsBorrados,
    'archivos_borrados' => $archivosBorrados,
]);