<?php
/**
 * app/Services/EnvManager.php
 * Gerencia a edição de parte do arquivo .env, permitindo modificar APENAS
 * as opções de SMTP. Nunca toca em outras chaves (DB, MSSQL, JWT, etc.).
 *
 * Medidas de segurança:
 *  - Apenas chaves SMTP de uma whitelist fixa são lidas/gravadas.
 *  - A gravação exige confirmação com a senha do próprio usuário logado.
 *  - Valores são validados por tipo; rejeita quebras de linha e aspas duplas.
 */

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

class EnvManager
{
    /**
     * Whitelist de chaves editáveis. Tipo: string | int | email | enum:tls|ssl | bool.
     */
    private const CHAVES = [
        'SMTP_HOST'         => 'string',
        'SMTP_PORT'         => 'int',
        'SMTP_SECURE'       => 'enum:tls|ssl',
        'SMTP_USER'         => 'string',
        'SMTP_PASS'         => 'string',
        'SMTP_FROM'         => 'email',
        'SMTP_FROM_NAME'    => 'string',
        'CC_FIXO'           => 'email-opt',
        'ENVIO_EMAIL_COPIA' => 'bool',
        'SMTP_TIMEOUT'      => 'int',
        'WORKER_INTERVALO_SEGUNDOS' => 'int',
        'ALLOWED_EXTENSIONS' => 'list-ext',
    ];

    public static function caminhoArquivo(): string
    {
        return BASE_PATH . '/.env';
    }

    /**
     * Lê os valores atuais das chaves SMTP do .env.
     *
     * @return array<string,string> valores (SMTP_PASS em claro, para uso interno)
     */
    public static function ler(): array
    {
        $valores = array_fill_keys(array_keys(self::CHAVES), '');

        $arquivo = self::caminhoArquivo();
        if (is_file($arquivo)) {
            foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
                $linha = trim($linha);
                if ($linha === '' || $linha[0] === '#' || strpos($linha, '=') === false) {
                    continue;
                }
                [$chave, $valor] = explode('=', $linha, 2);
                $chave = trim($chave);
                if (array_key_exists($chave, self::CHAVES)) {
                    $valores[$chave] = self::limparValor(trim($valor));
                }
            }
        }

