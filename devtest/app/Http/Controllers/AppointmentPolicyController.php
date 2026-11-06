<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AppointmentPolicyService;
use App\Services\AuditService;
use App\Services\SlotService;

/**
 * Appointment policies, per service.
 *
 * The clinic-wide numbers on the settings page remain the default. This screen
 * exists because a laboratory and a ten-minute consultation cannot share one
 * late-grace figure, and a clinic that has never used it behaves exactly as it
 * did before — every field here is an override, and blank means inherit.
 */
final class AppointmentPolicyController
{
    public function __construct(
        private readonly AppointmentPolicyService $policies = new AppointmentPolicyService(),
        private readonly AuditService $audit = new AuditService(),
    ) {}

    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Appointment policies belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        $resolved = [];
        foreach (array_keys(AppointmentPolicyService::KINDS) as $kind) {
            $resolved[$kind] = $this->policies->resolve($clinicId, $kind);
        }

        view('queues/appointment-policies', [
            'title'    => 'Appointment policies',
            'policies' => $this->policies->all($clinicId, false),
            'kinds'    => AppointmentPolicyService::KINDS,
            'resolved' => $resolved,
            'doctors'  => Db::all('SELECT id, name FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name', [$clinicId]),
            'defaults' => [
                'late_grace_minutes' => max(0, (int) (Db::scalar('SELECT late_grace_minutes FROM clinics WHERE id = ?', [$clinicId]) ?? 15)),
                'horizon_days'       => (new SlotService())->horizonDays($clinicId),
            ],
            'old'      => [],
            'errors'   => [],
        ]);
    }

    public function save(): void
    {
        $clinicId = $this->clinicId();
        $in = Request::payload();

        try {
            $id = $this->policies->save($clinicId, $in, Auth::id());
        } catch (\InvalidArgumentException $e) {
            view('queues/appointment-policies', [
                'title'    => 'Appointment policies',
                'policies' => $this->policies->all($clinicId, false),
                'kinds'    => AppointmentPolicyService::KINDS,
                'resolved' => array_combine(
                    array_keys(AppointmentPolicyService::KINDS),
                    array_map(fn ($k) => $this->policies->resolve($clinicId, $k), array_keys(AppointmentPolicyService::KINDS))
                ),
                'doctors'  => Db::all('SELECT id, name FROM doctors WHERE clinic_id = ? AND is_active = 1 ORDER BY name', [$clinicId]),
                'defaults' => [
                    'late_grace_minutes' => max(0, (int) (Db::scalar('SELECT late_grace_minutes FROM clinics WHERE id = ?', [$clinicId]) ?? 15)),
                    'horizon_days'       => (new SlotService())->horizonDays($clinicId),
                ],
                'old'      => $in,
                'errors'   => [$e->getMessage()],
            ]);
            return;
        }

        $this->audit->log($clinicId, 'appointment_policy.saved', 'appointment_policy', $id, [
            'kind' => (string) ($in['kind'] ?? ''),
            'doctor_id' => $in['doctor_id'] ?? null,
        ]);
        flash('success', 'Policy saved. It applies to bookings from now on; appointments already made keep their terms.');
        redirect('/queues/appointment-policies');
    }

    public function delete(string $id): void
    {
        $clinicId = $this->clinicId();
        $policyId = (int) $id;
        if (!$this->policies->delete($clinicId, $policyId)) {
            abort(404, 'Policy not found.');
        }
        $this->audit->log($clinicId, 'appointment_policy.deleted', 'appointment_policy', $policyId, []);
        flash('success', 'Policy removed. That service falls back to the clinic default.');
        redirect('/queues/appointment-policies');
    }
}
