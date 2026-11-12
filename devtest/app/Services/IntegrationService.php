<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * The catalogue of systems a clinic can connect to, and which ones it has.
 *
 * Two halves that are deliberately kept apart:
 *
 *   CATALOG    — what CAN be connected. Static, in code, per deployment.
 *   CONNECTION — what THIS clinic has connected. Rows in `integrations`.
 *
 * Keeping them apart means a deployment can ship a provider that no clinic
 * has switched on yet, and a clinic can switch off a provider that is
 * perfectly well configured. Neither fact has to be inferred from the other.
 *
 * Credentials are never stored here. This table records that a connection
 * exists and is switched on; the secret itself stays in the environment,
 * where it belongs. A row therefore tells you "SMS is live for this clinic"
 * without being the thing that leaks an API key.
 */
final class IntegrationService
{
    /** @var array<int, array<string, mixed>> */
    private const CATALOG = [
        [
            'provider' => 'sms_gateway',
            'name'     => 'SMS gateway',
            'blurb'    => 'Send token and "your turn is approaching" texts to patients.',
            'kind'     => 'Messaging',
        ],
        [
            'provider' => 'email_transport',
            'name'     => 'Email delivery',
            'blurb'    => 'Appointment confirmations and password resets by email.',
            'kind'     => 'Messaging',
        ],
        [
            'provider' => 'accounting',
            'name'     => 'Accounting',
            'blurb'    => 'Push invoices to your accounting system as they are raised.',
            'kind'     => 'Finance',
        ],
        [
            'provider' => 'patient_app',
            'name'     => 'Patient app',
            'blurb'    => 'Let patients track their place in line from their own phone.',
            'kind'     => 'Patients',
        ],
        [
            'provider' => 'webhooks',
            'name'     => 'Webhooks',
            'blurb'    => 'Post queue events to your own endpoint as they happen.',
            'kind'     => 'Developers',
        ],
    ];

    /**
     * Everything a clinic could connect, with this clinic's state folded in.
     *
     * `configured` means the deployment has the credentials for it; `connected`
     * means this clinic has switched it on. They are different facts and the
     * screen shows both, because "not available on this server" and "switched
     * off" are different problems with different fixes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(int $clinicId): array
    {
        $rows = Db::all(
            'SELECT provider, status, config, connected_at FROM integrations WHERE clinic_id = ?',
            [$clinicId]
        );

        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['provider']] = $row;
        }

        $out = [];
        foreach (self::CATALOG as $entry) {
            $key   = (string) $entry['provider'];
            $known = $state[$key] ?? null;
            $out[] = $entry + [
                'connected'    => $known !== null && (string) ($known['status'] ?? 'off') === 'connected',
                'connected_at' => $known['connected_at'] ?? null,
                'config'       => $this->decode($known['config'] ?? null),
                // A provider is only offered when the deployment actually has
                // the means to use it. Showing an "SMS gateway" row that can
                // never send anything would be a promise the app cannot keep.
                'available'    => $this->available($key),
            ];
        }

        return $out;
    }

    /**
     * How many integrations this clinic has switched on.
     */
    public function connectedCount(int $clinicId): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM integrations WHERE clinic_id = ? AND status = 'connected'",
            [$clinicId]
        );
    }

    /**
     * Switch an integration on for this clinic.
     *
     * Only non-secret settings are accepted. The whitelist is the point: the
     * form posts `config[...]`, and without it a caller could smuggle a
     * credential into a table that is meant never to hold one.
     *
     * @param array<string, mixed> $config
     */
    public function connect(int $clinicId, string $provider, array $config = []): bool
    {
        if (!$this->known($provider)) {
            return false;
        }

        $clean = [];
        foreach ($this->configFields($provider) as $field => $definition) {
            $value = trim((string) ($config[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            if (($definition['max'] ?? null) !== null && mb_strlen($value) > (int) $definition['max']) {
                $value = mb_substr($value, 0, (int) $definition['max']);
            }
            $clean[$field] = $value;
        }

        $payload = $clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR);

        // Read-then-write rather than an upsert. SQLite wants ON CONFLICT and
        // MySQL wants ON DUPLICATE KEY, and this class is shared by both
        // drivers; the extra query is the price of one SQL dialect here.
        // Switching an integration on is a click, not a hot path.
        $exists = Db::scalar(
            'SELECT id FROM integrations WHERE clinic_id = ? AND provider = ?',
            [$clinicId, $provider]
        );

        if ($exists) {
            Db::run(
                'UPDATE integrations SET status = \'connected\', config = ?, connected_at = ?, updated_at = ?
                 WHERE clinic_id = ? AND provider = ?',
                [$payload, Db::now(), Db::now(), $clinicId, $provider]
            );
        } else {
            Db::run(
                'INSERT INTO integrations (clinic_id, provider, status, config, connected_at, updated_at)
                 VALUES (?, ?, \'connected\', ?, ?, ?)',
                [$clinicId, $provider, $payload, Db::now(), Db::now()]
            );
        }

        return true;
    }

    /**
     * Switch an integration off, keeping its settings for later.
     */
    public function disconnect(int $clinicId, string $provider): bool
    {
        if (!$this->known($provider)) {
            return false;
        }

        Db::run(
            "UPDATE integrations SET status = 'off', updated_at = ? WHERE clinic_id = ? AND provider = ?",
            [Db::now(), $clinicId, $provider]
        );

        return true;
    }

    /**
     * The non-secret settings a given provider exposes on the connect form.
     *
     * @return array<string, array<string, mixed>>
     */
    public function configFields(string $provider): array
    {
        return match ($provider) {
            'sms_gateway' => ['sender_id' => ['label' => 'Sender ID', 'max' => 11]],
            // An endpoint is not a credential: it identifies your system, it
            // does not authenticate to it. Signing secrets stay in .env.
            'webhooks'    => ['endpoint' => ['label' => 'Endpoint URL', 'max' => 200]],
            'accounting'  => ['organisation' => ['label' => 'Organisation reference', 'max' => 60]],
            default       => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function catalog(): array
    {
        return self::CATALOG;
    }

    private function known(string $provider): bool
    {
        foreach (self::CATALOG as $entry) {
            if ($entry['provider'] === $provider) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this deployment can actually use the provider.
     *
     * Env keys are the deployment-level half of the story: the catalogue says
     * an SMS gateway exists, the environment says whether this server has one.
     */
    private function available(string $provider): bool
    {
        $configured = static fn (string ...$keys): bool => array_filter(
            array_map(static fn (string $k): string => (string) \App\Core\Env::get($k, ''), $keys)
        ) !== [];

        return match ($provider) {
            'sms_gateway'     => $configured('sms.api_key', 'sms.provider'),
            'email_transport' => $configured('mail.host', 'mail.dsn'),
            'accounting'      => $configured('accounting.client_id'),
            // The patient app and webhooks need nothing from the environment:
            // one is a public tracking page, the other posts to a URL the
            // clinic supplies. They are always offerable.
            'patient_app', 'webhooks' => true,
            default           => false,
        };
    }

    /** @return array<string, string> */
    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
