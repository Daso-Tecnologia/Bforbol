<?php
/**
 * api/boletos/ler.php
 * POST protegido (completo) - dispara a leitura da pasta de entrada.
 * Reproduz o "Ler boletos" do legado:
 *   1. Varre storage/enviar
 *   2. Extrai COD_FOR (posição 7-16)
 *   3. Resolve fornecedor (MySQL ou MSSQL)
 *   4. Copia para a pasta do cliente
 *   5. Cria fila de envios (1 lote por fornecedor com arquivos)
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';

use App\Services\BoletoReader;

header('Content-Type: application/json');

validarJWTCompleto();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// 1) Processar a entrada: agrupar por fornecedor e copiar para pastas de cliente
$resultado = BoletoReader::processarEntrada();

$pdo = getConnection();
$lotesCriados = 0;
$detalhes = [];

foreach ($resultado['lotes'] as $lote) {
    $fornecedorId = $lote['fornecedor_id'];
    $codFor       = $lote['cod_for'];
    $fanFor       = $lote['fan_for'];
    $arquivos     = $lote['arquivos'];
    $arquivosEntrada = $lote['arquivos_entrada'] ?? $arquivos;

    $pastaCliente = BoletoReader::obterPastaCliente([
        'fan_for' => $fanFor,
        'cod_for' => $codFor,
    ]);

    // Caminhos completos dos PDFs na pasta do cliente
    $anexos = [];
    foreach ($arquivos as $arquivo) {
        $anexos[] = $pastaCliente . '/' . $arquivo;
    }

    // Extrair NFs do assunto (regra do legado)
    $nfs = [];
    foreach ($arquivos as $arquivo) {
        $nf = BoletoReader::extrairNF($arquivo);
        if ($nf) $nfs[] = $nf;
    }

    $assunto = BoletoReader::montarAssunto($fanFor, $codFor, array_unique($nfs));

    // Buscar e-mail principal do fornecedor
    $stmtEmail = $pdo->prepare("SELECT email_for FROM fornecedores WHERE id = :id LIMIT 1");
    $stmtEmail->execute(['id' => $fornecedorId]);
    $destino = $stmtEmail->fetchColumn() ?: '';

    // Registrar lote pendente na fila de envios (1 por fornecedor)
    $stmt = $pdo->prepare(
        'INSERT INTO envios_log
            (fornecedor_id, cod_for, fan_for, destino, anexos, assunto, estado, data_tentativa)
         VALUES
            (:fornecedor_id, :cod_for, :fan_for, :destino, :anexos, :assunto, :estado, :data_tentativa)'
    );
    $stmt->execute([
        'fornecedor_id' => $fornecedorId,
        'cod_for'       => $codFor,
        'fan_for'       => $fanFor,
        'destino'       => $destino,
        'anexos'        => json_encode($anexos),
        'assunto'       => $assunto,
        'estado'        => 'pendente',
        'data_tentativa'=> date('Y-m-d H:i:s'),
    ]);

    $lotesCriados++;
    $detalhes[] = [
        'fornecedor_id' => $fornecedorId,
        'cod_for'       => $codFor,
        'fan_for'       => $fanFor,
        'arquivos'      => $arquivos,
        'assunto'       => $assunto,
    ];

    // Remover da pasta de entrada
    BoletoReader::removerDaEntrada($arquivosEntrada);
}

$mensagem = "Leitura concluída: {$lotesCriados} lote(s) de envio criado(s).";
if (count($resultado['gate_indisponivel'] ?? []) > 0) {
    $mensagem .= ' GATE (MSSQL) indisponível: ' . count($resultado['gate_indisponivel']) . ' arquivo(s) não processado(s).';
}
if (count($resultado['sem_email'] ?? []) > 0) {
    $mensagem .= ' Sem e-mail principal (filas NÃO criadas — cadastre o e-mail no fornecedor e volte a Ler): ' . count($resultado['sem_email']) . ' arquivo(s).';
}

// Duplicados: já existiam na pasta do cliente (raiz ou enviado) — foram ignorados
// e removidos da entrada para não gerar cobrança duplicada.
$duplicados = $resultado['duplicados'] ?? [];
if (count($duplicados) > 0) {
    $nomesEntrada = array_map(fn($d) => $d['nome_entrada'] ?? $d, $duplicados);
    BoletoReader::removerDaEntrada($nomesEntrada);
    $mensagem .= ' Duplicados ignorados (já existiam): ' . count($duplicados) . ' arquivo(s).';
}

echo json_encode([
    'success'          => true,
    'message'          => $mensagem,
    'lotes_criados'    => $lotesCriados,
    'detalhes'         => $detalhes,
    'sem_fornecedor'   => $resultado['sem_fornecedor'],
    'nao_ativos'       => $resultado['nao_ativos'],
    'nao_wg_ven'       => $resultado['nao_wg_ven'] ?? [],
    'gate_indisponivel' => $resultado['gate_indisponivel'] ?? [],
    'sem_email'        => $resultado['sem_email'] ?? [],
    'acima_limite'     => $resultado['acima_limite'] ?? [],
    'duplicados'       => $duplicados,
]);