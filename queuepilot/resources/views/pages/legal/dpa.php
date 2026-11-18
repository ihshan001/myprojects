<?php
/**
 * Data processing agreement — the clinic signs this.
 *
 * Under the PDPA a controller may only use a processor who provides sufficient
 * guarantees, and the arrangement must be in writing. This is that writing. It
 * is short on purpose: a DPA nobody reads is not a safeguard.
 */
$company = $company !== '' ? $company : 'QueuePilot';
$contact = $contact !== '' ? $contact : 'the address on your invoice';
?>
<div class="mx-auto max-w-3xl">
  <div class="card card-pad">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Data processing agreement</h1>
    <p class="mt-1 text-sm text-slate-500">
      To be accepted by each clinic before it starts using the service. Last updated <?= e(date('F Y')) ?>.
    </p>

    <div class="mt-5 rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200">
      <p class="text-sm text-slate-700">
        <strong>Controller:</strong> the clinic (name and address as registered).<br>
        <strong>Processor:</strong> <?= e($company) ?>.
      </p>
    </div>

    <section class="mt-6 space-y-5 text-sm leading-relaxed text-slate-600">
      <div>
        <h2 class="text-base font-bold text-slate-900">1. Scope</h2>
        <p class="mt-1">The processor stores patient names, contact numbers, optional
        demographic details, and queue events, for the sole purpose of operating the
        clinic's queue. No clinical records are processed.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">2. The processor will</h2>
        <ul class="mt-1 space-y-1">
          <li>· Process personal data only on the controller's documented instructions.</li>
          <li>· Keep it confidential and restrict access to staff who need it to run the service.</li>
          <li>· Apply appropriate technical measures: per-clinic separation of records, per-clinic hostnames and sessions, hashed passwords, encrypted second factors, audit logging of staff actions.</li>
          <li>· Assist the controller in responding to a data subject's request.</li>
          <li>· Notify the controller <strong>without undue delay</strong> after becoming aware of a personal data breach.</li>
          <li>· Delete or return all personal data at the end of the arrangement, as described in the terms of service.</li>
        </ul>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">3. Sub-processors</h2>
        <p class="mt-1">Hosting is provided on infrastructure operated for the processor in
        Sri Lanka. No other party receives personal data. The processor will give the
        controller notice before engaging a new sub-processor.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">4. Transfers</h2>
        <p class="mt-1">Personal data is not transferred outside Sri Lanka without the
        controller's written agreement.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">5. Audits</h2>
        <p class="mt-1">The controller may ask for a written description of the technical
        measures, once a year and after any breach.</p>
      </div>

      <div>
        <h2 class="text-base font-bold text-slate-900">6. Liability</h2>
        <p class="mt-1">Each party is responsible for its own compliance with the Personal Data
        Protection Act No. 9 of 2022. The processor is not liable for processing the
        controller instructed that was unlawful to begin with.</p>
      </div>
    </section>

    <div class="mt-7 border-t border-slate-100 pt-5">
      <p class="text-sm text-slate-600">
        This agreement is accepted when a clinic submits its application, by the
        checkbox on the signup form. A second "accept" button would create a
        second record of the same consent and no clearer answer about which one
        counts.
      </p>
    </div>

    <p class="mt-4 text-xs text-slate-400">Contact: <?= e($contact) ?></p>
  </div>
</div>
