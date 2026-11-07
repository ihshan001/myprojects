<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Onboarding — what a brand-new clinic still has to do, measured from data.
 *
 * Registration creates a working workspace: a clinic row, an owner, three
 * specialties, three rooms, one doctor, one open queue. Then the owner is
 * dropped on a dashboard with no statement of what is missing. The things
 * they most often miss are the ones that make the product *quietly wrong*
 * later: no doctor hours, so the booking page offers nothing; no real staff,
 * so everyone shares the owner's login; an unconfirmed owner email, which
 * blocks token issuing entirely.
 *
 * This class answers one question — is each of those actually done? — by
 * reading the tables rather than a checklist the owner ticked. A step is
 * complete when the data says so. Ticking a box that does not change the
 * clinic's state would be the exact dishonesty this project keeps fixing.
 */
final class OnboardingService
{
    /**
     * Every step, in the order a clinic actually needs them.
     *
     * @return array<int, array{key:string,title:string,why:string,done:bool,fix:?string,fix_label:string,blocking:bool}>
     */
    public function steps(int $clinicId): array
    {
        $clinic = Db::one('SELECT * FROM clinics WHERE id = ?', [$clinicId]) ?? [];

        // Opening hours are seven rows in `settings` (`hours.0` … `hours.6`),
        // written by the settings page and read by the public booking grid.
        // A clinic counts as open when at least one day is not `closed`.
        $openDays = 0;
        foreach (Db::all(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name LIKE 'hours.%'",
            [$clinicId]
        ) as $row) {
            if (trim((string) $row['value']) !== '' && !str_starts_with(trim((string) $row['value']), 'closed')) {
                $openDays++;
            }
        }
        $hasHours = $openDays > 0;

        $hasSchedule = (int) Db::scalar(
            'SELECT COUNT(*) FROM doctor_schedules WHERE clinic_id = ?', [$clinicId]
        ) > 0;

        $staffCount = (int) Db::scalar(
            "SELECT COUNT(*) FROM users WHERE clinic_id = ? AND is_active = 1", [$clinicId]
        );

        // DEVTEST: the owner-email step is gone with authentication. There is
        // no address to confirm and no gate in QueueService, so a checklist
        // item for it would be permanently open and permanently meaningless.

        $doctorCount = (int) Db::scalar(
            'SELECT COUNT(*) FROM doctors WHERE clinic_id = ? AND is_active = 1', [$clinicId]
        );

        $hasQueueToday = (int) Db::scalar(
            'SELECT COUNT(*) FROM queues WHERE clinic_id = ? AND session_date = ?', [$clinicId, Db::today()]
        ) > 0;

        $branded = trim((string) ($clinic['phone'] ?? '')) !== ''
            && trim((string) ($clinic['address_line'] ?? '')) !== '';

        return [
            [
                'key'      => 'hours',
                'title'    => 'Set the clinic’s opening hours',
                'why'      => 'The public booking page builds every slot from these. With none set it offers a patient nothing, and it looks like a broken page rather than an unconfigured clinic.',
                'done'     => $hasHours,
                'fix'      => '/settings',
                'fix_label' => 'Set opening hours',
                'blocking' => false,
            ],
            [
                'key'      => 'doctors',
                'title'    => 'Add the doctors who will actually see patients',
                'why'      => 'Registration creates one doctor so the app is not empty. A second room with no doctor attached cannot open a queue.',
                'done'     => $doctorCount >= 2,
                'fix'      => '/doctors',
                'fix_label' => 'Add doctors',
                'blocking' => false,
            ],
            [
                'key'      => 'schedules',
                'title'    => 'Give each doctor a weekly schedule',
                'why'      => 'A doctor with no schedule has no bookable sessions, and their appointments page reads as permanently full.',
                'done'     => $hasSchedule,
                'fix'      => '/calendar',
                'fix_label' => 'Set schedules',
                'blocking' => false,
            ],
            [
                'key'      => 'team',
                'title'    => 'Invite the staff who will use the desk',
                'why'      => 'Everyone sharing the owner’s login means the audit log cannot say who called a patient or who flagged a priority. Invitations are single-use and expiring.',
                'done'     => $staffCount >= 2,
                'fix'      => '/settings',
                'fix_label' => 'Invite staff',
                'blocking' => false,
            ],
            [
                'key'      => 'branding',
                'title'    => 'Add the clinic’s phone and address',
                'why'      => 'They appear on the token slip and the patient tracking page. A slip with no address is the thing a patient is holding when they ask how to get back.',
                'done'     => $branded,
                'fix'      => '/settings',
                'fix_label' => 'Add details',
                'blocking' => false,
            ],
            [
                'key'      => 'display',
                'title'    => 'Put the waiting-room screen up',
                'why'      => 'The display is the part that replaces someone calling names across a room. It needs one screen and the key from the settings page.',
                'done'     => Db::scalar(
                    "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name='onboarding.display_seen'",
                    [$clinicId]
                ) !== null,
                'fix'      => '/display',
                'fix_label' => 'Open the display',
                'blocking' => false,
            ],
            [
                'key'      => 'first_token',
                'title'    => 'Issue a token and watch the board',
                'why'      => 'The one step that proves the whole chain works end to end: reception, the queue, the display, the patient’s tracking link and the doctor’s call.',
                'done'     => $hasQueueToday && (int) Db::scalar(
                    'SELECT COUNT(*) FROM queue_entries WHERE clinic_id = ? AND joined_at >= ?',
                    [$clinicId, date('Y-m-d 00:00:00')]
                ) > 0,
                'fix'      => '/queue/new',
                'fix_label' => 'Issue a token',
                'blocking' => false,
            ],
        ];
    }

    /** @return array{done:int,total:int,percent:int,blocking_open:bool,next:?string} */
    public function summary(int $clinicId): array
    {
        $steps = $this->steps($clinicId);
        $done = count(array_filter($steps, static fn ($s) => $s['done']));
        $total = count($steps);
        $blockingOpen = false;
        $next = null;
        foreach ($steps as $step) {
            if (!$step['done']) {
                $next ??= $step['key'];
                if ($step['blocking']) {
                    $blockingOpen = true;
                }
            }
        }

        return [
            'done'           => $done,
            'total'          => $total,
            'percent'        => $total > 0 ? (int) round($done / $total * 100) : 100,
            'blocking_open'  => $blockingOpen,
            'next'           => $next,
        ];
    }

    /** Record that the owner has seen a screen, once. Never un-records it. */
    public function noteSeen(int $clinicId, string $key): void
    {
        $settingKey = 'onboarding.' . $key;
        $exists = Db::scalar(
            "SELECT value FROM settings WHERE scope='clinic' AND owner_id=? AND key_name=?",
            [$clinicId, $settingKey]
        );
        if ($exists === null) {
            Db::insert(
                'INSERT INTO settings (scope, owner_id, key_name, value) VALUES (?,?,?,?)',
                ['clinic', $clinicId, $settingKey, Db::now()]
            );
        }
    }
}
