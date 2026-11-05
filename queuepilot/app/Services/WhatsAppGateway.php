<?php
declare(strict_types=1);

namespace App\Services;

/** WhatsApp Cloud API transport — same interface, metered separately. */
final class WhatsAppGateway implements Gateway
{
    public function send(string $to, string $body): string
    {
        $token = getenv('WHATSAPP_TOKEN') ?: '';
        $phoneId = getenv('WHATSAPP_PHONE_ID') ?: '';

        if ($token === '' || $phoneId === '') {
            return 'simulated';
        }

        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '94' . substr($digits, 1);
        }

        $ch = curl_init('https://graph.facebook.com/v19.0/' . $phoneId . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $digits,
                'type' => 'text',
                'text' => ['body' => $body],
            ]),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException('WhatsApp rejected the message (HTTP ' . $status . ')');
        }
        return 'sent';
    }
}
