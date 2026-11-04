<?php
/**
 * api/envios/enviar_todos.php
 * POST protegido (completo) - envia TODOS os envios pendentes da fila de uma vez.
 *
 * Para cada registro 'pendente' em envios_log:
 *   - busca o fornecedor e os PDFs (anexos salvos em JSON)
 *   - envia o e-mail via MailSender
 *   - sucesso: move os PDFs para a subpasta "enviado" e marca 'enviado'
 *   - falha: mantém 'pendente' com a mensagem de erro registrada, para novo envio
 *
 * Retorna resumo com total/enviados/falhas e detalhes por id.
 * (Substitui a necessidade de rodar o worker via terminal para envios simples.)
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
$limite = isset($input['limite']) ? max(1, (int) $input['limite']) : 500;

$pdo = getConnection();

// Buscar todos os envios pendentes
$stmt = $pdo->prepare(
    'SELECT * FROM envios_log
     WHERE estado = :estado
     ORDER BY id ASC
     LIMIT :limite'
);
$stmt->bindValue(':estado', 'pendente', PDO::PARAM_STR);
$stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
$stmt->execute();
$pendentes = $stmt->fetchAll();

if (count($pendentes) === 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Não há envios pendentes na fila.',
        'total'   => 0,
        'enviados' => 0,
        'falhas'  => 0,
        'resultados' => [],
    ]);
    exit;
}

$mail = new MailSender();

// Intervalo entre envios (segundos) - evita sobrecarregar o provedor SMTP.
$intervalo = WORKER_INTERVALO_SEGUNDOS;
// Processamento em lote pode demorar; não deixar o timeout do PHP interromper.
set_time_limit(0);

$resultados = [];
$enviados = 0;
$falhas = 0;

foreach ($pendentes as $envio) {
    try {
        $resultado = ['id' => (int) $envio['id'], 'ok' => false, 'mensagem' => ''];

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
        $resultado['mensagem'] = 'Não há documentos disponíveis para envio';
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
        $stmt->execute(['data' => date('Y-m-d H:i:s'), 'id' => (int) $envio['id']]);

        $resultado['ok'] = true;
        $resultado['mensagem'] = 'Enviado com sucesso';
        $enviados++;
    } else {
        // Falha: mantém 'pendente' e registra a mensagem (sem mover PDFs)
        $stmt = $pdo->prepare("UPDATE envios_log SET mensagem_erro = :msg, data_tentativa = :data WHERE id = :id");
        $stmt->execute([
            'msg'  => substr($envioResultado['mensagem'], 0, 1000),
            'data' => date('Y-m-d H:i:s'),
            'id'   => (int) $envio['id'],
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
    'success'    => true,
    'message'    => "Fila processada: {$enviados} enviado(s), {$falhas} falha(s).",
    'total'      => count($pendentes),
    'enviados'   => $enviados,
    'falhas'     => $falhas,
    'resultados' => $resultados,
]);