        return $valores;
    }

    /**
     * Valida e grava as chaves SMTP no .env.
     *
     * @param array  $novos            valores enviados (apenas chaves da whitelist são usadas)
     * @param string $senhaConfirmacao senha do usuário logado (autorização)
     * @param int    $userId           id do usuário logado
     *
     * @return array ['ok' => bool, 'erro' => ?string]
     */
    public static function salvar(array $novos, string $senhaConfirmacao, int $userId): array
    {
        // 1) Autorização: conferir a senha do usuário logado
        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id AND ativo = 1 LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $reg = $stmt->fetch();
        if (!$reg || !password_verify($senhaConfirmacao, (string) ($reg['senha'] ?? ''))) {
            return ['ok' => false, 'erro' => 'Senha de confirmação incorreta.'];
        }

        // 2) Valores atuais (para preservar SMTP_PASS vazio e detectar padrões)
        $atuais = self::ler();

        // 3) Validar e normalizar cada chave da whitelist
        $normalizados = [];
        foreach (self::CHAVES as $chave => $tipo) {
            $valor = isset($novos[$chave]) ? trim((string) $novos[$chave]) : '';

            // SMTP_PASS: vazio mantém o valor atual
            if ($chave === 'SMTP_PASS' && $valor === '') {
                $normalizados[$chave] = $atuais[$chave];
                continue;
            }

            $validacao = self::validar($chave, $tipo, $valor);
            if ($validacao !== null) {
                return ['ok' => false, 'erro' => $validacao];
            }

            $normalizados[$chave] = self::normalizar($chave, $tipo, $valor);
        }

        // 4) Reescrever o .env preservando o restante do arquivo
        $arquivo = self::caminhoArquivo();
        if (!is_file($arquivo)) {
            return ['ok' => false, 'erro' => 'Arquivo .env não encontrado.'];
        }

        $linhas = file($arquivo, FILE_IGNORE_NEW_LINES);
        if ($linhas === false) {
            return ['ok' => false, 'erro' => 'Não foi possível ler o arquivo .env.'];
        }

        $encontradas = array_fill_keys(array_keys(self::CHAVES), false);
        $novasLinhas = [];

        foreach ($linhas as $linha) {
            $chave = self::chaveDaLinha($linha);
            if ($chave !== null && array_key_exists($chave, self::CHAVES)) {
                $novasLinhas[] = $chave . '=' . self::formatarValor($normalizados[$chave]);
                $encontradas[$chave] = true;
            } else {
                $novasLinhas[] = $linha;
            }
        }

        // Anexar chaves que ainda não existiam no arquivo
        foreach (self::CHAVES as $chave => $tipo) {
            if (!$encontradas[$chave]) {
                $novasLinhas[] = $chave . '=' . self::formatarValor($normalizados[$chave]);
            }
        }

        // Backup + escrita atômica (arquivo temporário + rename)
        $backup = $arquivo . '.bak';
        @copy($arquivo, $backup);

        $tmp = $arquivo . '.tmp';
        $conteudo = implode("\n", $novasLinhas) . "\n";

        if (file_put_contents($tmp, $conteudo, LOCK_EX) === false) {
            return ['ok' => false, 'erro' => 'Falha ao gravar o arquivo .env.'];
        }

        if (!@rename($tmp, $arquivo)) {
            @unlink($tmp);
            return ['ok' => false, 'erro' => 'Falha ao substituir o arquivo .env.'];
        }

        return ['ok' => true, 'erro' => null];
    }

    private static function chaveDaLinha(string $linha): ?string
    {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || strpos($linha, '=') === false) {
            return null;
        }
        [$chave] = explode('=', $linha, 2);
        $chave = trim($chave);
        if ($chave === '' || !preg_match('/^[A-Z0-9_]+$/', $chave)) {
            return null;
        }
        return $chave;
    }

    private static function limparValor(string $valor): string
    {
        if (strlen($valor) >= 2) {
            $first = $valor[0];
            $last  = $valor[strlen($valor) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($valor, 1, -1);
            }
        }
        return $valor;
    }

    /**
     * Retorna null se válido, ou mensagem de erro.
     */
    private static function validar(string $chave, string $tipo, string $valor): ?string
    {
        if (strpos($valor, "\n") !== false || strpos($valor, "\r") !== false) {
            return "O campo {$chave} não pode conter quebras de linha.";
        }
        if (strpos($valor, '"') !== false) {
            return "O campo {$chave} não pode conter aspas duplas.";
        }

        switch ($tipo) {
            case 'string':
                return null;
            case 'int':
                if (!preg_match('/^\d+$/', $valor)) {
                    return "O campo {$chave} deve ser um número.";
                }
                $num = (int) $valor;
                if ($chave === 'SMTP_TIMEOUT') {
                    if ($num < 1 || $num > 600) {
                        return "O campo {$chave} deve estar entre 1 e 600 segundos.";
                    }
                } elseif ($chave === 'WORKER_INTERVALO_SEGUNDOS') {
                    if ($num < 0 || $num > 120) {
                        return "O campo {$chave} deve estar entre 0 e 120 segundos.";
                    }
                } elseif ($num < 1 || $num > 65535) {
                    return "O campo {$chave} deve estar entre 1 e 65535.";
                }
                return null;
            case 'enum:tls|ssl':
                if (!in_array($valor, ['tls', 'ssl'], true)) {
                    return "O campo {$chave} deve ser 'tls' ou 'ssl'.";
                }
                return null;
            case 'email':
                if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    return "O campo {$chave} deve ser um e-mail válido.";
                }
                return null;
            case 'email-opt':
                if ($valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    return "O campo {$chave} deve ser um e-mail válido (ou vazio).";
                }
                return null;
            case 'bool':
                if (!in_array($valor, ['1', '0', 'true', 'false'], true)) {
                    return "O campo {$chave} deve ser true ou false.";
                }
                return null;
            case 'list-ext':
                if ($valor === '') {
                    return "O campo {$chave} não pode ficar vazio.";
                }
                foreach (explode(',', $valor) as $ext) {
                    $ext = trim($ext);
                    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $ext)) {
                        return "O campo {$chave} deve ser uma lista de extensões separadas por vírgula (ex.: pdf,xlsx,xls).";
                    }
                }
                return null;
        }

        return null;
    }

    private static function normalizar(string $chave, string $tipo, string $valor): string
    {
        if ($tipo === 'int') {
            return (string) (int) $valor;
        }
        if ($tipo === 'bool') {
            return in_array(strtolower($valor), ['1', 'true'], true) ? 'true' : 'false';
        }
        if ($tipo === 'email' || $tipo === 'email-opt') {
            return mb_strtolower($valor);
        }
        if ($tipo === 'list-ext') {
            $partes = array_map('trim', explode(',', $valor));
            $partes = array_map('strtolower', $partes);
            return implode(',', $partes);
        }
        return $valor;
    }

    private static function formatarValor(string $valor): string
    {
        if ($valor === '') {
            return '';
        }
        if (preg_match('/[\s#\'\\\\=]/', $valor)) {
            return '"' . $valor . '"';
        }
        return $valor;
    }
}