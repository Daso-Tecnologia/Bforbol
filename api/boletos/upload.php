<?php
/**
 * api/boletos/upload.php
 * POST protegido - upload de PDFs de boletos via multipart.
 * Salva os arquivos em storage/enviar (equivale à pasta \enviar do legado).
 */

declare(strict_types=1);

require_once __DIR__ . '/../auth/validar_jwt.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Services/BoletoReader.php';

use App\Services\BoletoReader;

header('Content-Type: application/json');

validarJWT();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (empty($_FILES['boletos'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
    exit;
}

if (!is_dir(UPLOAD_PATH)) {
    @mkdir(UPLOAD_PATH, 0775, true);
}

// Normalizar estrutura $_FILES (arquivo único ou múltiplos)
if (is_array($_FILES['boletos']['name'])) {
    $arquivos = [];
    foreach ($_FILES['boletos']['name'] as $i => $nome) {
        $arquivos[] = [
            'name'     => $nome,
            'type'     => $_FILES['boletos']['type'][$i],
            'tmp_name' => $_FILES['boletos']['tmp_name'][$i],
            'error'    => $_FILES['boletos']['error'][$i],
            'size'     => $_FILES['boletos']['size'][$i],
        ];
    }
} else {
    $arquivos = [$_FILES['boletos']];
}

$sucesso = 0;
$erros = [];

foreach ($arquivos as $arquivo) {
    $nome = basename(str_replace('\\', '/', (string) ($arquivo['name'] ?? '')));

    // Validar extensão conforme ALLOWED_EXTENSIONS (config .env)
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $permitidas = array_map('trim', explode(',', strtolower(ALLOWED_EXTENSIONS)));
    if (!in_array($ext, $permitidas)) {
        $erros[] = "{$nome}: extensão .{$ext} não permitida (permitidas: " . implode(', ', $permitidas) . ")";
        continue;
    }

    // Validar erro de upload
    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $erros[] = "{$nome}: erro no upload (" . ($arquivo['error'] ?? 'desconhecido') . ')';
        continue;
    }

    // Validar tamanho
    if (($arquivo['size'] ?? 0) > UPLOAD_MAX_SIZE) {
        $erros[] = "{$nome}: excede o tamanho máximo de " . round(UPLOAD_MAX_SIZE / 1048576, 1) . 'MB';
        continue;
    }

    // Validar conteúdo real: o arquivo deve ser um PDF (assinatura "%PDF-")
    $fh = ($arquivo['tmp_name'] ?? '') !== '' ? @fopen($arquivo['tmp_name'], 'rb') : false;
    $magic = $fh ? fread($fh, 5) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($magic !== '%PDF-') {
        $erros[] = "{$nome}: conteúdo inválido (não é um PDF)";
        continue;
    }

    // Validar regra do legado: extrair COD_FOR (posição 7-16)
    $codFor = BoletoReader::extrairCodFor($nome);
    if (!$codFor) {
        $erros[] = "{$nome}: nome não contém código do fornecedor na posição 7-16 (ex.: FCOMP-0000000001-....PDF)";
        continue;
    }

    // Verificar duplicidade: o mesmo boleto (nome original) já foi enviado antes?
    // Evita reenviar a mesma cobrança duas vezes (risco de duplicidade).
    $stmtDup = getConnection()->prepare(
        "SELECT id FROM log_upload WHERE arquivo_origem = :origem LIMIT 1"
    );
    $stmtDup->execute(['origem' => $nome]);
    if ($stmtDup->fetch()) {
        $erros[] = "{$nome}: este boleto já foi enviado anteriormente (registro em log_upload). Evite duplicidade de cobrança.";
        continue;
    }

    // Nome único no storage para evitar colisões
    $destino = UPLOAD_PATH . '/' . date('YmdHis') . '_' . $nome;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        $erros[] = "{$nome}: falha ao mover o arquivo para o servidor";
        continue;
    }

    // Registrar no log de upload (auditoria)
    dbExecute(
        'INSERT INTO log_upload (cod_for, arquivo_origem, arquivo_destino, tamanho, status)
         VALUES (:cod_for, :origem, :destino, :tamanho, :status)',
        [
            'cod_for' => $codFor,
            'origem'  => $nome,
            'destino' => $destino,
            'tamanho' => (int) $arquivo['size'],
            'status'  => 'pendente',
        ]
    );

    $sucesso++;
}

if ($sucesso === 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum arquivo foi aceito',
        'erros'   => $erros,
    ]);
    exit;
}

echo json_encode([
    'success'        => true,
    'message'        => "Upload concluído: {$sucesso} arquivo(s) aceito(s), " . count($erros) . " com erro.",
    'arquivos_aceitos' => $sucesso,
    'erros'          => $erros,
]);