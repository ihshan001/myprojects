<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Log;

/**
 * Mail transport for invitations and account notices.
 *
 * Same honesty rule as SmsGateway: when no transport is configured this
 * returns false rather than pretending the message was delivered. The caller
 * may then show the invitation link to the administrator so it can be passed
 * on by hand — the invitation still works, the delivery claim is just true.
 *
 * Two transports are supported:
 *   - `log`: appends to storage/logs/mail.log (development and demos).
 *   - `smtp`: a minimal SMTP client (STARTTLS or implicit TLS).
 * Anything else is treated as unconfigured.
 */
final class MailService
{
    /** @return bool true only when the message really left this process. */
    public function send(string $to, string $subject, string $body): bool
    {
        $transport = strtolower((string) Env::get('mail.transport', 'none'));

        if ($transport === 'log') {
            return $this->writeToLog($to, $subject, $body);
        }
        if ($transport === 'smtp') {
            return $this->sendSmtp($to, $subject, $body);
        }
        Log::info('mail.not_configured', ['to' => $to, 'subject' => $subject]);
        return false;
    }

    private function writeToLog(string $to, string $subject, string $body): bool
    {
        $dir = QP_ROOT . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $line = sprintf(
            "===== %s\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('c'),
            $to,
            $subject,
            $body
        );
        return @file_put_contents($dir . '/mail.log', $line, FILE_APPEND) !== false;
    }

    /**
     * Minimal SMTP: EHLO → STARTTLS (if offered) → AUTH LOGIN → MAIL/RCPT/DATA.
     * Deliberately not a general-purpose client; multi-recipient, attachments
     * and HTML parts are out of scope for a transactional notice.
     */
    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $host = (string) Env::get('mail.host', '');
        $port = (int) Env::get('mail.port', 587);
        $user = (string) Env::get('mail.username', '');
        $pass = (string) Env::get('mail.password', '');
        $from = (string) Env::get('mail.from', 'no-reply@queuepilot.local');
        $secure = strtolower((string) Env::get('mail.encryption', 'tls'));

        if ($host === '') {
            return false;
        }

        $prefix = $secure === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            Log::error('mail.smtp.connect_failed', ['host' => $host, 'err' => $errstr]);
            return false;
        }
        stream_set_timeout($socket, 10);

        $read = function () use ($socket): string {
            $out = '';
            while (($line = fgets($socket, 1024)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        $cmd = function (string $command, array $expect) use ($socket, $read): bool {
            fwrite($socket, $command . "\r\n");
            $response = $read();
            foreach ($expect as $code) {
                if (str_starts_with($response, (string) $code)) {
                    return true;
                }
            }
            return false;
        };

        try {
            if (!str_starts_with($read(), '220')) {
                return false;
            }
            $hostname = parse_url((string) Env::get('app.url', 'localhost'), PHP_URL_HOST) ?: 'localhost';
            if (!$cmd('EHLO ' . $hostname, [250])) {
                return false;
            }
            if ($secure === 'tls') {
                if (!$cmd('STARTTLS', [220])) {
                    return false;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return false;
                }
                if (!$cmd('EHLO ' . $hostname, [250])) {
                    return false;
                }
            }
            if ($user !== '') {
                if (!$cmd('AUTH LOGIN', [334])) {
                    return false;
                }
                if (!$cmd(base64_encode($user), [334])) {
                    return false;
                }
                if (!$cmd(base64_encode($pass), [235])) {
                    return false;
                }
            }
            if (!$cmd('MAIL FROM:<' . $from . '>', [250])) {
                return false;
            }
            if (!$cmd('RCPT TO:<' . $to . '>', [250, 251])) {
                return false;
            }
            if (!$cmd('DATA', [354])) {
                return false;
            }
            $headers = 'From: ' . $from . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . 'MIME-Version: 1.0' . "\r\n"
                . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                . 'Content-Transfer-Encoding: 8bit' . "\r\n";
            // Dot-stuffing per RFC 5321 §4.5.2
            $stuffed = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $payload = $headers . "\r\n" . str_replace("\n", "\r\n", (string) $stuffed) . "\r\n.";
            if (!$cmd($payload, [250])) {
                return false;
            }
            $cmd('QUIT', [221]);
            return true;
        } catch (\Throwable $e) {
            Log::error('mail.smtp.error', ['error' => $e->getMessage()]);
            return false;
        } finally {
            @fclose($socket);
        }
    }
}
