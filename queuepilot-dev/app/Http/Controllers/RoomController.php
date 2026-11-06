<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\AuditService;

/**
 * Consultation rooms.
 *
 * Rooms were created only as a side effect of self-service signup — three
 * defaults called "Room 1", "Room 2", "Room 3" — and after that they were
 * read-only forever. A clinic that moved premises, added a treatment room or
 * wanted to name rooms after the doctors who use them had no way to say so, and
 * the waiting-room display kept announcing "Room 2" to patients who had never
 * heard the name.
 *
 * The rules:
 *
 *   * A room name is unique within a clinic. Two "Room 1"s make the display
 *     ambiguous, which is the one thing a room label exists to prevent.
 *   * A room in use cannot be deleted. Detaching it from the doctor silently
 *     would send patients to a room nobody is in. The delete is refused with the
 *     count of doctors still assigned.
 *   * Deleting a room that nothing points at is safe, because the display
 *     falls back to the queue name when there is no room.
 */
final class RoomController
{
    private function clinicId(): int
    {
        $id = Auth::clinicId();
        if ($id === null) {
            abort(403, 'Rooms belong to a clinic.');
        }
        return $id;
    }

    public function index(): void
    {
        $clinicId = $this->clinicId();

        view('rooms/index', [
            'title' => 'Rooms',
            'rooms' => Db::all(
                'SELECT r.*,
                        (SELECT COUNT(*) FROM doctors d WHERE d.room_id = r.id AND d.is_active = 1) AS doctors,
                        (SELECT GROUP_CONCAT(d.name, ", ") FROM doctors d WHERE d.room_id = r.id AND d.is_active = 1) AS doctor_names
                   FROM rooms r
                  WHERE r.clinic_id = ?
                  ORDER BY r.floor, r.name',
                [$clinicId]
            ),
            'canManage' => Auth::can('doctor.manage'),
            'active' => 'rooms',
        ]);
    }

    public function store(): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('doctor.manage')) {
            abort(403, 'Only a clinic owner can add rooms.');
        }

        $name = trim(Request::string('name'));
        $floor = trim(Request::string('floor'));

        if (mb_strlen($name) < 1) {
            flash('error', 'Give the room a name. "Consulting 1" is a name; "Room" on its own is not.');
            redirect('/rooms');
            return;
        }
        if (mb_strlen($name) > 60) {
            flash('error', 'Keep the room name under 60 characters — it is read from across a waiting room.');
            redirect('/rooms');
            return;
        }

        // Case-insensitive: "Room 1" and "room 1" are the same room to a patient.
        $exists = Db::scalar(
            'SELECT id FROM rooms WHERE clinic_id = ? AND lower(name) = lower(?)',
            [$clinicId, $name]
        );
        if ($exists !== null) {
            flash('error', 'A room called "' . $name . '" already exists in this clinic.');
            redirect('/rooms');
            return;
        }

        $id = Db::insert(
            'INSERT INTO rooms (clinic_id, name, floor) VALUES (?,?,?)',
            [$clinicId, $name, $floor !== '' ? $floor : null]
        );
        (new AuditService())->log($clinicId, 'room.created', 'room', $id, ['name' => $name, 'floor' => $floor]);

        flash('success', $name . ' added. It can now be assigned to a doctor.');
        redirect('/rooms');
    }

    public function update(string $id): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('doctor.manage')) {
            abort(403, 'Only a clinic owner can edit rooms.');
        }

        $room = Db::one('SELECT * FROM rooms WHERE id = ? AND clinic_id = ?', [(int) $id, $clinicId]);
        if (!$room) {
            abort(404, 'That room does not exist in this clinic.');
            return;
        }

        $name = trim(Request::string('name'));
        $floor = trim(Request::string('floor'));

        if ($name === '') {
            flash('error', 'A room needs a name.');
            redirect('/rooms');
            return;
        }

        $clash = Db::scalar(
            'SELECT id FROM rooms WHERE clinic_id = ? AND lower(name) = lower(?) AND id <> ?',
            [$clinicId, $name, (int) $id]
        );
        if ($clash !== null) {
            flash('error', 'Another room is already called "' . $name . '".');
            redirect('/rooms');
            return;
        }

        Db::run(
            'UPDATE rooms SET name = ?, floor = ? WHERE id = ? AND clinic_id = ?',
            [$name, $floor !== '' ? $floor : null, (int) $id, $clinicId]
        );
        (new AuditService())->log($clinicId, 'room.renamed', 'room', (int) $id, [
            'from' => $room['name'], 'to' => $name,
        ]);

        flash('success', 'Renamed. Any display showing this room updates on its next poll.');
        redirect('/rooms');
    }

    public function destroy(string $id): void
    {
        $clinicId = $this->clinicId();
        if (!Auth::can('doctor.manage')) {
            abort(403, 'Only a clinic owner can remove rooms.');
        }

        $room = Db::one('SELECT * FROM rooms WHERE id = ? AND clinic_id = ?', [(int) $id, $clinicId]);
        if (!$room) {
            abort(404, 'That room does not exist in this clinic.');
            return;
        }

        $inUse = (int) Db::scalar(
            'SELECT COUNT(*) FROM doctors WHERE room_id = ? AND clinic_id = ? AND is_active = 1',
            [(int) $id, $clinicId]
        );
        if ($inUse > 0) {
            // Refuse rather than silently unassign: a doctor with no room still
            // consults, and the display would then announce the queue name to
            // patients waiting for a room number.
            flash('error', sprintf(
                '%s is still assigned to %d doctor%s. Move %s first — otherwise the display starts announcing the queue name instead of a room.',
                $room['name'],
                $inUse,
                $inUse === 1 ? '' : 's',
                $inUse === 1 ? 'them' : 'them'
            ));
            redirect('/rooms');
            return;
        }

        Db::run('DELETE FROM rooms WHERE id = ? AND clinic_id = ?', [(int) $id, $clinicId]);
        (new AuditService())->log($clinicId, 'room.deleted', 'room', (int) $id, ['name' => $room['name']]);

        flash('success', $room['name'] . ' removed.');
        redirect('/rooms');
    }
}
