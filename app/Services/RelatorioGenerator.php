<?php
/**
 * app/Services/RelatorioGenerator.php
 * Gera relatórios dos envios (sucesso/falha) em PDF e XLSX/CSV.
 *
 * Implementação NATIVA (sem dependências externas como TCPDF/PHPSpreadsheet):
 *  - PDF: gerador mínimo e válido, com paginação e tabela.
 *  - XLSX: pacote OpenXML gerado via ZipArchive.
 *  - CSV:  padrão aberto com BOM UTF-8.
 */

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

class RelatorioGenerator
{
    /**
     * Busca os envios conforme os filtros.
     *
     * @param string|null $estado      pendente|enviado|erro
     * @param string|null $dataInicial Y-m-d
     * @param string|null $dataFinal   Y-m-d
     */
    public static function buscarEnvios(?string $estado = null, ?string $dataInicial = null, ?string $dataFinal = null): array
    {
        $where = [];
        $params = [];

        if ($estado !== null && in_array($estado, ['pendente', 'enviado', 'erro'], true)) {
            $where[] = 'e.estado = :estado';
            $params['estado'] = $estado;
        }

        if ($dataInicial) {
            $where[] = 'e.data_tentativa >= :data_inicial';
            $params['data_inicial'] = $dataInicial . ' 00:00:00';
        }

        if ($dataFinal) {
            $where[] = 'e.data_tentativa <= :data_final';
            $params['data_final'] = $dataFinal . ' 23:59:59';
        }

        $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT e.*, f.fan_for, f.raz_for, f.email_for AS email_actual
                FROM envios_log e
                LEFT JOIN fornecedores f ON e.fornecedor_id = f.id
                {$whereClause}
                ORDER BY e.data_tentativa DESC";

        $rows = dbQuery($sql, $params);

        // O envios_log.destino é um retrato do momento do "Ler boletos".
        // Refletir o e-mail ATUAL do fornecedor no relatório (mesma regra da
        // lista de Envios - item 34 fix 1), para não exibir destino vazio/velho.
        foreach ($rows as &$r) {
            $emailActual = trim((string) ($r['email_actual'] ?? ''));
            if ($emailActual !== '') {
                $r['destino'] = $emailActual;
            }
            unset($r['email_actual']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Gera o arquivo do relatório conforme o formato solicitado.
     * Redireciona o download direto para o navegador.
     */
    public static function download(string $formato, ?string $estado = null, ?string $dataInicial = null, ?string $dataFinal = null): void
    {
        $envios = self::buscarEnvios($estado, $dataInicial, $dataFinal);
        $nomeArquivo = 'relatorio_envios_' . date('Ymd_His');

        switch (strtolower($formato)) {
            case 'pdf':
                self::gerarPdf($envios, $nomeArquivo);
                break;
            case 'xlsx':
                self::gerarXlsx($envios, $nomeArquivo);
                break;
            case 'csv':
                self::gerarCsv($envios, $nomeArquivo);
                break;
            default:
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Formato não suportado. Use pdf, xlsx ou csv.']);
                exit;
        }
    }

    // ====================================================================
    //  PDF (nativo)
    // ====================================================================

    /**
     * Gera relatório em PDF (paisagem A4) sem dependências externas.
     */
    private static function gerarPdf(array $envios, string $nomeArquivo): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.pdf"');
        echo self::montarPdfEnvios($envios);
        exit;
    }

    private static function montarPdfEnvios(array $envios): string
    {
        $mm = 2.83465;
        $W = 841.89;
        $H = 595.28;
        $ml = 10 * $mm;
        $mt = 10 * $mm;
        $mb = 14 * $mm;

        // Colunas: [título, largura em mm, alinhamento]
        $cols = [
            ['Data', 30, 'C'],
            ['Código', 20, 'C'],
            ['Fornecedor', 50, 'L'],
            ['Destino', 50, 'L'],
            ['Assunto', 66, 'L'],
            ['Estado', 22, 'C'],
            ['Mensagem', 34, 'L'],
        ];

        $cW = [];
        foreach ($cols as $c) {
            $cW[] = $c[1] * $mm;
        }

        $hTitulo = 11 * $mm;
        $hHeader = 7 * $mm;
        $hRow    = 7 * $mm;
        $limiteY = $H - $mb;

        $paginas = [];
        $conteudo = '';
        $y = $mt;

        $pdfY = static function (float $top, float $h) use ($H): float {
            return $H - $top - $h;
        };

        $esc = static function (string $t): string {
            // Converter UTF-8 para CP1252 (WinAnsiEncoding) para que os
            // caracteres acentuados do português sejam exibidos corretamente
            // na fonte Helvetica do PDF.
            $t = mb_convert_encoding($t, 'CP1252', 'UTF-8');
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
        };

        $celula = static function (float $cx, float $cyTop, float $cw, float $ch, string $texto, float $tamanho, string $align) use (&$conteudo, $pdfY, $esc): void {
            $largTexto = mb_strlen($texto) * 0.6 * $tamanho;
            $tx = $cx + 2;
            if ($align === 'C') {
                $tx = $cx + ($cw - $largTexto) / 2;
            } elseif ($align === 'R') {
                $tx = $cx + $cw - $largTexto - 2;
            }
            $ty = $pdfY($cyTop + ($ch - $tamanho) / 2, 0);
            $conteudo .= sprintf("BT /F1 %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $tamanho, $tx, $ty, $esc($texto));
        };

        // Trunca o texto para caber na largura da coluna (evita sobreposição no PDF,
        // já que o gerador não aplica recorte/clip ao texto).
        $truncar = static function (string $texto, float $cw, float $tamanho) : string {
            $max = max(1, (int) floor(($cw - 4) / (0.6 * $tamanho)));
            if (mb_strlen($texto) <= $max) {
                return $texto;
            }
            return mb_substr($texto, 0, max(1, $max - 1)) . '…';
        };

        $desenharCabecalho = static function () use (&$conteudo, &$y, $H, $W, $ml, $hTitulo, $hHeader, $cols, $cW, $celula, $esc): void {
            $titulo = 'Relatório de Envios de Boletos - Sua Empresa';
            $conteudo .= sprintf(
                "BT /F1 14 Tf %.2f %.2f Td (%s) Tj ET\n",
                ($W - mb_strlen($titulo) * 7) / 2,
                $H - $y - 7,
                $esc($titulo)
            );
            $y += $hTitulo;

            $sub = 'Gerado em: ' . date('d/m/Y H:i:s');
            $conteudo .= sprintf(
                "BT /F1 10 Tf %.2f %.2f Td (%s) Tj ET\n",
                ($W - mb_strlen($sub) * 5) / 2,
                $H - $y - 5,
                $esc($sub)
            );
            $y += 5 * 2.83465;

            $cx = $ml;
            foreach ($cols as $i => $c) {
                $celula($cx, $y, $cW[$i], $hHeader, $c[0], 9, 'C');
                $cx += $cW[$i];
            }
            $y += $hHeader;
        };

        $desenharCabecalho();

        foreach ($envios as $envio) {
            if ($y + $hRow > $limiteY) {
                $paginas[] = $conteudo;
                $conteudo = '';
                $y = $mt;
                $desenharCabecalho();
            }

            $estado = strtoupper((string) ($envio['estado'] ?? ''));
            if ($estado === 'ERRO') {
                $conteudo .= "0.75 0.05 0.05 rg\n";
            } elseif ($estado === 'ENVIADO') {
                $conteudo .= "0.10 0.50 0.15 rg\n";
            } else {
                $conteudo .= "0.55 0.45 0.10 rg\n";
            }

            $valores = [
                date('d/m/Y H:i', strtotime($envio['data_tentativa'] ?? 'now')),
                (string) ($envio['cod_for'] ?? ''),
                (string) ($envio['fan_for'] ?? ''),
                (string) ($envio['destino'] ?? ''),
                (string) ($envio['assunto'] ?? ''),
                $estado,
                (string) ($envio['mensagem_erro'] ?? ''),
            ];

            $cx = $ml;
            foreach ($valores as $i => $valor) {
                $celula($cx, $y, $cW[$i], $hRow, $truncar($valor, $cW[$i], 8), 8, $cols[$i][2]);
                $cx += $cW[$i];
            }
            $conteudo .= "0 0 0 rg\n";
            $y += $hRow;
        }

        if (count($envios) === 0) {
            $celula($ml, $y, array_sum($cW), $hRow, 'Nenhum registro encontrado.', 9, 'C');
        }

        $paginas[] = $conteudo;

        return self::montarArquivoPdf($paginas, $W, $H);
    }

    /**
     * Monta o arquivo PDF (objetos + xref) a partir das páginas geradas.
     */
    public static function montarArquivoPdf(array $paginas, float $W, float $H): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $nextObj = 4;
        $kidRefs = [];

        foreach ($paginas as $conteudoPagina) {
            $contentObj = $nextObj++;
            $pageObj = $nextObj++;

            $objs[$contentObj] = '<< /Length ' . strlen($conteudoPagina) . " >>\nstream\n" . $conteudoPagina . "endstream";
            $objs[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
                $W,
                $H,
                $contentObj
            );
            $kidRefs[] = $pageObj . ' 0 R';
        }

        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kidRefs) . '] /Count ' . count($kidRefs) . ' >>';

        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objs as $n => $corpo) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $corpo . "\nendobj\n";
        }

