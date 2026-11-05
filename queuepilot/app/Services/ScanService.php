<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * What the desk scanner accepts, and what it refuses.
 *
 * The scanner has no special authority of its own: a barcode reader is a
 * keyboard, and a phone camera is untrusted input. So this class does the one
 * thing that matters — turn a scanned string into a queue entry **belonging to
 * the scanning clinic**, or explain why it cannot.
 *
 * Accepted, in order of how often they actually occur:
 *
 *   1. A full tracking URL from the slip's QR — `https://…/t/<token>`, with or
 *      without a trailing slash, any host, `http` or `https`. The host is
 *      deliberately ignored: a clinic may print its own domain, and the token
 *      is what identifies the visit. Accepting the host as authoritative would
 *      let a printed slip from one clinic scan into another.
 *   2. The bare 128-bit token, which is what a cheap laser reader emits when
 *      it reads the same QR.
 *   3. A token label (`A012`) — typed, or read from a barcode that encodes the
 *      printed label. Resolves only within today's open sessions.
 *
 * Refused: anything shorter or longer than a token, any token belonging to a
 * different clinic, an expired link, and a string that matches nothing. The
 * refusal message never echoes a patient's name — the person holding the
 * scanner may have just read a stranger's slip.
 */
final class ScanService
{
    /** The tracking token: 128 bits base64url, as issued by QueueService. */
    private const TOKEN = '/^[A-Za-z0-9_-]{16,64}$/';

    /** The printed label, e.g. A012 or D-104. */
    private const LABEL = '/^[A-Z]{1,3}-?[0-9]{1,4}$/i';

    /**
     * @return array{ok:bool,reason?:string,entry_id?:int,token?:string,label?:string,status?:string,how?:string}
     */
    public function resolve(int $clinicId, string $raw): array
    {
        $input = trim($raw);
        if ($input === '') {
            return ['ok' => false, 'reason' => 'Nothing was scanned.'];
        }

        // 1 & 2 — a tracking token, possibly wrapped in a URL.
        $token = $this->tokenFrom($input);
        if ($token !== null) {
            $entry = Db::one(
                "SELECT id, token_label, status, track_expires_at FROM queue_entries
                  WHERE clinic_id = ? AND track_token = ?",
                [$clinicId, $token]
            );
            if ($entry === null) {
                // Do not distinguish "another clinic's token" from "no such
                // token": that difference tells a scanner-holder that a slip
                // they found belongs to somebody, just not here.
                return ['ok' => false, 'reason' => 'That code is not a visit at this clinic.'];
            }
            if ($entry['track_expires_at'] !== null
                && strtotime((string) $entry['track_expires_at']) < time()) {
                return ['ok' => false, 'reason' => 'That visit has finished. Look it up by name instead.'];
            }
            return [
                'ok' => true,
                'entry_id' => (int) $entry['id'],
                'label' => (string) $entry['token_label'],
                'status' => (string) $entry['status'],
                'how' => 'tracking code',
            ];
        }

        // 3 — a printed token label, resolved within today's sessions only.
        if (preg_match(self::LABEL, $input) === 1) {
            $label = strtoupper($input);
            $rows = Db::all(
                "SELECT e.id, e.token_label, e.status
                   FROM queue_entries e
                   JOIN queues q ON q.id = e.queue_id
                  WHERE e.clinic_id = ? AND e.token_label = ? AND q.session_date = ?
                  ORDER BY e.id DESC",
                [$clinicId, $label, Db::today()]
            );
            if ($rows === []) {
                return ['ok' => false, 'reason' => 'No token ' . $label . ' was issued today.'];
            }
            if (count($rows) > 1) {
                // Two sessions can both have an A001. Guessing which one the
                // scanner meant would put a patient in the wrong queue.
                return ['ok' => false, 'reason' => 'Several tokens are labelled ' . $label . ' today. Search by name instead.'];
            }
            return [
                'ok' => true,
                'entry_id' => (int) $rows[0]['id'],
                'label' => (string) $rows[0]['token_label'],
                'status' => (string) $rows[0]['status'],
                'how' => 'token label',
            ];
        }

        return ['ok' => false, 'reason' => 'That does not look like a token or a tracking code.'];
    }

    /**
     * Pull a tracking token out of whatever the scanner produced.
     *
     * Handles `/t/<token>`, a full URL ending in it, and the bare token. A
     * `%`-escaped or quoted value is unescaped first, because a barcode reader
     * set to "keyboard wedge" can emit either.
     */
    private function tokenFrom(string $input): ?string
    {
        $value = trim($input, " \t\n\r\0\x0B\"'");

        if (str_contains($value, '/t/')) {
            $value = substr($value, (int) strrpos($value, '/t/') + 3);
            $value = rtrim($value, '/');
            // Strip any query or fragment a printed URL might carry.
            $value = preg_split('/[?#]/', $value)[0] ?? $value;
        }

        if (preg_match(self::TOKEN, $value) !== 1) {
            return null;
        }
        return $value;
    }
}
