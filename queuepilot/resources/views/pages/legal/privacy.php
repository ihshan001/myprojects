<?php
/**
 * Privacy notice — written for the patient, not for a lawyer.
 *
 * The PDPA requires specific information at the point of collection. This page
 * supplies it in the order a person actually asks the questions: who has my
 * data, what do they have, why, who else sees it, how long, and how do I make
 * them stop. The legal minimum is a checklist; this is the checklist arranged
 * so somebody standing at a clinic desk can read it in a minute.
 */
$company = $company !== '' ? $company : (string) \App\Core\Env::get('app.name', 'QueuePilot');
$contact = $contact !== '' ? $contact : 'the address on your clinic receipt';
?>
<div class="mx-auto max-w-3xl">
  <div class="card card-pad">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900">Privacy notice</h1>
    <p class="mt-1 text-sm text-slate-500">
      How patient information is handled by the queue system your clinic uses.
      Last updated <?= e(date('F Y')) ?>.
    </p>

    <div class="mt-6 rounded-lg bg-teal-50 p-4 ring-1 ring-teal-200">
      <p class="text-sm font-semibold text-teal-900">The short version</p>
      <p class="mt-1 text-sm leading-relaxed text-teal-900">
        <strong>Your clinic owns your information. <?= e($appName ?? \App\Core\Env::get('app.name', 'QueuePilot')) ?> only stores it for them.</strong>
        Your clinic decides what is collected and why. <?= e($appName ?? \App\Core\Env::get('app.name', 'QueuePilot')) ?> provides the
        software and is not allowed to use your information for anything else.
      </p>
    </div>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Who is responsible</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        The clinic you are visiting is the <strong>data controller</strong> under the
        Personal Data Protection Act No. 9 of 2022. If you want your information
        corrected, exported or deleted, ask the clinic — they are the ones who
        can decide.
      </p>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        <strong><?= e($company) ?></strong> is the <strong>data processor</strong>. We run
        the queue software on the clinic's instructions. We do not sell
        information, we do not use it for advertising, and we do not look at it
        except when fixing a fault the clinic has reported.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">What is collected</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        Your name, a contact telephone number, and — if the clinic records them —
        your date of birth, gender, and the reason for your visit. The system also
        records when you joined the queue, when you were called, and which room
        you went to.
      </p>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        It does <strong>not</strong> hold clinical notes, test results, prescriptions or
        diagnoses. Those stay in the clinic's own medical records.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Why, and on what basis</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        To manage the queue and call you when it is your turn. The lawful basis
        is your clinic's provision of healthcare to you — you cannot be called
        for an appointment without a name and a way to reach you.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Who else can see it</h2>
      <ul class="mt-2 space-y-1.5 text-sm leading-relaxed text-slate-600">
        <li>· Staff at your clinic who are signed in to run the queue.</li>
        <li>· Other patients see only a <strong>token number</strong> on the waiting-room screen — never a name.</li>
        <li>· The person tracking a visit holds a private link. Anyone with that link can see the token and queue position, so treat it like a ticket.</li>
        <li>· Nobody else. Information is not shared between clinics, not sold, and not given to advertisers.</li>
      </ul>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Where it is stored</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        On servers operated for <?= e($company) ?> in Sri Lanka. Information is not
        transferred outside Sri Lanka without the clinic's agreement.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">How long it is kept</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        Queue records are kept for as long as the clinic uses the service, and for
        up to <strong>90 days</strong> after a clinic closes its account, in case they need
        to reopen it. After that the records are permanently deleted or reduced to
        anonymous statistics. Your clinic may keep its own records for longer under
        medical record-keeping rules — ask them.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Your rights</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        You may ask to see what is held about you, have it corrected, ask for a
        copy, or ask for it to be deleted. Ask your clinic; they decide. If you
        believe your information has been mishandled you may complain to the
        <strong>Data Protection Authority of Sri Lanka</strong>.
      </p>
    </section>

    <section class="mt-7">
      <h2 class="text-base font-bold text-slate-900">Contact</h2>
      <p class="mt-2 text-sm leading-relaxed text-slate-600">
        For the software: <?= e($contact) ?>. For your own medical records: your clinic.
      </p>
    </section>

    <p class="mt-8 border-t border-slate-100 pt-4 text-xs text-slate-400">
      This notice describes the queue system. It is not legal advice, and your
      clinic's own privacy notice may be more specific — where the two differ,
      the clinic's notice applies to your treatment.
    </p>
  </div>
</div>
