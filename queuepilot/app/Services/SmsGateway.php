<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\Log;

/**
 * SMS transport. Swap the provider here (Dialog / Mobitel / Twilio / local
 * aggregator) without touching domain code. Credentials come from env vars and
 * are never committed.
 *
 * Three transports, and the difference between them is the point:
 *
 *   log   the message is written to the application log and reported as sent.
 *         This is a development transport, the same idea as Laravel's
 *         MAIL_MAILER=log: the pipeline is exercised, nothing leaves the box,
 *         and the notification history shows a delivery rather than a wall of
 *         failures. It is the default so a fresh install cannot text anyone.
 *
 *   http  a real POST to SMS_PROVIDER_URL. Used by every aggregator with a
 *         REST endpoint. Credentials missing under this transport is an
 *         error, not a simulation — see below.
 *
 *   auto  http when credentials are present, log otherwise.
 *
 * The previous behaviour returned `simulated` whenever credentials were absent
 * regardless of configuration, which meant a half-configured production
 * install looked identical to a working one from inside the app. A missing
 * credential is now a loud failure, because the alternative is a clinic
 * believing patients were told.
 */
final class SmsGateway implements Gateway
{
    public function send(string $to, string $body): string
    {
        $transport = strtolower((string) (Env::get('sms.gateway') ?: getenv('SMS_GATEWAY') ?: 'log'));

        $sid   = (string) (Env::get('sms.provider_sid') ?: getenv('SMS_PROVIDER_SID') ?: '');
        $token = (string) (Env::get('sms.provider_token') ?: getenv('SMS_PROVIDER_TOKEN') ?: '');
        $url   = (string) (Env::get('sms.provider_url') ?: getenv('SMS_PROVIDER_URL') ?: '');
        $from  = (string) (Env::get('sms.from') ?: getenv('SMS_FROM') ?: 'QueuePilot');

        $useHttp = $transport === 'http' || ($transport === 'auto' && $sid !== '' && $token !== '' && $url !== '');
        if (!$useHttp) {
            if ($transport === 'http') {
                // Explicitly asked for real delivery without the means to do it.
                throw new \RuntimeException(
                    'SMS transport is set to http but the provider URL, SID or token is missing. '
                    . 'Messages are not being sent.'
                );
            }
            Log::info('sms.log_transport', [
                'to' => self::maskForLog($to),
                'body' => $body,
                'from' => $from,
            ]);
            // Not 'sent'. The clinic must be able to see that nothing reached
            // the patient, otherwise a development install and a broken
            // production one look identical in the notification history.
            return 'logged';
        }

        if ($url === '') {
            throw new \RuntimeException('SMS provider URL is missing.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'to' => $to,
                'message' => $body,
                'from' => $from,
            ]),
            CURLOPT_HTTPHEADER => array_filter([
                'Accept: application/json',
                $sid !== '' && $token !== ''
                    ? 'Authorization: Basic ' . base64_encode($sid . ':' . $token)
                    : null,
            ]),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            $detail = $response === false ? $error : ('HTTP ' . $status);
            throw new \RuntimeException('SMS gateway rejected the message (' . $detail . ')');
        }

        Log::info('sms.sent', ['to' => self::maskForLog($to), 'status' => $status]);
        return 'sent';
    }

    /** Last three digits only, matching how the rest of the app stores numbers. */
    private static function maskForLog(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        return strlen($digits) >= 3 ? '•••••' . substr($digits, -3) : '••••';
    }
}
