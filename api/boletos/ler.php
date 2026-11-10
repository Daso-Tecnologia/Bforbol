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

// Leitura em partes: cada chamada HTTP examina arquivos por no máximo ~40 s (o
// balanceador e o Apache cortam em 60 s) e devolve um cursor ("ultimo_arquivo").
// O front repete a chamada até "incompleto" = false.
// "ids_sessao" = ids de envios_log criados nas chamadas anteriores desta mesma
// leitura: lotes do mesmo fornecedor são unidos (continua 1 e-mail por fornecedor).
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$aposArquivo = isset($input['apos_arquivo']) && is_string($input['apos_arquivo']) ? $input['apos_arquivo'] : null;
$idsSessao = isset($input['ids_sessao']) && is_array($input['ids_sessao'])
    ? array_values(array_filter(array_map('intval', $input['ids_sessao']), static fn (int $v): bool => $v > 0))
    : [];

set_time_limit(120);

// 1) Processar a entrada: agrupar por fornecedor e copiar para pastas de cliente
$resultado = BoletoReader::processarEntrada($aposArquivo, 40.0);

$pdo = getConnection();
$lotesCriados = 0;
$detalhes = [];
$idsCriados = [];

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

    // Já existe, nesta mesma leitura (chamadas anteriores), um envio PENDENTE deste
    // fornecedor? Então junta os anexos nele em vez de criar um segundo e-mail.
    $uniu = false;
    if ($idsSessao !== []) {
        $marcas = implode(',', array_fill(0, count($idsSessao), '?'));
        $stmtEx = $pdo->prepare(
            "SELECT id, anexos FROM envios_log
             WHERE fornecedor_id = ? AND estado = 'pendente' AND id IN ({$marcas}) LIMIT 1"
        );
        $stmtEx->execute(array_merge([$fornecedorId], $idsSessao));
        $existente = $stmtEx->fetch();
        if ($existente) {
            $anexosAntigos = json_decode((string) ($existente['anexos'] ?? '[]'), true);
            $anexosAntigos = is_array($anexosAntigos) ? $anexosAntigos : [];
            $todosAnexos = array_values(array_unique(array_merge($anexosAntigos, $anexos)));

            $nfsTodas = [];
            foreach ($todosAnexos as $caminho) {
                $nf = BoletoReader::extrairNF(basename((string) $caminho));
                if ($nf) $nfsTodas[] = $nf;
            }
            $assuntoUnido = BoletoReader::montarAssunto($fanFor, $codFor, array_unique($nfsTodas));

            $stmtUp = $pdo->prepare('UPDATE envios_log SET anexos = :anexos, assunto = :assunto WHERE id = :id');
            $stmtUp->execute([
                'anexos'  => json_encode($todosAnexos),
                'assunto' => $assuntoUnido,
                'id'      => (int) $existente['id'],
            ]);
            $uniu = true;
        }
    }

    // Registrar lote pendente na fila de envios (1 por fornecedor)
    if (!$uniu) {
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
        $idsCriados[] = (int) $pdo->lastInsertId();
        $lotesCriados++;
    }

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
    'avisos_cadastro'  => $resultado['avisos_cadastro'] ?? [],
    // Controle da leitura em partes (usado pelo front-end)
    'incompleto'       => (bool) ($resultado['incompleto'] ?? false),
    'ultimo_arquivo'   => $resultado['ultimo_arquivo'] ?? null,
    'examinados'       => (int) ($resultado['examinados'] ?? 0),
    'ids_criados'      => $idsCriados,
]);