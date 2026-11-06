<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * text.lk SMS gateway.
 *
 * Calls `POST https://app.text.lk/api/v3/sms/send` with Bearer auth and a
 * JSON body. Every SMS costs 0.64 LKR, so this transport is only used when
 * the clinic is entitled to it (see the feature gate) and the message is
 * genuinely needed.
 *
 * Env:
 *   TEXTLK_API_KEY   — Bearer token from the text.lk dashboard (required).
 *   TEXTLK_SENDER_ID — ≤11 alphanumeric sender ID approved by text.lk
 *                      (required; falls back to 'QueuePilot' for dev only).
 *
 * Implements Gateway exactly: returns 'sent' when text.lk accepts the
 * message, 'logged' when the log transport is used instead, throws on
 * network or provider failure.
 */
final class TextLkGateway implements Gateway
{
    private const ENDPOINT = 'https://app.text.lk/api/v3/sms/send';

    public function send(string $to, string $body): string
    {
        $transport = strtolower((string) (Env::get('sms.gateway') ?: getenv('SMS_GATEWAY') ?: 'log'));

        if ($transport !== 'textlk') {
            // Not selected: fall back to the log transport so a clinic that
            // has not enabled text.lk never accidentally texts anyone.
            \App\Core\Log::info('sms.log_transport', [
                'to' => self::maskForLog($to),
                'body' => $body,
                'from' => $this->senderId(),
                'gateway' => 'textlk',
            ]);
            return 'logged';
        }

        $apiKey = (string) (Env::get('textlk.api_key') ?: getenv('TEXTLK_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new \RuntimeException(
                'text.lk transport selected but TEXTLK_API_KEY is not set. Messages are not being sent.'
            );
        }

        $senderId = $this->senderId();
        if ($senderId === '') {
            throw new \RuntimeException(
                'text.lk transport selected but TEXTLK_SENDER_ID is not set. Messages are not being sent.'
            );
        }

        // Recipient must be 947XXXXXXXX (no '+'), per text.lk's API contract.
        $recipient = preg_replace('/\D/', '', $to);
        if (str_starts_with($recipient, '0')) {
            $recipient = '94' . substr($recipient, 1);
        } elseif (!str_starts_with($recipient, '94')) {
            $recipient = '94' . $recipient;
        }

        $body = json_encode([
            'recipient' => $recipient,
            'sender_id' => $senderId,
            'type' => 'plain',
            'message' => $body,
        ]);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            throw new \RuntimeException(
                'text.lk request failed: ' . ($error !== '' ? $error : 'no response')
            );
        }

        // 2xx means text.lk accepted the message for delivery. Anything else
        // is a real failure — wrong key, unapproved sender ID, blocked
        // recipient, insufficient balance — and must throw so the caller can
        // retry or surface it.
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(
                'text.lk returned HTTP ' . $httpCode . ': ' . self::truncate($response)
            );
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            // text.lk returned something that is not JSON on a 2xx — treat
            // as accepted but log the body for debugging.
            \App\Core\Log::info('sms.textlk_response', [
                'to' => self::maskForLog($to),
                'http' => $httpCode,
                'body' => self::truncate($response, 200),
            ]);
            return 'sent';
        }

        // text.lk v3 returns a message object; we only need to know whether
        // it was accepted. A 2xx with any body means the gateway took it.
        return 'sent';
    }

    private function senderId(): string
    {
        return (string) (Env::get('textlk.sender_id') ?: getenv('TEXTLK_SENDER_ID') ?: '');
    }

    private static function maskForLog(string $to): string
    {
        $digits = preg_replace('/\D/', '', $to);
        if ($digits === '') {
            return '';
        }
        $keep = max(2, (int) ceil(strlen($digits) / 3));
        return str_repeat('X', max(0, strlen($digits) - $keep)) . substr($digits, -$keep);
    }

    private static function truncate(string $s, int $max = 300): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
    }
}
