<?php
/**
 * 原生 SMTP 客户端（零依赖，不依赖 PHPMailer / composer）
 * 支持：
 *   - 465 隐式 SSL（ssl:// 直连）
 *   - 587 STARTTLS（明连后升级加密，stream_socket_enable_crypto）
 *   - 25 明文（仅本机中继调试用）
 *   - AUTH LOGIN 认证（QQ/163/阿里云邮/Gmail 通用）
 * 按协议顺序：HELO/EHLO → STARTTLS → EHLO → AUTH LOGIN → MAIL FROM → RCPT TO → DATA → QUIT
 */
class SmtpClient
{
    /** @var resource|null */
    private $socket = null;
    private string $log = '';

    public function __construct(
        private string $host,
        private int    $port,
        private string $username,
        private string $password,
        private string $secure = 'ssl'   // ssl(465) / tls(587 STARTTLS) / none(25)
    ) {}

    /** 完整发送一封 HTML 邮件。成功返回 true，失败抛异常（message 已含 SMTP 应答码） */
    public function send(string $from, string $fromName, string $to, string $subject, string $htmlBody): bool
    {
        $this->connect();
        try {
            $this->ehlo();
            if ($this->secure === 'tls') {
                $this->starttls();
                $this->ehlo();   // TLS 重建后需重新 EHLO
            }
            $this->auth();
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', [354]);

            // ---------- 组装邮件（MIME multipart：文本兜底 + HTML 正文） ----------
            $boundary = 'xc_' . bin2hex(random_bytes(12));
            $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromEnc    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $headers = [
                'From: ' . $fromEnc . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $subjectEnc,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . ($this->host) . '>',
            ];
            $textVer = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
            $mail = implode("\r\n", $headers) . "\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                . chunk_split(base64_encode($textVer)) . "\r\n"
                . '--' . $boundary . "\r\n"
                . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                . chunk_split(base64_encode($htmlBody)) . "\r\n"
                . '--' . $boundary . '--' . "\r\n";

            // 点填充：行首的点转成两个点（SMTP DATA 规范，防提前结束）
            $mail = preg_replace('/^\./m', '..', $mail);
            fwrite($this->socket, $mail . "\r\n.\r\n");
            $this->readResponse([250]);

            $this->cmd('QUIT', [221]);
            return true;
        } finally {
            $this->close();
        }
    }

    // ------------------------------------------------------------------

    private function connect(): void
    {
        $remote = $this->secure === 'ssl' ? 'ssl://' . $this->host : 'tcp://' . $this->host;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $this->socket = @stream_socket_client($remote . ':' . $this->port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) {
            throw new RuntimeException("SMTP 连接失败 {$this->host}:{$this->port} - {$errstr}");
        }
        stream_set_timeout($this->socket, 15);
        $this->readResponse([220]);
    }

    private function ehlo(): void
    {
        $domain = $_SERVER['SERVER_NAME'] ?? php_uname('n');
        $this->cmd('EHLO ' . $domain, [250]);
    }

    private function starttls(): void
    {
        $this->cmd('STARTTLS', [220]);
        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$ok) throw new RuntimeException('STARTTLS 协商失败（PHP openssl 扩展或证书问题）');
    }

    private function auth(): void
    {
        $this->cmd('AUTH LOGIN', [334]);
        $this->cmd(base64_encode($this->username), [334]);
        $this->cmd(base64_encode($this->password), [235]);
    }

    /** 发送命令并校验应答码 */
    private function cmd(string $cmd, array $expectCodes): string
    {
        if ($this->socket === null) throw new RuntimeException('SMTP 未连接');
        fwrite($this->socket, $cmd . "\r\n");
        return $this->readResponse($expectCodes);
    }

    /** 读取多行应答（形如 250-xxx / 250 xxx），校验最终应答码 */
    private function readResponse(array $expectCodes): string
    {
        $data = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $data .= $line;
            $this->log .= 'S: ' . $line;
            // 第4位是空格表示应答结束，是'-'表示还有后续行
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int)substr($data, 0, 3);
        if (!in_array($code, $expectCodes, true)) {
            throw new RuntimeException('SMTP 应答异常 [' . $code . ']: ' . trim($data));
        }
        return $data;
    }

    private function close(): void
    {
        if ($this->socket) { @fclose($this->socket); $this->socket = null; }
    }

    public function lastLog(): string { return $this->log; }
}
