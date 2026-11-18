<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\View;

/**
 * The legal pages the PDPA requires at the point of collection.
 *
 * Not legal advice, and deliberately written to be read by a person: Sri Lanka's
 * Personal Data Protection Act No. 9 of 2022 requires a controller to tell a
 * data subject, before or at the point of collecting their data, who the
 * controller is, what is collected, why, the lawful basis, who it is shared
 * with, whether it leaves the country, and how long it is kept. A privacy
 * policy that only a lawyer can find is not compliance; a notice at the desk
 * where the patient's name is typed is.
 *
 * The division of roles is the part that matters most here and is stated
 * plainly on each page:
 *
 *   the CLINIC is the controller  — it decides why patient data is processed
 *   QueuePilot is the PROCESSOR   — it stores and displays on the clinic's
 *                                   instructions and does not decide anything
 *
 * That division is what makes this business lawful at all, so it is stated on
 * the page rather than buried in a contract.
 */
final class LegalController
{
    public function privacy(): void
    {
        view('legal/privacy', [
            'title' => 'Privacy notice',
            'company' => \App\Core\Env::get('app.company', ''),
            'contact' => \App\Core\Env::get('app.company_contact', ''),
        ]);
    }

    public function terms(): void
    {
        view('legal/terms', [
            'title' => 'Terms of service',
            'company' => \App\Core\Env::get('app.company', ''),
            'contact' => \App\Core\Env::get('app.company_contact', ''),
        ]);
    }

    public function dpa(): void
    {
        view('legal/dpa', [
            'title' => 'Data processing agreement',
            'company' => \App\Core\Env::get('app.company', ''),
            'contact' => \App\Core\Env::get('app.company_contact', ''),
        ]);
    }
}