        $maxObj = count($objs) + 1;
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 {$maxObj}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n < $maxObj; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$maxObj} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";

        return $pdf;
    }

    // ====================================================================
    //  XLSX (nativo - OpenXML via ZipArchive)
    // ====================================================================

    private static function gerarXlsx(array $envios, string $nomeArquivo): void
    {
        if (!class_exists(\ZipArchive::class)) {
            http_response_code(500);
            echo json_encode(['error' => 'Extensão ZipArchive não disponível no servidor. Use o formato CSV.']);
            exit;
        }

        $conteudo = self::montarXlsxEnvios($envios);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.xlsx"');
        header('Content-Length: ' . strlen($conteudo));
        echo $conteudo;
        exit;
    }

    private static function montarXlsxEnvios(array $envios): string
    {
        $headers = ['ID', 'Código', 'Fornecedor', 'Destino', 'Assunto', 'Estado', 'Mensagem', 'Data Tentativa', 'Criado em'];

        $linhas = [];
        foreach ($envios as $envio) {
            $linhas[] = [
                $envio['id'] ?? '',
                $envio['cod_for'] ?? '',
                $envio['fan_for'] ?? '',
                $envio['destino'] ?? '',
                $envio['assunto'] ?? '',
                $envio['estado'] ?? '',
                $envio['mensagem_erro'] ?? '',
                $envio['data_tentativa'] ?? '',
                $envio['created_at'] ?? '',
            ];
        }

        // Planilha
        $sheetRows = '';
        $linhaIdx = 1;
        $sheetRows .= self::linhaXml($linhaIdx, $headers, true);
        $linhaIdx++;
        foreach ($linhas as $linha) {
            $sheetRows .= self::linhaXml($linhaIdx, $linha, false);
            $linhaIdx++;
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<autoFilter ref="A1:I' . max(1, $linhaIdx - 1) . '"/>'
            . '</worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Envios" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . "\n" . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $arquivo = tempnam(sys_get_temp_dir(), 'relxlsx') . '.xlsx';

        $zip = new \ZipArchive();
        if ($zip->open($arquivo, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao criar o arquivo XLSX.']);
            exit;
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->close();

        $conteudo = (string) file_get_contents($arquivo);
        @unlink($arquivo);

        return $conteudo;
    }

    /**
     * Monta uma linha <row> da planilha com células de string inline.
     */
    private static function linhaXml(int $num, array $valores, bool $negrito): string
    {
        $cols = range('A', 'I');
        $s = $negrito ? ' s="1"' : '';
        $xml = '<row r="' . $num . '">';
        foreach ($valores as $i => $valor) {
            $xml .= '<c r="' . $cols[$i] . $num . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
                . htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</t></is></c>';
        }
        $xml .= '</row>';
        return $xml;
    }

    // ====================================================================
    //  CSV
    // ====================================================================

    private static function gerarCsv(array $envios, string $nomeArquivo): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.csv"');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM para UTF-8 no Excel

        fputcsv($out, ['ID', 'Código', 'Fornecedor', 'Destino', 'Assunto', 'Estado', 'Mensagem', 'Data Tentativa', 'Criado em'], ';');

        foreach ($envios as $envio) {
            fputcsv($out, [
                $envio['id'] ?? '',
                $envio['cod_for'] ?? '',
                $envio['fan_for'] ?? '',
                $envio['destino'] ?? '',
                $envio['assunto'] ?? '',
                $envio['estado'] ?? '',
                $envio['mensagem_erro'] ?? '',
                $envio['data_tentativa'] ?? '',
                $envio['created_at'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }
}