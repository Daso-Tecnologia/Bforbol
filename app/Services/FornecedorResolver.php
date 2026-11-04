<?php
/**
 * app/Services/FornecedorResolver.php
 * Busca o fornecedor no banco local (MySQL). Se não existir, consulta o
 * repositório corporativo (MSSQL - tabela wgfor) e registra automaticamente.
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mssql.php';

class FornecedorResolver
{
    /**
     * Resolve um fornecedor pelo código. Prioriza o MySQL local e, caso não
     * encontre, consulta o MSSQL (GATE) e registra no banco local.
     *
     * Quando o fornecedor já existe localmente, confere na wgfor (GATE) se o
     * e-mail principal ficou desatualizado e atualiza. Se o GATE estiver
     * indisponível, mantém o cadastro local sem interromper o fluxo.
     *
     * Retorna os dados do fornecedor ou null.
     */
    public static function resolver(string $codFor): ?array
    {
        $pdo = getConnection();

        // 1) Buscar no banco local
        $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE cod_for = :cod_for LIMIT 1");
        $stmt->execute(['cod_for' => $codFor]);
        $local = $stmt->fetch();

        if ($local) {
            self::atualizarEmailSeDesatualizado($local);

            // Reler após possível atualização
            $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE cod_for = :cod_for LIMIT 1");
            $stmt->execute(['cod_for' => $codFor]);
            return $stmt->fetch() ?: $local;
        }

        // 2) Não achou localmente -> consultar MSSQL e registrar
        $mssql = self::buscarNoMssql($codFor);

        if (!$mssql) {
            return null;
        }

        // Limpar e-mails sujos vindos do GATE (caracteres estranhos)
        $mssql['email_for'] = self::limparEmail((string) ($mssql['email_for'] ?? ''));

        return self::registrar($mssql);
    }

    /**
     * Regra de negocio atual: os e-mails os controla o usuário manualmente.
     * O GATE (wgfor) só fornece o e-mail principal; só se completa desde el
     * GATE quando o e-mail local está VACÍO (NUNCA sobrescribe uma edição manual).
     */
    private static function atualizarEmailSeDesatualizado(array $local): void
    {
        $emailAtual = mb_strtolower(trim((string) ($local['email_for'] ?? '')));
        if ($emailAtual !== '') {
            return;
        }

        try {
            $dados = mssqlBuscarFornecedor((string) $local['cod_for']);

            if (!$dados) {
                return;
            }

            $emailNovo = self::limparEmail((string) ($dados['email_for'] ?? ''));

            if ($emailNovo !== '') {
                $pdo = getConnection();
                $stmt = $pdo->prepare("UPDATE fornecedores SET email_for = :email WHERE id = :id");
                $stmt->execute(['email' => $emailNovo, 'id' => (int) $local['id']]);
                error_log('[FornecedorResolver] E-mail do fornecedor ' . $local['cod_for'] . ' completado do GATE: ' . $emailNovo);
            }
        } catch (\Throwable $e) {
            error_log('[FornecedorResolver] Completado de e-mail ignorado (MSSQL indisponível): ' . $e->getMessage());
        }
    }

    /**
     * Limpa caracteres que não fazem parte de um e-mail (vindos do GATE).
     * Retorna '' quando não há um e-mail válido reconhecível.
     */
    private static function limparEmail(string $email): string
    {
        $email = trim($email);

        // Ex.: "contato@exemplo.com.br>" -> remove sufixo após '>'
        if (preg_match('/([A-Za-z0-9._%+\-]+@[A-Za-z0-9._\-]+\.[A-Za-z]{2,})/', $email, $m)) {
            $email = $m[1];
        }

        $email = mb_strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Busca o fornecedor no MSSQL (tabela wgfor).
     */
    public static function buscarNoMssql(string $codFor): ?array
    {
        try {
            $dados = mssqlBuscarFornecedor($codFor);

            if (!$dados || empty($dados['fan_for'])) {
                return null;
            }

            // Corrigir mojibake de página de código (CP437/DOS) vindo do GATE:
            // ex.: "HOTÉIS" chegava como "HOT╔IS". Reconverte os glifos
            // "fantasma" de volta à letra correta.
            foreach (['fan_for', 'raz_for', 'email_for', 'email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'] as $campo) {
                $dados[$campo] = corregirMojibakeCP437((string) ($dados[$campo] ?? ''));
            }

            return $dados;
        } catch (\Throwable $e) {
            // MSSQL indisponível: registra o erro no log de envios
            error_log('[FornecedorResolver] MSSQL erro: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Registra um novo fornecedor no banco local (MySQL) a partir dos dados do MSSQL.
     */
    public static function registrar(array $dados): ?array
    {
        $pdo = getConnection();

        $stmt = $pdo->prepare(
            'INSERT INTO fornecedores
                (cod_for, fan_for, raz_for, dir_bol, email_for, email_for_2, email_for_3,
                 email_for_4, email_for_5, email_for_6, ativo)
             VALUES
                (:cod_for, :fan_for, :raz_for, :dir_bol, :email_for, :email_for_2, :email_for_3,
                 :email_for_4, :email_for_5, :email_for_6, 1)
             ON DUPLICATE KEY UPDATE
                fan_for = VALUES(fan_for),
                raz_for = VALUES(raz_for),
                email_for = VALUES(email_for),
                email_for_2 = VALUES(email_for_2),
                email_for_3 = VALUES(email_for_3),
                email_for_4 = VALUES(email_for_4),
                email_for_5 = VALUES(email_for_5),
                email_for_6 = VALUES(email_for_6)'
        );

        $stmt->execute([
            'cod_for'     => $dados['cod_for'],
            'fan_for'     => $dados['fan_for'],
            'raz_for'     => $dados['raz_for'] ?? $dados['fan_for'],
            'dir_bol'     => BOLETO_PATH . '/' . preg_replace('/[^A-Za-z0-9 _.-]/', '', $dados['fan_for']) . '-' . $dados['cod_for'],
            'email_for'   => self::limparEmail((string) ($dados['email_for'] ?? '')),
            'email_for_2' => self::limparEmail((string) ($dados['email_for_2'] ?? '')),
            'email_for_3' => self::limparEmail((string) ($dados['email_for_3'] ?? '')),
            'email_for_4' => self::limparEmail((string) ($dados['email_for_4'] ?? '')),
            'email_for_5' => self::limparEmail((string) ($dados['email_for_5'] ?? '')),
            'email_for_6' => self::limparEmail((string) ($dados['email_for_6'] ?? '')),
        ]);

        // Retornar o registro recém-criado
        $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE cod_for = :cod_for LIMIT 1");
        $stmt->execute(['cod_for' => $dados['cod_for']]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}