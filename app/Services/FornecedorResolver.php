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
        self::$gateFalhou = false;
        $pdo = getConnection();

        // 1) Buscar no banco local
        $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE cod_for = :cod_for LIMIT 1");
        $stmt->execute(['cod_for' => $codFor]);
        $local = $stmt->fetch();

        if ($local) {
            // Só consulta o GATE quando o e-mail principal local está vazio (evita
            // 1 consulta por fornecedor a cada leitura; edições manuais nunca são tocadas).
            if (trim((string) ($local['email_for'] ?? '')) === '') {
                $r = self::sincronizarEmails($local, false);
                $rotulo = 'Fornecedor ' . $codFor . ' (' . trim((string) $local['fan_for']) . ')';
                if ($r['estado'] !== 'ok') {
                    self::$avisos[] = $rotulo . ': ' . $r['mensagem'];
                }
                if (($r['ativo_gate'] ?? null) === 0) {
                    self::$avisos[] = $rotulo . ': está INATIVO no GATE; isso não impede o envio (só o cadastro local manda).';
                }
            }

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

        // Separa e limpa os e-mails do GATE (ex.: "a@x.com ; b@x.com"): o primeiro é o
        // principal e os demais entram como cópias. Sujeira (">", espaços) é removida.
        $bruto = trim((string) ($mssql['email_for'] ?? ''));
        $emails = self::extrairEmails($bruto);
        for ($i = 0; $i < 6; $i++) {
            $mssql[$i === 0 ? 'email_for' : 'email_for_' . ($i + 1)] = $emails[$i] ?? '';
        }

        $rotulo = 'Fornecedor ' . $codFor . ' (' . trim((string) ($mssql['fan_for'] ?? '')) . ')';
        if ($bruto === '') {
            self::$avisos[] = $rotulo . ' cadastrado a partir do GATE SEM e-mail: o cadastro do GATE (wgfor) está sem e-mail.';
        } elseif ($emails === []) {
            self::$avisos[] = $rotulo . ' cadastrado SEM e-mail: o e-mail do GATE ("' . $bruto . '") não é um endereço válido.';
        } elseif (count($emails) > 1) {
            self::$avisos[] = $rotulo . ': o GATE tem ' . count($emails) . ' e-mails; o primeiro (' . $emails[0] . ') é o principal e os demais foram cadastrados como cópia.';
        }

        if (($mssql['ativo_gate'] ?? null) === 0) {
            self::$avisos[] = $rotulo . ': está INATIVO no GATE. Foi cadastrado como ativo e o envio segue normalmente (pode haver boletos de comissões antigas); se não deve receber mais, desative na tela Fornecedores.';
        }

        return self::registrar($mssql);
    }

    /** Avisos de cadastro acumulados durante a leitura (ver consumirAvisos). */
    private static array $avisos = [];

    /** true se a última chamada a resolver() não conseguiu consultar o GATE (rede/driver). */
    private static bool $gateFalhou = false;

    public static function gateFalhouNaUltimaBusca(): bool
    {
        return self::$gateFalhou;
    }

    /** Devolve e zera os avisos acumulados (GATE sem e-mail, e-mail inválido, GATE fora, etc.). */
    public static function consumirAvisos(): array
    {
        $a = self::$avisos;
        self::$avisos = [];
        return $a;
    }

    /**
     * Separa uma string de e-mails do GATE (separadores ; , espaço) em uma lista de
     * endereços válidos, em minúsculas e sem repetição. Ex.: "a@x.com ; b@x.com>" -> [a@x.com, b@x.com].
     */
    public static function extrairEmails(string $bruto): array
    {
        $lista = [];
        foreach (preg_split('/[;,\s]+/', $bruto) ?: [] as $parte) {
            $email = self::limparEmail($parte);
            if ($email !== '' && !in_array($email, $lista, true)) {
                $lista[] = $email;
            }
        }
        return $lista;
    }

    /**
     * Sincroniza os e-mails do fornecedor LOCAL com o GATE (wgfor).
     *
     * Regras (item 34: edição manual sempre vence):
     *  - Se o e-mail principal local está VAZIO e o GATE tem e-mail válido: o primeiro vira
     *    o principal e os demais entram nas cópias (2..6) que estiverem vazias, sem repetir.
     *  - Se o principal local está preenchido, NADA é sobrescrito. Com $comparar = true,
     *    informa em 'divergencias' os e-mails do GATE que não estão no cadastro local.
     *
     * Retorna ['estado' => ok|completado|gate_indisponivel|nao_encontrado|gate_sem_email|email_invalido,
     *          'mensagem' => texto explícito, 'gate_nome' => string, 'divergencias' => [...],
     *          'preenchidos' => [campo => valor]]
     */
    public static function sincronizarEmails(array $local, bool $comparar = false, ?PDO $pdo = null): array
    {
        $campos = ['email_for', 'email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'];
        $saida = ['estado' => 'ok', 'mensagem' => '', 'gate_nome' => '', 'divergencias' => [], 'preenchidos' => []];

        try {
            $dados = mssqlBuscarFornecedor((string) $local['cod_for']);
        } catch (\Throwable $e) {
            error_log('[FornecedorResolver] GATE indisponível ao sincronizar ' . $local['cod_for'] . ': ' . get_class($e));
            $saida['estado'] = 'gate_indisponivel';
            $saida['mensagem'] = 'não foi possível consultar o GATE agora; o e-mail não foi verificado. Tente novamente mais tarde.';
            return $saida;
        }

        if (!$dados) {
            $saida['estado'] = 'nao_encontrado';
            $saida['mensagem'] = 'não encontrado no cadastro (wgfor) do GATE.';
            return $saida;
        }

        $saida['gate_nome'] = trim((string) ($dados['fan_for'] ?? ''));
        $saida['ativo_gate'] = $dados['ativo_gate'] ?? null;
        $bruto = trim(corregirMojibakeCP437((string) ($dados['email_for'] ?? '')));
        $emailsGate = self::extrairEmails($bruto);

        $locais = [];
        foreach ($campos as $c) {
            $v = mb_strtolower(trim((string) ($local[$c] ?? '')));
            if ($v !== '') {
                $locais[$c] = $v;
            }
        }

        if (!isset($locais['email_for'])) {
            if ($emailsGate === []) {
                if ($bruto === '') {
                    $saida['estado'] = 'gate_sem_email';
                    $saida['mensagem'] = 'sem e-mail principal no cadastro local e o cadastro do GATE (wgfor) também está sem e-mail.';
                } else {
                    $saida['estado'] = 'email_invalido';
                    $saida['mensagem'] = 'sem e-mail principal no cadastro local e o e-mail do GATE ("' . $bruto . '") não é um endereço válido.';
                }
                return $saida;
            }

            $novos = [];
            $novos['email_for'] = $emailsGate[0];
            $jaUsados = array_values($locais);
            $jaUsados[] = $emailsGate[0];
            $extras = array_values(array_filter(array_slice($emailsGate, 1), static fn ($e) => !in_array($e, $jaUsados, true)));
            foreach (array_slice($campos, 1) as $c) {
                if (!isset($locais[$c]) && $extras !== []) {
                    $novos[$c] = array_shift($extras);
                }
            }

            $sets = [];
            $params = ['id' => (int) $local['id']];
            foreach ($novos as $c => $v) {
                $sets[] = "{$c} = :{$c}";
                $params[$c] = $v;
            }
            $pdo ??= getConnection();
            $pdo->prepare('UPDATE fornecedores SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

            $saida['estado'] = 'completado';
            $saida['preenchidos'] = $novos;
            $cop = array_slice($novos, 1);
            $saida['mensagem'] = 'e-mail principal completado a partir do GATE: ' . $novos['email_for']
                . ($cop ? ' (cópias: ' . implode(', ', $cop) . ')' : '') . '.';
            error_log('[FornecedorResolver] ' . $local['cod_for'] . ' ' . $saida['mensagem']);
            return $saida;
        }

        if ($comparar) {
            foreach ($emailsGate as $g) {
                if (!in_array($g, $locais, true)) {
                    $saida['divergencias'][] = ['campo' => 'email', 'local' => $locais['email_for'], 'gate' => $g];
                }
            }
            if ($saida['divergencias']) {
                $saida['mensagem'] = 'o GATE tem e-mail que não está no cadastro local ('
                    . implode(', ', array_column($saida['divergencias'], 'gate')) . '); o cadastro local foi mantido.';
            }
        }

        return $saida;
    }

    /**
     * Limpa caracteres que não fazem parte de um e-mail (vindos do GATE).
     * Retorna '' quando não há um e-mail válido reconhecível.
     */
    public static function limparEmail(string $email): string
    {
        $email = trim($email);

        // Ex.: "fornecedor@exemplo.com.br>" -> remove sufixo após '>'
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
            self::$gateFalhou = true;
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