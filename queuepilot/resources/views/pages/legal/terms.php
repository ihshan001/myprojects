<?php
/**
 * Terms of service — the clinic-facing contract summary.
 *
 * Written to be quotable in a support conversation rather than to fill a page.
 * The clauses that actually get argued about are the ones stated plainly:
 * who owns the patient data, what happens when a bill is unpaid, and what
 * happens to the data when an account closes.
 */
$company = $company !== '' ? $company : 'QueuePilot';
$contact = $contact !== '' ? $contact : 'the address on your invoice';
?>
<div class="mx-auto max-w-3xl">
  <div class="card card-pad">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Terms of service</h1>
    <p class="mt-1 text-sm text-slate-500">Between <?= e($company) ?> and the clinic. Last updated <?= e(date('F Y')) ?>.</p>

    <section class="mt-6 space-y-5 text-sm leading-relaxed text-slate-600">
      <div>
        <h2 class="text-base font-bold text-slate-900">1. What you are getting</h2>
        <p class="mt-1">A hosted queue-management system for one clinic, on its own
        address. We run the software and keep it working; you run the clinic.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">2. Patient data belongs to the clinic</h2>
        <p class="mt-1">Your clinic is the data controller for patient information and is
        responsible for having a lawful basis to collect it. We are the processor:
        we store and display it on your instructions only. We will not sell it,
        share it with another clinic, or use it for our own purposes.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">3. Payment</h2>
        <p class="mt-1">Billed monthly in advance, in the first week of the month, by bank
        transfer. A yearly option is available and is invoiced for the full year.
        Prices are in LKR and exclude any taxes that may apply.</p>
        <p class="mt-2"><strong>If an invoice is unpaid:</strong> your account keeps working for
        <strong>five more days</strong>, with a visible warning. After that it is suspended — the
        queue stops accepting patients. Suspension never deletes anything, and the
        account resumes the moment payment is confirmed.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">4. Trials</h2>
        <p class="mt-1">A trial is granted by us, for a stated number of days, to a named
        clinic. When it ends without payment the clinic is suspended, not deleted.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">5. Availability</h2>
        <p class="mt-1">We aim to keep the service available continuously and we tell you in
        advance when we plan downtime. We do not promise uninterrupted service, and
        we are not liable for losses caused by an outage — which is why the system
        includes a paper fallback for recording patients.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">6. Your responsibilities</h2>
        <ul class="mt-1 space-y-1">
          <li>· Keep staff accounts to people who should have them, and remove them when they leave.</li>
          <li>· Do not put clinical notes, diagnoses or test results into free-text fields.</li>
          <li>· Tell us promptly if you think an account has been compromised.</li>
          <li>· Do not attempt to access another clinic's data.</li>
        </ul>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">7. Closing your account</h2>
        <p class="mt-1">You may close at any time. Public pages are switched off immediately and
        your data is kept for <strong>90 days</strong> in case you change your mind, after which
        it is permanently deleted. Invoices already issued remain payable.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">8. Suspension by us</h2>
        <p class="mt-1">We may suspend an account for non-payment, for use that endangers other
        clinics, or where we are required to by law. Except for non-payment we will
        tell you why.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">9. Governing law</h2>
        <p class="mt-1">The laws of Sri Lanka. Disputes go to the courts of Colombo.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">10. Contact</h2>
        <p class="mt-1"><?= e($contact) ?></p>
      </div>
    </section>

    <p class="mt-8 border-t border-slate-100 pt-4 text-xs text-slate-400">
      This is a plain-language summary for reading. Have a lawyer review it before
      relying on it in a dispute.
    </p>
  </div>
</div>
