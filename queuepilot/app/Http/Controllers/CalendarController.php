<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\DoctorService;
use App\Services\HolidayService;
use App\Services\ScheduleService;

/**
 * Operating calendar: doctor weekly schedules and clinic closure days.
 *
 * Both live on one screen because they answer the same question for the
 * booking page ("is this doctor available that day?") and a clinic manager
 * who has to visit two pages to answer it will keep one of them wrong.
 */
final class CalendarController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Not attached to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();
        $doctorId = Request::int('doctor_id');

        $doctors = (new DoctorService())->all($clinicId, true);
        if ($doctorId <= 0 && $doctors !== []) {
            $doctorId = (int) $doctors[0]['id'];
        }

        $schedule = new ScheduleService();
        $selected = null;
        foreach ($doctors as $d) {
            if ((int) $d['id'] === $doctorId) {
                $selected = $d;
                break;
            }
        }

        view('calendar/index', [
            'title' => 'Schedules & holidays',
            'doctors' => $doctors,
            'selectedDoctor' => $selected,
            'week' => $selected !== null ? $schedule->asWeek($clinicId, $doctorId) : array_fill(0, 7, []),
            'hasSchedule' => $selected !== null && $schedule->hasSchedule($clinicId, $doctorId),
            'holidays' => (new HolidayService())->all($clinicId),
            'canManage' => Auth::can('clinic.manage') || Auth::can('doctor.manage'),
            'active' => 'calendar',
        ]);
    }

    public function saveSchedule(): void
    {
        $clinicId = $this->clinicId();
        $doctorId = Request::int('doctor_id');

        // The form posts `day_<weekday>_from[]`/`day_<weekday>_to[]` pairs.
        // Reading them as parallel arrays keeps the template simple and lets a
        // doctor have two shifts in one day (morning clinic, evening clinic).
        $week = [];
        for ($dow = 0; $dow <= 6; $dow++) {
            $froms = (array) Request::input('day_' . $dow . '_from', []);
            $tos = (array) Request::input('day_' . $dow . '_to', []);
            foreach ($froms as $i => $from) {
                $to = $tos[$i] ?? '';
                if (trim((string) $from) === '' && trim((string) $to) === '') {
                    continue;
                }
                $week[$dow][] = [(string) $from, (string) $to];
            }
        }

        $result = (new ScheduleService())->saveWeek($clinicId, $doctorId, $week);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Schedule saved. The booking page now follows it.'
            : $result['error']);
        redirect('/calendar?doctor_id=' . $doctorId);
    }

    public function clearSchedule(): void
    {
        $clinicId = $this->clinicId();
        $doctorId = Request::int('doctor_id');

        if (!Db::one('SELECT id FROM doctors WHERE id = ? AND clinic_id = ?', [$doctorId, $clinicId])) {
            flash('error', 'That doctor does not belong to this clinic.');
            redirect('/calendar');
            return;
        }

        Db::run('DELETE FROM doctor_schedules WHERE clinic_id = ? AND doctor_id = ?', [$clinicId, $doctorId]);
        (new AuditService())->log($clinicId, 'doctor.schedule_cleared', 'doctor', $doctorId);
        flash('success', 'Schedule cleared. This doctor now follows the clinic hours.');
        redirect('/calendar?doctor_id=' . $doctorId);
    }

    public function addHoliday(): void
    {
        $clinicId = $this->clinicId();
        $date = Request::string('date');
        $name = Request::string('name');

        $result = (new HolidayService())->add($clinicId, $date, $name, Auth::id());
        if (!$result['ok']) {
            flash('error', $result['error']);
            redirect('/calendar');
            return;
        }

        // Bookings already made for that date do not vanish: they are a
        // commitment the clinic has to act on, so the count is part of the
        // confirmation rather than discovered on the day.
        $booked = (new HolidayService())->appointmentsOn($clinicId, $date);
        flash('success', $booked > 0
            ? 'Holiday saved. ' . $booked . ' appointment(s) already booked that day need rebooking — the booking page is now closed for it.'
            : 'Holiday saved. The booking page is now closed for that date.');
        redirect('/calendar');
    }

    public function removeHoliday(string $id): void
    {
        $clinicId = $this->clinicId();
        $result = (new HolidayService())->remove($clinicId, (int) $id);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Holiday removed.' : $result['error']);
        redirect('/calendar');
    }
}
