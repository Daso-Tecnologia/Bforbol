<?php
/**
 * cron/worker_envio.php
 * Worker de envio - processa a fila de envios pendentes.
 *
 * Uso (CLI):
 *   php cron/worker_envio.php [--limite=20]
 *
 * Cron (exemplo - a cada minuto):
 *   * * * * * php /caminho/para/php_boletos/cron/worker_envio.php >> /var/log/boleto_worker.log 2>&1
 *
 * Lógica (replica o legado):
 *   1. Busca registros 'pendente' na tabela envios_log (limite N)
 *   2. Envia o e-mail com os PDFs anexos (PHPMailer)
 *   3. Em sucesso: move PDFs para a subpasta "enviado" e marca como 'enviado'
 *   4. Em falha: marca como 'erro' com a mensagem (NÃO move os PDFs)
 *   5. Respeita o intervalo entre envios (evita saturar o SMTP)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito à linha de comando');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/BoletoReader.php';
require_once __DIR__ . '/../app/Services/MailSender.php';
require_once __DIR__ . '/../app/Services/FornecedorResolver.php';

use App\Services\BoletoReader;
use App\Services\MailSender;

// Parâmetros
$limite = WORKER_LIMITE;
$intervalo = WORKER_INTERVALO_SEGUNDOS;

foreach ($argv as $arg) {
    if (preg_match('/^--limite=(\d+)$/', $arg, $m)) {
        $limite = (int) $m[1];
    }
    if (preg_match('/^--intervalo=(\d+)$/', $arg, $m)) {
        $intervalo = (int) $m[1];
    }
}

$pdo = getConnection();
$mail = new MailSender();

$inicio = microtime(true);
$processados = 0;
$enviados = 0;
$falhas = 0;

echo '[' . date('Y-m-d H:i:s') . "] Iniciando worker (limite={$limite}, intervalo={$intervalo}s)\n";

// Bloquear execução simultânea (evita duplo envio)
$lockFile = STORAGE_PATH . '/worker.lock';
$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle) {
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        echo '[' . date('Y-m-d H:i:s') . "] Worker já está em execução. Abortando.\n";
        exit(0);
    }
}

try {
    // Buscar envios pendentes
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

    foreach ($pendentes as $envio) {
        $processados++;

        $fornecedor = null;
        if (!empty($envio['fornecedor_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => (int) $envio['fornecedor_id']]);
            $fornecedor = $stmt->fetch() ?: null;
        }

        // Fornecedor pode ter sido desativado - manter envio
        if (!$fornecedor) {
            echo '  - Envio #' . $envio['id'] . ': fornecedor não encontrado, marcando erro.' . "\n";
            $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'erro', mensagem_erro = :msg, data_tentativa = :data WHERE id = :id");
            $stmt->execute([
                'msg'  => 'Fornecedor não encontrado',
                'data' => date('Y-m-d H:i:s'),
                'id'   => (int) $envio['id'],
            ]);
            $falhas++;
            continue;
        }

        // Anexos salvos em JSON (caminhos completos)
        $anexos = json_decode($envio['anexos'] ?? '[]', true) ?: [];
        if (!is_array($anexos)) $anexos = [];

        // Filtrar apenas arquivos existentes
        $anexosExistentes = array_values(array_filter($anexos, 'is_file'));

        if (count($anexosExistentes) === 0) {
            echo '  - Envio #' . $envio['id'] . ': nenhum anexo encontrado, marcando erro.' . "\n";
            $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'erro', mensagem_erro = :msg, data_tentativa = :data WHERE id = :id");
            $stmt->execute([
                'msg'  => 'Nenhum anexo encontrado no armazenamento',
                'data' => date('Y-m-d H:i:s'),
                'id'   => (int) $envio['id'],
            ]);
            $falhas++;
            continue;
        }

        echo '  - Enviando #' . $envio['id'] . ' para ' . ($fornecedor['fan_for'] ?? '') . ' (' . count($anexosExistentes) . ' anexo(s))... ';

        $resultado = $mail->enviarBoleto($fornecedor, $anexosExistentes, $envio['assunto'] ?? '');

        if ($resultado['ok']) {
            // Sucesso: mover para a pasta "enviado"
            $pastaCliente = BoletoReader::obterPastaCliente($fornecedor);
            $nomes = array_map('basename', $anexosExistentes);
            BoletoReader::moverParaEnviado($pastaCliente, $nomes);

            $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'enviado', mensagem_erro = NULL, data_tentativa = :data WHERE id = :id");
            $stmt->execute(['data' => date('Y-m-d H:i:s'), 'id' => (int) $envio['id']]);

            echo "OK\n";
            $enviados++;
        } else {
            // Falha: não move, registra erro
            $stmt = $pdo->prepare("UPDATE envios_log SET estado = 'erro', mensagem_erro = :msg, data_tentativa = :data WHERE id = :id");
            $stmt->execute([
                'msg'  => substr($resultado['mensagem'], 0, 1000),
                'data' => date('Y-m-d H:i:s'),
                'id'   => (int) $envio['id'],
            ]);

            echo "ERRO: " . $resultado['mensagem'] . "\n";
            $falhas++;
        }

        // Intervalo entre envios (respeita wenvio/reenvio do legado)
        if ($intervalo > 0) {
            sleep($intervalo);
        }
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

$duracao = round(microtime(true) - $inicio, 2);
echo '[' . date('Y-m-d H:i:s') . "] Concluído: {$processados} processado(s), {$enviados} enviado(s), {$falhas} falha(s) em {$duracao}s.\n";