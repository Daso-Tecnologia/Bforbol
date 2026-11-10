<?php
/**
 * app/Services/BoletoReader.php
 * Lê os arquivos da pasta de entrada (storage/enviar), extrai o código do
 * fornecedor (regra do legado: caracteres 7-16 do nome do arquivo) e
 * prepara os lotes de envio.
 */

declare(strict_types=1);

namespace App\Services;

use App\Services\FornecedorResolver;
use PDO;

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mssql.php';
require_once __DIR__ . '/FornecedorResolver.php';

class BoletoReader
{
    /**
     * Extrai o COD_FOR do nome do arquivo.
     * Regra do legado: caracteres na posição 7 a 16 (0-indexado: 6 a 16).
     *
     * Exemplo: FCOMP-0000000001-0000000001A.PDF -> 0000000001
     */
    public static function extrairCodFor(string $nomeArquivo): ?string
    {
        $base = pathinfo($nomeArquivo, PATHINFO_FILENAME);

        // 1) Formato legado exato: código na posição 7-16 (ex.: FCOMP-0000000001-...)
        if (strlen($base) >= 16) {
            $codFor = substr($base, 6, 10);
            if (preg_match('/^[0-9]{10}$/', $codFor)) {
                return $codFor;
            }
        }

        // 2) Fallback robusto: primeiro bloco de 10 dígitos entre traços.

        //    Suporta o prefixo de data/hora adicionado no upload(storage/enviar).
        //    O código pode ser seguido de '-' (boleto), espaço (nota) ou fim da string.



        if (preg_match('/-(\d{10})(?:[-\s]|$)/', $base, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Detecta se o nome do arquivo contém referência de NF (Nota Fiscal).
     * Ex.: ...-0000000001A.PDF -> NF-0000000001
     */
    public static function extrairNF(string $nomeArquivo): ?string
    {
        $base = pathinfo($nomeArquivo, PATHINFO_FILENAME);

        // 1) Padrão do boleto (legado): ...-<5+ dígitos>[A-Z] no final
        if (preg_match('/-(\d{5,})[A-Z]?$/', $base, $m)) {
            return $m[1];
        }

        // 2) Nota fiscal (NFSE/FATURA): reconhece o nº da NFSE e o nº da FATURA.
        //    Retorna ambos quando presentes, ex.: "NFSE 28028 FATURA 4918".
        $nfse = null;
        $fatura = null;
        if (preg_match('/NFSE\s+(\d+)/i', $base, $mNfse)) {
            $nfse = $mNfse[1];
        }
        if (preg_match('/FATURA\s+(\d+)/i', $base, $mFat)) {
            $fatura = $mFat[1];
        }

        if ($nfse !== null || $fatura !== null) {
            $partes = [];
            if ($nfse !== null) $partes[] = 'NFSE ' . $nfse;
            if ($fatura !== null) $partes[] = 'FATURA ' . $fatura;
            return implode(' ', $partes);
        }

        return null;
    }

    /**
     * Gera o assunto padrão do e-mail conforme o legado.

     * Ex.: "Boleto/Comissão BVT BOURBON PONTA GROSSA - 0000002206 NF-0000000001"
     */
    public static function montarAssunto(string $fanFor, string $codFor, array $nfs): string
    {
        $assunto = 'Boleto/Comissão BVT ' . $fanFor . ' - ' . $codFor;

        if (count($nfs) > 0) {
            $descritores = array_map(function ($nf) {
                // Se já é um descritor completo (NFSE/FATURA), usa como está;
                // senão (nº da NF do boleto) prefixa "NF-".
                if (preg_match('/^NFSE\s|^FATURA\s/i', $nf)) {
                    return $nf;
                }
                return 'NF-' . $nf;
            }, $nfs);
            $assunto .= ' ' . implode(' ', $descritores);
        }

        return $assunto;
    }

    /**
     * Varre a pasta de entrada (storage/enviar) e lista TODOS os arquivos
     * (PDFs e outros tipos, conforme regra do legado — qualquer arquivo
     * na pasta de entrada é agrupado e anexado ao e-mail do fornecedor).
     * Ignora subpastas e arquivos ocultos/temporários.


     */
    public static function listarArquivosNaEntrada(): array
    {
        $dir = UPLOAD_PATH;

        if (!is_dir($dir)) {
            return [];
        }

        $arquivos = scandir($dir);
        $lista = [];

        foreach ($arquivos as $arquivo) {
            if ($arquivo === '.' || $arquivo === '..' || is_dir($dir . '/' . $arquivo)) {
                continue;
            }
            if ($arquivo[0] === '.' || preg_match('/\.(tmp|part|swp|lock)$/i', $arquivo)) {
                continue;
            }
            $lista[] = $arquivo;
        }

        sort($lista);
        return $lista;
    }

    /**
     * Varre a pasta de entrada (storage/enviar) e lista os PDFs.


     */
    public static function listarPdfsNaEntrada(): array
    {
        $dir = UPLOAD_PATH;

        if (!is_dir($dir)) {
            return [];
        }

        $arquivos = scandir($dir);
        $pdfs = [];

        foreach ($arquivos as $arquivo) {
            if (preg_match('/\.pdf$/i', $arquivo)) {
                $pdfs[] = $arquivo;
            }
        }

        sort($pdfs);
        return $pdfs;

    }

    /**
     * Processa a pasta de entrada: agrupa arquivos por fornecededor e copia
     * para a pasta do cliente (storage/boleto/<FAN>-<COD>).
     *
     * Retorna:
     * [
     *   'lotes' => [ ['fornecedor_id' => x,, 'cod_for' => y,, 'arquivos' => [...]], ... ],
     *   'sem_fornecedor' => [ 'arquivo.pdf', ... ],
     *   'nao_ativos' => [ 'arquivo.pdf', ... ],
     *   'nao_wg_ven' => [] (obsoleto: a wg_ven deixou de ser exigida),
     *   'gate_indisponivel' => [ 'arquivo.pdf', ... ],
     *   'acima_limite' => [ [ 'fornecedor', 'cod_for', 'total_mb', ... ], ... ],
     * ]
     */
    public static function processarEntrada(?string $aposArquivo = null, float $orcamentoSegundos = 40.0): array
    {
        $inicio = microtime(true);
        $arquivosEntrada = self::listarArquivosNaEntrada();
        $pdo  = getConnection();

        // Lista ordenada: o cursor ($aposArquivo) pula o que já foi examinado em
        // chamadas anteriores, para cada chamada HTTP caber abaixo do limite de 60 s
        // do balanceador/Apache.
        if ($aposArquivo !== null && $aposArquivo !== '') {
            $arquivosEntrada = array_values(array_filter(
                $arquivosEntrada,
                static fn (string $a): bool => strcmp($a, $aposArquivo) > 0
            ));
        }

        $incompleto = false;
        $ultimoArquivo = $aposArquivo;
        $examinados = 0;

        // Passo 1: agrupar arquivos por código de fornecededor (sem copiar)
        $grupos = [];
        $semFornecedor = [];
        $gateIndisponivel = [];

        foreach ($arquivosEntrada as $arquivo) {
            // Orçamento de tempo: para antes de iniciar outro arquivo (sempre faz ao menos 1).
            if ($examinados > 0 && (microtime(true) - $inicio) > $orcamentoSegundos) {
                $incompleto = true;
                break;
            }
            $examinados++;
            $ultimoArquivo = $arquivo;

            $codFor = self::extrairCodFor($arquivo);

            if (!$codFor) {
                $semFornecedor[] = $arquivo;
                continue;
            }

            // Regra: o upload do boleto é a prova de que a cobrança existe. Não se exige
            // mais que o código conste na wg_ven (vendas) do GATE. O fornecedor é resolvido
            // pelo cadastro local e, se não existir, pelo cadastro oficial (wgfor).

            $tamanho = is_file(UPLOAD_PATH . '/' . $arquivo) ? (int) filesize(UPLOAD_PATH . '/' . $arquivo) : 0;

            $grupos[$codFor]['cod_for'] = $codFor;
            $grupos[$codFor]['arquivos'][] = $arquivo;
            $grupos[$codFor]['total_bytes'] = ($grupos[$codFor]['total_bytes'] ?? 0) + $tamanho;
        }

        // Passo 2: processar cada grupo
        $lotes = [];
        $naoAtivos = [];
        $acimaLimite = [];
        $semEmail = [];
        $duplicados = [];

        foreach ($grupos as $grupo) {
            $fornecedor = FornecedorResolver::resolver($grupo['cod_for']);

            if (!$fornecedor) {
                // Não existe no cadastro local e a busca no GATE (wgfor) não trouxe nada:
                // distingue "GATE fora do ar" (tentar de novo) de "código desconhecido".
                $gateFora = FornecedorResolver::gateFalhouNaUltimaBusca();
                foreach ($grupo['arquivos'] as $a) {
                    if ($gateFora) {
                        $gateIndisponivel[] = $a;
                    } else {
                        $semFornecedor[] = $a;
                    }
                }
                continue;
            }

            // Regra de negócio: só envia para fornecedores ATIVOS
            if ((int) $fornecedor['ativo'] !== 1) {
                foreach ($grupo['arquivos'] as $a) { $naoAtivos[] = $a; }
                continue;
            }

            // Regra de negócio: sem e-mail principal não se cria fila de envio.
            // A lista de pendentes deve estar sempre "pronta para enviar". O PDF
            // fica em storage/enviar até que o usuário cadastre o e-mail e volte
            // a executar "Ler boletos" (não se copia à pasta do cliente).
            if (trim((string) ($fornecedor['email_for'] ?? '')) === '') {
                foreach ($grupo['arquivos'] as $a) { $semEmail[] = $a; }
                continue;
            }

            // Limite de tamanho total dos anexos por e-mail: se exceder,NÃO envia
            // e informa qual fornecededor está excedendo o limite.





            $limiteBytes = (int) ENVIO_LIMITE_TOTAL_MB * 1024 * 1024;
            if ($grupo['total_bytes'] > $limiteBytes) {

                $acimaLimite[] = [
                    'fornecedor'      => $fornecedor['fan_for'],
                    'cod_for'         => $grupo['cod_for'],
                    'total_mb'        => round($grupo['total_bytes'] / 1048576, 2),
                    'limite_mb'       => (int) ENVIO_LIMITE_TOTAL_MB,
                    'total_arquivos' => count($grupo['arquivos']),
                    'arquivos'        => $grupo['arquivos'],
                ];
                continue;


            }

            // Copiar para a pasta do cliente
            $pastaCliente = self::obterPastaCliente($fornecedor);
            if (!is_dir($pastaCliente)) {
                @mkdir($pastaCliente, 0775, true);
            }

            $duplicadosGrupo = [];

            foreach ($grupo['arquivos'] as $arquivo) {
                $origem = UPLOAD_PATH . '/' . $arquivo;

                // Nome original do boleto (remove o prefixo de data/hora do upload).
                $nomeOriginal = preg_replace('/^\d{14}_/', '', $arquivo);
                $destino = $pastaCliente . '/' . $nomeOriginal;

                // Já existe na pasta do cliente (raiz ou subpasta "enviado")?
                // Evita reprocessar o mesmo boleto (duplicidade de cobrança).
                if (is_file($destino) || is_file($pastaCliente . '/enviado/' . $nomeOriginal)) {
                    $duplicadosGrupo[] = [
                        'nome_entrada' => $arquivo,
                        'nome_original' => $nomeOriginal,
                        'cod_for' => $grupo['cod_for'],
                    ];
                    continue;
                }

                if (copy($origem, $destino)) {
                    $lotes[$fornecedor['id']]['fornecedor_id'] = (int) $fornecedor['id'];
                    $lotes[$fornecedor['id']]['cod_for'] = $grupo['cod_for'];
                    $lotes[$fornecedor['id']]['fan_for'] = $fornecedor['fan_for'];
                    $lotes[$fornecedor['id']]['arquivos'][] = $nomeOriginal;
                    $lotes[$fornecedor['id']]['arquivos_entrada'][] = $arquivo;
                }
            }

            // Acumula os duplicados de TODOS os grupos (antes só o último grupo era devolvido).
            $duplicados = array_merge($duplicados, $duplicadosGrupo);
        }

        return [
            'lotes'            => array_values($lotes),
            'sem_fornecedor'   => $semFornecedor,
            'nao_ativos'       => $naoAtivos,
            'nao_wg_ven'       => [], // mantido vazio por compatibilidade: a wg_ven não barra mais arquivos
            'gate_indisponivel' => $gateIndisponivel,
            'sem_email'        => $semEmail,
            'acima_limite'     => $acimaLimite,
            'duplicados'       => $duplicados,
            'avisos_cadastro'  => FornecedorResolver::consumirAvisos(),
            'incompleto'       => $incompleto,
            'ultimo_arquivo'   => $ultimoArquivo,
            'examinados'       => $examinados,
        ];
    }

    /**
     * Retorna o caminho da pasta do cliente: storage/boleto/<FAN>-<COD>
     */
    public static function obterPastaCliente(array $fornecedor): string
    {
        $fan = preg_replace('/[^A-Za-z0-9 _.-]/', '', trim($fornecedor['fan_for']));
        $cod = $fornecedor['cod_for'];

        return BOLETO_PATH . '/' . $fan . '-' . $cod;
    }

    /**
     * Lista os arquivos PDF de uma pasta (sem subpastas).
     */
    public static function listarPdfsDaPasta(string $pasta): array
    {
        if (!is_dir($pasta)) {
            return [];
        }

        $arquivos = scandir($pasta);
        $pdfs = [];

        foreach ($arquivos as $arquivo) {
            if ($arquivo === '.' || $arquivo === '..' || is_dir($pasta . '/' . $arquivo)) {
                continue;
            }
            if (preg_match('/\.pdf$/i', $arquivo)) {
                $pdfs[] = $arquivo;
            }
        }

        return $pdfs;

    }

    /**
     * Lista TODOS os arquivos de uma pasta (qualquer formato), sem subpastas.
     * Ignora a subpasta "enviado", arquivos ocultos e temporários.
     */
    public static function listarArquivosDaPasta(string $pasta): array
    {
        if (!is_dir($pasta)) {
            return [];
        }

        $arquivos = scandir($pasta);
        $lista = [];

        foreach ($arquivos as $arquivo) {
            if ($arquivo === '.' || $arquivo === '..' || is_dir($pasta . '/' . $arquivo)) {
                continue;
            }
            if ($arquivo[0] === '.' || preg_match('/\.(tmp|part|swp|lock)$/i', $arquivo)) {
                continue;
            }
            $lista[] = $arquivo;
        }

        sort($lista);
        return $lista;
    }

    /**
     * Move os PDFs enviados para a subpasta "enviado" do cliente.

     */
    public static function moverParaEnviado(string $pastaCliente, array $arquivos): void
    {
        $pastaEnviado = $pastaCliente . '/enviado';

        if (!is_dir($pastaEnviado)) {
            @mkdir($pastaEnviado, 0775, true);
        }

        foreach ($arquivos as $arquivo) {
            $origem = $pastaCliente . '/' . $arquivo;
            if (is_file($origem)) {
                @rename($origem, $pastaEnviado . '/' . $arquivo);
            }
        }
    }

    /**
     * Remove arquivos da pasta de entrada após processamento bem-sucedido.


     */
    public static function removerDaEntrada(array $arquivos): void
    {
        foreach ($arquivos as $arquivo) {
            $caminho = UPLOAD_PATH . '/' . $arquivo;
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
    }
}