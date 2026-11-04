<?php
/**
 * api/boletos/reenviar_lote.php
 * POST protegido (completo) - reenvia em lote os boletos que falharam.
 *
 * Recebe:
 *   { "ids": [1, 2, 3] }
 *
 * Reenvia apenas os envios com estado 'erro'. Para cada um, usa a mesma
 * lógica do reenviar.php: reutiliza os PDFs que ainda existem na pasta do
 * cliente; em sucesso move para a subpasta "enviado" e marca como 'enviado';
 * em falha mantém 'erro' e registra a mensagem.
 Retorna o resumo por id.

 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';
require_once __DIR__ . '/../../app/Services/MailSender.php';

use App\Services\BoletoReader;
use App\Services\MailSender;

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ids = isset($input['ids']) && is_array($input['ids']) ? array_filter(array_map('intval', $input['ids']), fn($v) => $v > 0) : [];

if (count($ids) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Informe ao menos um ID de envio para reenviar']);
    exit;
}

$pdo = getConnection();
$mail = new MailSender();

// Intervalo entre envios (segundos) - evita sobrecarregar o provedor SMTP.
$intervalo = WORKER_INTERVALO_SEGUNDOS;
// Processamento em lote pode demorar; não deixar o timeout do PHP interromper.
set_time_limit(0);

$resultados = [];
$reenviados = 0;
$falhas    = 0;

foreach ($ids as $id) {
    try {
        $resultado = ['id' => $id, 'ok' => false, 'mensagem' => ''];

    // Buscar o envio com erro
    $stmt = $pdo->prepare("SELECT * FROM envios_log WHERE id = :id AND estado = 'erro' LIMIT 1");
    $stmt->execute(['id' => $id]);
    $envio = $stmt->fetch();

    if (!$envio) {
        $resultado['mensagem'] = 'Envio não encontrado (ou não está com erro).';
        $resultados[] = $resultado;
        $falhas++;
        continue;
    }

    // Buscar fornecedor
    $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => (int) $envio['fornecedor_id']]);
    $fornecedor = $stmt->fetch();

    if (!$fornecedor) {
        $resultado['mensagem'] = 'Fornecedor não encontrado';
        $resultados[] = $resultado;
        $falhas++;
        continue;
    }

    // Anexos (JSON salvo no log)
    $anexos = json_decode($envio['anexos'] ?? '[]', true) ?: [];
    if (!is_array($anexos)) $anexos = [];

    // Manter apenas os arquivos que ainda existem
    $anexosExistentes = array_values(array_filter($anexos, 'is_file'));

    if (count($anexosExistentes) === 0) {
        // Verificar se ainda estão na pasta do cliente
        $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);
        $anexosExistentes = BoletoReader::listarArquivosDaPasta($pastaCliente);
        $anexosExistentes = array_map(fn($a) => $pastaCliente . '/' . $a, $anexosExistentes);
    }

    if (count($anexosExistentes) === 0) {
        $resultado['mensagem'] = 'Não há documentos disponíveis para reenvio';
        $resultados[] = $resultado;
        $falhas++;
        continue;
    }

    // Enviar
    try {
        $envioResultado = $mail->enviarBoleto($fornecedor, $anexosExistentes, $envio['assunto'] ?? '');
    } catch (\Throwable $e) {
        $envioResultado = ['ok' => false, 'mensagem' => $e->getMessage()];
    }

    if ($envioResultado['ok']) {
        // Mover para a pasta "enviado"
        $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);
        $nomesArquivos = array_map('basename', $anexosExistentes);
        BoletoReader::moverParaEnviado($pastaCliente, $nomesArquivos);

        $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'enviado', mensagem_erro = NULL, data_tentativa = :data WHERE id = :id");
        $stmt->execute(['data' => date('Y-m-d H:i:s'), 'id' => $id]);

        $resultado['ok']        = true;
        $resultado['mensagem'] = 'Enviado com sucesso';
        $reenviados++;
    } else {
        $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'erro', mensagem_erro = :msg, data_tentativa = :data WHERE id = :id");
        $stmt->execute([
            'msg'  => substr($envioResultado['mensagem'], 0, 1000),
            'data' => date('Y-m-d H:i:s'),
            'id'   => $id,
        ]);

        $resultado['mensagem'] = $envioResultado['mensagem'];
        $falhas++;
    }

    $resultados[] = $resultado;
    } finally {
        // Pausa entre envios (mesmo quando há continue no try).
        if ($intervalo > 0) {
            sleep($intervalo);
        }
    }
}

echo json_encode([
    'success'      => true,
    'message'      => "Lote processado: {$reenviados} reenviado(s), {$falhas} falha(s).",
    'total'        => count($ids),
    'reenviados'   => $reenviados,
    'falhas'       => $falhas,
    'resultados'   => $resultados,
]);