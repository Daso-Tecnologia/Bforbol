<?php
/**
 * app/Services/MailSender.php
 * Encapsula o PHPMailer para envio via SMTP corporativo.
 * Replica o comportamento do legado (CDO.Message):
 *   - Para: EMAIL_FOR (e-mail principal)
 *   - CC: EMAILFOR01..05 + CC_FIXO (contato@exemplo.com.br)
 *   - Anexos: PDFs
 *   - SMTP porta 587/STARTTLS (ou 465/SSL conforme configuração)
 */

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../../config/config.php';

class MailSender
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->Host       = SMTP_HOST;
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = SMTP_USER;
        $this->mail->Password   = SMTP_PASS;
        $this->mail->Port       = SMTP_PORT;

        // Porta 465 = SSL implícito; 587 = STARTTLS.
        // Se SMTP_SECURE não estiver explícito, usa o padrão da porta.

        $secure = strtolower((string) SMTP_SECURE);
        if ($secure === 'ssl' || (int) SMTP_PORT === 465) {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;

        } else {
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587
        }

        $this->mail->CharSet = 'UTF-8';
        $this->mail->Timeout = SMTP_TIMEOUT;
        // Reaproveita a conexão SMTP entre os e-mails de um mesmo lote (menos logins
        // no servidor de e-mail, que pode limitar conexões/autenticações por minuto).
        $this->mail->SMTPKeepAlive = true;
        $this->mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    }

    public function __destruct()
    {
        try {
            $this->mail->smtpClose();
        } catch (\Throwable $e) {
            // encerrando: nada a fazer
        }
    }

    /** Tentativas realizadas no último envio (para registrar na mensagem de erro). */
    private int $tentativas = 1;

    /**
     * Erro "temporário" (vale tentar de novo): falha de conexão, tempo esgotado,
     * limite de taxa do servidor de e-mail (códigos 4xx) etc. Erros definitivos
     * (endereço inválido, 5xx de destinatário) NÃO são repetidos.
     */
    private static function erroTemporario(string $info): bool
    {
        // ATENÇÃO (duplicidade de cobrança): só repete quando é CERTO que a mensagem
        // NÃO foi aceita pelo servidor. Por isso NÃO se repete em "timeout", "connection
        // reset/closed" ou "broken pipe" depois do envio dos dados: o servidor pode ter
        // aceitado a mensagem e só demorado a confirmar — repetir enviaria 2 e-mails.
        $t = strtolower($info);
        foreach ([
            'smtp connect() failed',    // não chegou a conectar: nada foi enviado
            'could not authenticate',   // falhou no login: nada foi enviado
        ] as $trecho) {
            if (strpos($t, $trecho) !== false) {
                return true;
            }
        }
        // Resposta 4xx = o servidor recusou AGORA e pede para tentar depois (limite de taxa etc.).
        return preg_match('/(^|[^0-9])4[0-9]{2}[ -]/', $info) === 1;
    }

    /**
     * Envia com repetição automática em erro temporário (pausa crescente: 2 s, 4 s...).
     * Lança a última exceção do PHPMailer se esgotar as tentativas ou o erro for definitivo.
     */
    private function enviarComTentativas(): void
    {
        $max = defined('ENVIO_TENTATIVAS_SMTP') ? max(1, (int) ENVIO_TENTATIVAS_SMTP) : 3;
        $this->tentativas = 0;
        while (true) {
            $this->tentativas++;
            try {
                $this->mail->send();
                return;
            } catch (PHPMailerException $e) {
                if ($this->tentativas >= $max || !self::erroTemporario((string) $this->mail->ErrorInfo)) {
                    throw $e;
                }
                $this->mail->smtpClose(); // força nova conexão na próxima tentativa
                sleep(2 * $this->tentativas);
            }
        }
    }

    /**
     * Monta os destinatários a partir do fornecedor (array da tabela fornecedores).
     * - Para: email_for (único; NUNCA se duplica nos CC)
     * - CC: email_for_2..6 preenchidos MANUALMENTE pelo usuário + CC_FIXO (opcional)
     */
    private function montarDestinatarios(array $fornecedor): array
    {
        $para = array_values(array_filter([
            $fornecedor['email_for'] ?? '',
        ]));
        $principal = count($para) > 0 ? $para[0] : '';

        $cc = [];
        foreach (['email_for_2', 'email_for_3', 'email_for_4', 'email_for_5', 'email_for_6'] as $campo) {
            $email = trim((string) ($fornecedor[$campo] ?? ''));
            if ($email !== '' && $email !== $principal) {
                $cc[] = $email;
            }
        }

        // CC fixo (regra de negocio do legado, configurable no .env) — nunca repite o principal
        if (ENVIO_EMAIL_COPIA && CC_FIXO !== '' && CC_FIXO !== $principal) {
            $cc[] = CC_FIXO;
        }

        return ['para' => $para, 'cc' => array_values(array_unique($cc))];
    }

    /**
     * Carrega o corpo padrão da mensagem a partir do template local
     * (app/Resources/boleto_corpo.html), espelho do arquivo
     * https://exemplo.com.br/fat/boleto.html.
     * Se o arquivo não existir, retorna '' (o chamador usa o fallback).
     */
    private function corpoPadrao(): string
    {
        $arquivo = APP_PATH . '/Resources/boleto_corpo.html';
        if (is_file($arquivo)) {
            $conteudo = (string) file_get_contents($arquivo);
            if ($conteudo !== '') {
                return $conteudo;
            }
        }
        return '';
    }

    /**
     * Embute a assinatura de e-mail (HTML + imagens) como inline (CID) no corpo.
     * As imagens ficam em app/Resources/assinatura/ e cada URL remota é
     * substituída por cid: correspondente quando o arquivo local existe.
     * Se algum arquivo não existir, mantém a URL remota (comportamento de fallback).
     */
    private function montarCorpoComAssinatura(string $corpoHtml): string
    {
        $dir = APP_PATH . '/Resources/assinatura';

        $mapa = [
            'https://exemplo.com.br/assets/logo.gif' => [
                'arquivo' => $dir . '/logo_empresa.gif',
                'cid' => 'logo_empresa',
                'nome' => 'logo_empresa.gif',
                'tipo' => 'image/gif',
            ],
            'https://exemplo.com.br/assets/website.png' => [
                'arquivo' => $dir . '/assinatura_website_novo.png',
                'cid' => 'ico_website',
                'nome' => 'assinatura_website_novo.png',
                'tipo' => 'image/png',
            ],
            'https://exemplo.com.br/assets/instagram.png' => [
                'arquivo' => $dir . '/assinatura_instragran_novo.png',
                'cid' => 'ico_instagram',
                'nome' => 'assinatura_instragran_novo.png',
                'tipo' => 'image/png',
            ],
            'https://exemplo.com.br/assets/linkedin.png' => [
                'arquivo' => $dir . '/assinatura_linkedin_novo.png',
                'cid' => 'ico_linkedin',
                'nome' => 'assinatura_linkedin_novo.png',
                'tipo' => 'image/png',
            ],
            'https://exemplo.com.br/assets/banner.png' => [
                'arquivo' => $dir . '/melhoresturismo.png',
                'cid' => 'banner_melhoresturismo',
                'nome' => 'melhoresturismo.png',
                'tipo' => 'image/png',
            ],
        ];

        foreach ($mapa as $url => $info) {
            if (is_file($info['arquivo']) && strpos($corpoHtml, $url) !== false) {
                $this->mail->AddEmbeddedImage($info['arquivo'], $info['cid'], $info['nome'], 'base64', $info['tipo']);
                $corpoHtml = str_replace($url, 'cid:' . $info['cid'], $corpoHtml);
            }
        }

        return $corpoHtml;
    }

    /**
     * Envia e-mail de teste para um destinatário, com um PDF fictício em anexo.
     *
     * @param string $email      destinatário do teste
     * @param string $caminhoPdf caminho do PDF fictício gerado
     *
     * @return array ['ok' => bool, 'mensagem' => string]
     */
    public function enviarTeste(string $email, string $caminhoPdf): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'mensagem' => 'E-mail de destino inválido'];
        }

        if (!is_file($caminhoPdf)) {
            return ['ok' => false, 'mensagem' => 'PDF fictício de teste não encontrado'];
        }

        try {
            $this->mail->clearAddresses();
            $this->mail->clearCCs();
            $this->mail->clearAttachments();
            $this->mail->clearReplyTos();

            $this->mail->addAddress($email);
            $this->mail->Subject = 'Teste de envio - Sistema de Boletos Sua Empresa';

            $corpo = $this->corpoPadrao();
            if ($corpo === '') {
                $corpo = '<p>Prezado(a),</p><p>Este é um e-mail de teste do Sistema de Boletos da Sua Empresa. Se você recebeu esta mensagem, o envio de e-mails está funcionando corretamente.</p><p>Em anexo, um PDF fictício para validar o recebimento de anexos.</p><p>Atenciosamente,<br><strong>' . htmlspecialchars(SMTP_FROM_NAME, ENT_QUOTES, 'UTF-8') . '</strong></p>';
            }
            $corpo = $this->montarCorpoComAssinatura($corpo);

            $this->mail->isHTML(true);
            $this->mail->Body = $corpo;
            $this->mail->AltBody = strip_tags($corpo);
            $this->mail->addAttachment($caminhoPdf);

            $this->mail->send();

            return ['ok' => true, 'mensagem' => 'E-mail de teste enviado com sucesso'];
        } catch (PHPMailerException $e) {
            return ['ok' => false, 'mensagem' => 'Erro no envio: ' . $this->mail->ErrorInfo];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Erro inesperado: ' . $e->getMessage()];
        }
    }

    /**
     * Envia e-mail com PDFs anexados para um fornecedor.
     *
     * @param array  $fornecedor dados da tabela fornecedores
     * @param array  $pdfs       lista de caminhos completos dos PDFs
     * @param string $assunto    assunto do e-mail
     * @param string $corpo      corpo HTML opcional (se vazio, usa o template padrão)
     *
     * @return array ['ok' => bool, 'mensagem' => string]
     */
    public function enviarBoleto(array $fornecedor, array $pdfs, string $assunto, string $corpo = ''): array
    {
        $destinatarios = $this->montarDestinatarios($fornecedor);

        if (count($destinatarios['para']) === 0) {
            return ['ok' => false, 'mensagem' => 'Fornecedor sem e-mail principal (email_for)'];
        }

        if (count($pdfs) === 0) {
            return ['ok' => false, 'mensagem' => 'Nenhum PDF anexado'];
        }

        try {
            $this->mail->clearAddresses();
            $this->mail->clearCCs();
            $this->mail->clearAttachments();
            $this->mail->clearReplyTos();

            $this->mail->addAddress($destinatarios['para'][0]);

            foreach ($destinatarios['cc'] as $cc) {
                if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                    $this->mail->addCC($cc);
                }
            }

            $this->mail->Subject = $assunto;

            // Corpo: usa o corpo informado; senão o template padrão (boleto_corpo.html);
            // como último recurso, um corpo HTML genérico.
            $corpoFinal = $corpo !== ''
                ? $corpo
                : ($this->corpoPadrao() !== ''
                    ? $this->corpoPadrao()
                    : sprintf(
                        '<p>Prezado(a),</p><p>Segue em anexo o(s) boleto(s) de comissão referente ao fornecedor <strong>%s</strong> (código %s).</p><p>Qualquer dúvida, estamos à disposição.</p><p>Atenciosamente,<br><strong>%s</strong></p>',
                        htmlspecialchars($fornecedor['fan_for'] ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($fornecedor['cod_for'] ?? '', ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars(SMTP_FROM_NAME, ENT_QUOTES, 'UTF-8')
                    ));

            $corpoFinal = $this->montarCorpoComAssinatura($corpoFinal);

            $this->mail->isHTML(true);
            $this->mail->Body = $corpoFinal;
            $this->mail->AltBody = strip_tags($corpoFinal);

            foreach ($pdfs as $pdf) {
                if (is_file($pdf)) {
                    $this->mail->addAttachment($pdf);
                }
            }

            $this->enviarComTentativas();

            return ['ok' => true, 'mensagem' => 'Enviado com sucesso'];
        } catch (PHPMailerException $e) {
            return ['ok' => false, 'mensagem' => 'Erro no envio (tentativa ' . $this->tentativas . '): ' . $this->mail->ErrorInfo];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Erro inesperado: ' . $e->getMessage()];
        }
    }
}