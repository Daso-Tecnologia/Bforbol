<?php
/**
 * api/email/testar.php
 * POST protegido - envia um e-mail de teste com um PDF fictício em anexo.
 * Requer apenas o e-mail do destinatário.
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/MailSender.php';

use App\Services\MailSender;

header('Content-Type: application/json');

// Garantir tempo suficiente para o envio SMTP (quando o host permite)
@set_time_limit(60);

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$email = isset($input['email']) ? mb_strtolower(trim($input['email'])) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe um e-mail de destinatário válido']);
    exit;
}

// Gera um PDF fictício válido em memória/arquivo temporário
$dirTmp = STORAGE_PATH . '/tmp';
if (!is_dir($dirTmp)) {
    @mkdir($dirTmp, 0775, true);
}

$pdfTmp = $dirTmp . '/teste_email_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.pdf';

$okPdf = gerarPdfFicticio($pdfTmp);

if (!$okPdf) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao gerar o PDF fictício de teste']);
    exit;
}

try {
    $mail = new MailSender();
    $resultado = $mail->enviarTeste($email, $pdfTmp);

    if ($resultado['ok']) {
        echo json_encode([
            'success' => true,
            'message' => 'E-mail de teste enviado para ' . $email . '. Verifique a caixa de entrada (e a pasta de spam).',
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Falha no envio: ' . $resultado['mensagem']]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha no envio: ' . $e->getMessage()]);
} finally {
    if (is_file($pdfTmp)) {
        @unlink($pdfTmp);
    }
}

/**
 * Gera um PDF de uma página, mínimo e válido.
 */
function gerarPdfFicticio(string $destino): bool
{
    $obj = [];

    $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';

    $obj[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

    $obj[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
        . '/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';

    $stream  = "BT /F1 20 Tf 56 770 Td (E-mail de teste - Sistema de Boletos Sua Empresa) Tj ET\n";
    $stream .= "BT /F1 12 Tf 56 742 Td (Este PDF e ficticio e acompanha o e-mail de teste.) Tj ET\n";
    $stream .= "BT /F1 12 Tf 56 722 Td (Emitido em: " . date('d/m/Y H:i:s') . ") Tj ET\n";

    $obj[4] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";

    $obj[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($obj as $n => $corpo) {
        $offsets[$n] = strlen($pdf);
        $pdf .= $n . " 0 obj\n" . $corpo . "\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $count = count($obj) + 1;

    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($obj); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefStart}\n%%EOF\n";

    return file_put_contents($destino, $pdf) !== false;
}