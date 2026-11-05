<?php
declare(strict_types=1);

namespace App\Services;

/**
 * SMS transport. Swap the provider here (Dialog / Mobitel / Twilio / local
 * aggregator) without touching domain code. Credentials come from env vars and
 * are never committed.
 */
final class SmsGateway implements Gateway
{
    public function send(string $to, string $body): string
    {
        $sid   = getenv('SMS_PROVIDER_SID') ?: '';
        $token = getenv('SMS_PROVIDER_TOKEN') ?: '';
        $url   = getenv('SMS_PROVIDER_URL') ?: '';

        if ($sid === '' || $token === '' || $url === '') {
            return 'simulated';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['to' => $to, 'message' => $body]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($sid . ':' . $token),
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException('SMS gateway rejected the message (HTTP ' . $status . ')');
        }
        return 'sent';
    }
}
