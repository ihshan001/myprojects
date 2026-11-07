<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Services\OrganisationService;

/**
 * Groups of clinics, from the platform side.
 *
 * A group exists so a business running several branches can see one roll-up
 * instead of signing into each. Creating one and moving a clinic into it is a
 * platform decision, not a clinic one: attaching a clinic changes who can see a
 * summary of its activity, which crosses the tenancy boundary the rest of the
 * product is careful about.
 *
 * The clinic-facing roll-up already existed at /organisation; this is the
 * missing half, the screen that creates the group in the first place.
 */
final class AdminOrganisationController
{
    private function guard(): void
    {
        if (!Auth::isSuper()) {
            abort(403, 'Only the platform team can manage groups.');
        }
    }

    public function index(): void
    {
        $this->guard();
        $service = new OrganisationService();

        $orgs = [];
        foreach ($service->all() as $org) {
            // Assign rather than merge: `all()` already returns a `branches`
            // count, and `$row + [...]` keeps the left-hand value, so merging
            // left the view counting an int.
            $org['branch_list'] = $service->branches((int) $org['id']);
            $org['rollup'] = $service->rollup((int) $org['id']);
            $orgs[] = $org;
        }

        view('admin/organisations', [
            'title' => 'Groups',
            'orgs' => $orgs,
            // Clinics not yet in a group, offered as candidates to attach.
            'loose' => Db::all(
                'SELECT c.id, c.name, c.city, p.name AS plan_name
                   FROM clinics c LEFT JOIN plans p ON p.id = c.plan_id
                  WHERE c.organisation_id IS NULL AND c.closed_at IS NULL
                  ORDER BY c.name'
            ),
        ]);
    }

    public function store(): void
    {
        $this->guard();

        $name = Request::string('name');
        $owner = Request::string('owner_email');
        $notes = Request::string('notes');

        if ($owner !== '' && filter_var($owner, FILTER_VALIDATE_EMAIL) === false) {
            flash('error', 'That contact email does not look like an address.');
            redirect('/admin/organisations');
            return;
        }

        $result = (new OrganisationService())->create($name, $owner !== '' ? $owner : null, $notes !== '' ? $notes : null);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Group created. Attach branches below.'
            : (string) $result['error']);
        redirect('/admin/organisations');
    }

    public function attach(string $id): void
    {
        $this->guard();

        $clinicId = Request::int('clinic_id');
        if ($clinicId <= 0) {
            flash('error', 'Choose a clinic to attach.');
            redirect('/admin/organisations');
            return;
        }

        $result = (new OrganisationService())->attachClinic((int) $id, $clinicId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Branch attached. Its admin now sees the group roll-up.'
            : (string) $result['error']);
        redirect('/admin/organisations');
    }

    public function detach(string $id): void
    {
        $this->guard();

        $clinicId = Request::int('clinic_id');
        if ($clinicId <= 0) {
            flash('error', 'Choose a clinic to detach.');
            redirect('/admin/organisations');
            return;
        }

        // Refuse if the clinic is not in this group — the form is a plain POST
        // and a stale page must not move an unrelated branch.
        $actual = Db::scalar('SELECT organisation_id FROM clinics WHERE id = ?', [$clinicId]);
        if ((int) $actual !== (int) $id) {
            flash('error', 'That clinic is not a branch of this group. Refresh and try again.');
            redirect('/admin/organisations');
            return;
        }

        $result = (new OrganisationService())->detachClinic($clinicId);
        flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Branch detached. It is a standalone clinic again.'
            : (string) $result['error']);
        redirect('/admin/organisations');
    }
}
