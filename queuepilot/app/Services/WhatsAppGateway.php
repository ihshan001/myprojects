<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Log;

/**
 * WhatsApp Cloud API transport.
 *
 * Same three transports and the same honesty rule as SMS, because the failure
 * this file used to have is the one that matters most:
 *
 *   log   the message is recorded and reported as `logged` — recorded, not
 *         sent. It never claims a patient was contacted.
 *
 *   http  a real POST to WHATSAPP_API_URL (default: Meta's Cloud API). Missing
 *         credentials here is an **error**, not a silent simulation. The old
 *         version returned `simulated` whenever the token was absent, which
 *         the worker stored as a completed send — a half-configured install
 *         was indistinguishable from a working one from inside the app.
 *
 *   auto  http when credentials exist, log otherwise.
 *
 * One deliberate difference from SMS, and it is the reason this will keep
 * needing work in production: WhatsApp does not accept free text to a number
 * that has not messaged you in the last 24 hours. Business-initiated messages
 * (a token call, an appointment reminder — exactly what this product sends)
 * must use a **pre-approved template**. This class sends `type: text`, which
 * works inside the customer-service window and fails outside it with error
 * 131047. Sending templates means creating and getting them approved in Meta
 * Business Manager, with a variable mapping per template; that is an account
 * activity, not code, and pretending otherwise would be the sort of claim this
 * project exists to avoid. WHATSAPP_TEMPLATE_NAME, when set, switches to
 * template mode with a single body variable.
 */
final class WhatsAppGateway implements Gateway
{
    public function send(string $to, string $body): string
    {
        $transport = strtolower((string) (getenv('WHATSAPP_TRANSPORT') ?: 'auto'));
        $token = trim((string) (getenv('WHATSAPP_TOKEN') ?: ''));
        $phoneId = trim((string) (getenv('WHATSAPP_PHONE_ID') ?: ''));
        $template = trim((string) (getenv('WHATSAPP_TEMPLATE_NAME') ?: ''));
        $endpoint = trim((string) (getenv('WHATSAPP_API_URL') ?: 'https://graph.facebook.com/v19.0'));

        $configured = $token !== '' && $phoneId !== '';

        if ($transport === 'log' || ($transport === 'auto' && !$configured)) {
            if ($transport === 'http') {
                throw new \RuntimeException('WhatsApp is set to http but WHATSAPP_TOKEN / WHATSAPP_PHONE_ID are missing.');
            }
            Log::info('whatsapp.log_transport', ['to' => self::maskForLog($to)]);
            return 'logged';
        }

        if (!$configured) {
            throw new \RuntimeException('WhatsApp is set to http but WHATSAPP_TOKEN / WHATSAPP_PHONE_ID are missing.');
        }

        $digits = (string) preg_replace('/\D+/', '', $to);
        if (str_starts_with($digits, '0')) {
            $digits = '94' . substr($digits, 1);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $digits,
        ];
        if ($template !== '') {
            // Business-initiated message: must be an approved template.
            $payload['type'] = 'template';
            $payload['template'] = [
                'name' => $template,
                'language' => ['code' => (string) (getenv('WHATSAPP_TEMPLATE_LANG') ?: 'en')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $body]],
                ]],
            ];
        } else {
            // Inside the 24-hour customer-service window only.
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $body];
        }

        $ch = curl_init(rtrim($endpoint, '/') . '/' . $phoneId . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('WhatsApp could not be reached.');
        }
        if ($status >= 400) {
            // Pass through Meta's own explanation when it gives one: "message
            // outside the 24-hour window" is something a clinic can act on,
            // "HTTP 400" is not.
            $decoded = json_decode((string) $response, true);
            $detail = $decoded['error']['message'] ?? null;
            throw new \RuntimeException(
                'WhatsApp rejected the message (HTTP ' . $status . ')' . ($detail !== null ? ': ' . mb_substr((string) $detail, 0, 200) : '')
            );
        }

        return 'sent';
    }

    /** Enough to correlate a log line, not enough to identify a patient. */
    private static function maskForLog(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        return $digits === '' ? '' : '•••••' . substr($digits, -3);
    }
}
