<?php
/**
 * api/boletos/enviar_fornecedor.php
 * POST protegido (completo) - envia de uma só vez todos os boletos pendentes
 * da pasta do fornecedor (storage/boleto/<FAN>-<COD>), somente para aquele
 * fornecedor (For + todos os e-mails cadastrados em CC).
 *
 * Regras:
 *  - Se a pasta do fornecedor não tiver arquivos, retorna 409 (botão desabilitado).
 *  - Se os arquivos já foram enviados antes para o mesmo fornecedor, alerta sobre
 *    o risco de duplicidade e só envia se o usuário confirmar (forcar=1).
 *  - Em sucesso, move os PDFs para a subpasta "enviado" e grava o log.
 *  - Em falha, grava o log como 'erro' e NAO move os arquivos (sem reenvio).
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

$fornecedorId = isset($input['fornecedor_id']) ? (int) $input['fornecedor_id'] : 0;
$forcar       = isset($input['forcar']) ? (int) (bool) $input['forcar'] : 0;

if ($fornecedorId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'fornecedor_id é obrigatório']);
    exit;
}

$pdo = getConnection();

// Buscar fornecedor
$stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $fornecedorId]);
$fornecedor = $stmt->fetch();

if (!$fornecedor) {
    http_response_code(404);
    echo json_encode(['error' => 'Fornecedor não encontrado']);
    exit;
}

if ((int) $fornecedor['ativo'] !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Fornecedor inativo. Não é possível enviar boletos.']);
    exit;
}

if (trim((string) ($fornecedor['email_for'] ?? '')) === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Fornecedor sem e-mail principal (email_for) cadastrado.']);
    exit;
}

$pastaCliente = BoletoReader::obterPastaCliente($fornecedor);

// Listar arquivos pendentes (qualquer formato, fora da subpasta "enviado")
$arquivosPendentes = BoletoReader::listarArquivosDaPasta($pastaCliente);

if (count($arquivosPendentes) === 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Não há documentos pendentes para este fornecedor']);
    exit;
}

$anexos = array_map(fn($a) => $pastaCliente . '/' . $a, $arquivosPendentes);

// Montar assunto conforme o legado
$nfs = [];
foreach ($arquivosPendentes as $arquivo) {
    $nf = BoletoReader::extrairNF($arquivo);
    if ($nf) $nfs[] = $nf;
}
$assunto = BoletoReader::montarAssunto($fornecedor['fan_for'], $fornecedor['cod_for'], array_unique($nfs));

// --- Alerta de duplicidade -------------------------------------------------
// Se já existir um envio 'enviado' para este fornecedor com pelo menos um dos
// arquivos atuais, exige confirmação antes de reenviar (decisão do usuário).
if (!$forcar) {
    $jaEnviados = [];
    $stmt = $pdo->prepare(
        "SELECT anexos FROM envios_log
         WHERE fornecedor_id = :fornecedor_id AND estado = 'enviado'"
    );
    $stmt->execute(['fornecedor_id' => $fornecedorId]);

    foreach ($stmt->fetchAll() as $reg) {
        $anexosAntigos = json_decode($reg['anexos'] ?? '[]', true) ?: [];
        foreach ($anexosAntigos as $antigo) {
            $nomeAntigo = basename((string) $antigo);
            if (!in_array($nomeAntigo, $jaEnviados, true) && in_array($nomeAntigo, $arquivosPendentes, true)) {
                $jaEnviados[] = $nomeAntigo;
            }
        }
    }

    if (count($jaEnviados) > 0) {
        http_response_code(200);
        echo json_encode([
            'success'          => false,
            'precisa_confirmacao' => true,
            'message'          => 'Atenção: os arquivos '
                . implode(', ', $jaEnviados)
                . ' já foram enviados anteriormente para este fornecedor. Reenviar pode gerar duplicidade.',
            'arquivos_duplicados' => $jaEnviados,
        ]);
        exit;
    }
}

// --- Envio -----------------------------------------------------------------
try {
    $mail = new MailSender();
    $resultado = $mail->enviarBoleto($fornecedor, $anexos, $assunto);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Falha no envio: ' . $e->getMessage()]);
    exit;
}

$novoEstado = $resultado['ok'] ? 'enviado' : 'erro';

// Registrar no envios_log
$stmt = $pdo->prepare(
    'INSERT INTO envios_log
        (fornecedor_id, cod_for, fan_for, destino, anexos, assunto, estado, mensagem_erro, data_tentativa)
     VALUES
        (:fornecedor_id, :cod_for, :fan_for, :destino, :anexos, :assunto, :estado, :mensagem_erro, :data_tentativa)'
);
$stmt->execute([
    'fornecedor_id'  => $fornecedorId,
    'cod_for'        => $fornecedor['cod_for'],
    'fan_for'        => $fornecedor['fan_for'],
    'destino'        => $fornecedor['email_for'],
    'anexos'         => json_encode($anexos),
    'assunto'        => $assunto,
    'estado'         => $novoEstado,
    'mensagem_erro'  => $resultado['ok'] ? null : substr($resultado['mensagem'], 0, 1000),
    'data_tentativa' => date('Y-m-d H:i:s'),
]);

$envioId = (int) $pdo->lastInsertId();

if ($resultado['ok']) {
    // Mover para a subpasta "enviado" do cliente
    BoletoReader::moverParaEnviado($pastaCliente, $arquivosPendentes);

    echo json_encode([
        'success' => true,
        'message' => 'Boletos enviados com sucesso para ' . $fornecedor['fan_for']
            . ' (' . count($arquivosPendentes) . ' arquivo(s)).',
        'id'      => $envioId,
    ]);
    exit;
}

http_response_code(500);
echo json_encode([
    'success' => false,
    'message' => 'Falha no envio: ' . $resultado['mensagem'],
    'id'      => $envioId,
]);