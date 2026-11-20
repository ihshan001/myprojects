<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= e((string) $invoice['number']) ?> — <?= e((string) $clinic['name']) ?></title>
<style>
  /* Self-contained on purpose. An invoice gets saved to PDF, emailed to an
     accountant and printed on whatever printer the clinic owns; it must not
     depend on the app's stylesheet loading, and it must not print the admin
     chrome. */
  * { box-sizing: border-box; }
  body { font: 13px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         color: #0f172a; margin: 0; padding: 32px; background: #fff; }
  .sheet { max-width: 760px; margin: 0 auto; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
  h1 { font-size: 26px; letter-spacing: -0.02em; margin: 0 0 4px; }
  .muted { color: #64748b; }
  .small { font-size: 11px; }
  .mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
  .seller { text-align: right; }
  .seller strong { display: block; font-size: 14px; }
  hr { border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0; }
  .grid { display: flex; gap: 32px; justify-content: space-between; }
  .grid > div { flex: 1; }
  .label { font-size: 10px; text-transform: uppercase; letter-spacing: .07em; color: #94a3b8; font-weight: 700; margin-bottom: 5px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em;
       color: #64748b; border-bottom: 1px solid #e2e8f0; padding: 7px 0; }
  td { padding: 9px 0; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  td.n, th.n { text-align: right; }
  .total { font-size: 19px; font-weight: 700; }
  .stamp { display: inline-block; padding: 3px 9px; border-radius: 5px; font-size: 10px;
           font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
  .s-paid { background: #d1fae5; color: #065f46; }
  .s-unpaid { background: #fef3c7; color: #92400e; }
  .s-claimed { background: #ccfbf1; color: #115e59; }
  .s-rejected, .s-void { background: #ffe4e6; color: #9f1239; }
  .bank { border: 1px solid #e2e8f0; border-radius: 9px; padding: 14px 16px; background: #f8fafc; }
  .bank dl { display: grid; grid-template-columns: 9rem 1fr; gap: 4px 14px; margin: 8px 0 0; }
  .bank dt { color: #64748b; font-weight: 600; }
  .bank dd { margin: 0; font-weight: 600; }
  .note { margin-top: 26px; font-size: 10.5px; color: #64748b; line-height: 1.6; }
  .actions { max-width: 760px; margin: 0 auto 18px; display: flex; gap: 8px; }
  .actions button, .actions a { font: inherit; font-size: 12px; padding: 7px 14px; border-radius: 7px;
    border: 1px solid #cbd5e1; background: #fff; color: #0f172a; cursor: pointer; text-decoration: none; }
  .actions button:hover, .actions a:hover { background: #f1f5f9; }
  @media print {
    body { padding: 0; font-size: 12px; }
    .actions { display: none; }
    .sheet { max-width: none; }
  }
</style>
</head>
<body>

<div class="actions">
  <button data-print type="button">Print / save as PDF</button>
  <a href="/billing">Back to billing</a>
</div>

<?php /* This is a standalone document, so it has to load the bundle itself —
         the layout's script tag is not here. data-print is bound by app.js, and
         an inline handler would be blocked by the CSP with no console error. */ ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>

<div class="sheet">

  <div class="top">
    <div>
      <h1>Invoice</h1>
      <p class="muted small mono" style="margin:0"><?= e((string) $invoice['number']) ?></p>
    </div>
    <div class="seller">
      <strong><?= e((string) ($company['legal_name'] ?? $appName)) ?></strong>
      <?php if (!empty($company['address'])): ?>
        <span class="muted small"><?= e((string) $company['address']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($company['reg_number'])): ?>
        <span class="muted small">Reg. no. <?= e((string) $company['reg_number']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($company['vat_number'])): ?>
        <span class="muted small">VAT <?= e((string) $company['vat_number']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($company['email'])): ?>
        <span class="muted small"><?= e((string) $company['email']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($company['phone'])): ?>
        <span class="muted small"><?= e((string) $company['phone']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <hr>

  <div class="grid">
    <div>
      <p class="label">Billed to</p>
      <strong><?= e((string) ($clinic['legal_name'] ?: $clinic['name'])) ?></strong><br>
      <?php if (!empty($clinic['address_line'])): ?>
        <span class="muted small"><?= e((string) $clinic['address_line']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($clinic['city'])): ?>
        <span class="muted small"><?= e((string) $clinic['city']) ?><?= !empty($clinic['district']) ? ', ' . e((string) $clinic['district']) : '' ?></span><br>
      <?php endif; ?>
      <?php if (!empty($clinic['billing_email']) || !empty($clinic['email'])): ?>
        <span class="muted small"><?= e((string) ($clinic['billing_email'] ?: $clinic['email'])) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($clinic['tax_id'])): ?>
        <span class="muted small">VAT / TIN: <?= e((string) $clinic['tax_id']) ?></span><br>
      <?php endif; ?>
      <?php if (!empty($clinic['po_number'])): ?>
        <span class="muted small">Your PO: <strong><?= e((string) $clinic['po_number']) ?></strong></span>
      <?php endif; ?>
    </div>
    <div style="text-align:right">
      <p class="label">Details</p>
      <span class="small">Issued <strong><?= e(substr((string) $invoice['period_to'], 0, 10)) ?></strong></span><br>
      <span class="small">Period <strong><?= e(substr((string) $invoice['period_from'], 0, 10)) ?> → <?= e(substr((string) $invoice['period_to'], 0, 10)) ?></strong></span><br>
      <?php if (!empty($invoice['due_on'])): ?>
        <span class="small">Due <strong><?= e(substr((string) $invoice['due_on'], 0, 10)) ?></strong></span><br>
      <?php endif; ?>
      <span class="stamp s-<?= e((string) $invoice['status']) ?>" style="margin-top:6px"><?= e((string) $invoice['status']) ?></span>
    </div>
  </div>

  <table>
    <thead>
      <tr><th>Description</th><th class="n">Amount</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <?= e((string) ($planName !== '' ? $planName : 'Subscription')) ?> —
          <?= e((string) ($clinic['billing_cycle'] ?? 'monthly') === 'yearly' ? 'annual' : 'monthly') ?> subscription
          <div class="muted small"><?= e(substr((string) $invoice['period_from'], 0, 10)) ?> to <?= e(substr((string) $invoice['period_to'], 0, 10)) ?></div>
        </td>
        <td class="n mono"><?= e(money((float) $invoice['amount'])) ?></td>
      </tr>
      <?php if ((float) ($invoice['usage_amount'] ?? 0) > 0): ?>
        <tr>
          <td>
            SMS &amp; WhatsApp usage
            <div class="muted small">Metered at cost plus a small margin, never bundled into the plan price.</div>
          </td>
          <td class="n mono"><?= e(money((float) $invoice['usage_amount'])) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td class="n" style="border:0;padding-top:14px"><strong>Total due</strong></td>
        <td class="n mono total" style="border:0;padding-top:14px"><?= e(money((float) $invoice['amount'] + (float) ($invoice['usage_amount'] ?? 0))) ?> <?= e((string) ($invoice['currency'] ?? 'LKR')) ?></td>
      </tr>
    </tfoot>
  </table>

  <?php if (in_array((string) $invoice['status'], ['unpaid', 'rejected'], true)): ?>
    <div class="bank" style="margin-top:20px">
      <p style="margin:0;font-weight:700">How to pay</p>
      <p class="small muted" style="margin:3px 0 0">
        Bank transfer only. Quote <strong class="mono"><?= e((string) $invoice['number']) ?></strong> as the
        reference so the payment can be matched to your clinic.
      </p>
      <?php if (!empty($company['bank_account_no'])): ?>
        <dl>
          <dt>Account name</dt><dd><?= e((string) ($company['bank_account_name'] ?? '')) ?></dd>
          <dt>Bank</dt><dd><?= e((string) ($company['bank'] ?? '')) ?></dd>
          <?php if (!empty($company['bank_branch'])): ?>
            <dt>Branch</dt><dd><?= e((string) $company['bank_branch']) ?></dd>
          <?php endif; ?>
          <dt>Account no.</dt><dd class="mono"><?= e((string) $company['bank_account_no']) ?></dd>
          <?php if (!empty($company['bank_swift'])): ?>
            <dt>SWIFT</dt><dd class="mono"><?= e((string) $company['bank_swift']) ?></dd>
          <?php endif; ?>
        </dl>
      <?php else: ?>
        <p class="small" style="margin:8px 0 0;color:#92400e">
          Bank details are not configured on this installation. Contact us before sending any money.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ((string) $invoice['status'] === 'paid'): ?>
    <p class="small" style="margin-top:18px">
      Paid<?= !empty($invoice['paid_at']) ? ' on ' . e(substr((string) $invoice['paid_at'], 0, 10)) : '' ?><?= !empty($invoice['reference']) ? ' — reference ' . e((string) $invoice['reference']) : '' ?>.
      Thank you.
    </p>
  <?php endif; ?>

  <p class="note">
    This invoice covers a software subscription. <?= e((string) ($company['legal_name'] ?? $appName)) ?>
    does not provide medical services and is not a party to the clinical relationship between
    <?= e((string) $clinic['name']) ?> and its patients. Under the Personal Data Protection Act
    No. 9 of 2022 the clinic is the data controller; the platform processes patient data on the
    clinic's instructions only.
  </p>

</div>
</body>
</html>
