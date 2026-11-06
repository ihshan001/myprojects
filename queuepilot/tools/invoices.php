<?php
declare(strict_types=1);

/**
 * Raise every invoice that is due.
 *
 *   php tools/invoices.php              # show what is due, change nothing
 *   php tools/invoices.php --yes        # actually create them
 *   php tools/invoices.php --yes --today 2026-12-01
 *
 * Run it from cron once a day. It is safe to run more often than that, and
 * safe to run twice: the unique index on (clinic_id, period_from) means a
 * retried job cannot bill a clinic twice.
 *
 * Dry run is the default for the same reason `tools/purge.php` is: money is
 * the one place where being helpful without being asked is not a virtue.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\InvoiceService;

$apply = App\Support\Argv::has('--yes');
$today = App\Support\Argv::value('--today');

$service = new InvoiceService();
$due = $service->generateDue(!$apply, $today);

if ($due === []) {
    echo "Nothing to invoice.\n";
    exit(0);
}

$total = 0.0;
foreach ($due as $row) {
    $total += $row['amount'];
    printf(
        "%-34s %-18s %s .. %s  %s\n",
        mb_substr($row['clinic'], 0, 34),
        $row['number'],
        $row['period_from'],
        $row['period_to'],
        money($row['amount'])
    );
}

printf("\n%d invoice(s), %s total.\n", count($due), money($total));

if (!$apply) {
    echo "\nDry run — nothing was written. Re-run with --yes to create these.\n";
    exit(0);
}

echo "Created. Clinics can settle from Subscription; a super admin verifies in Payment verification.\n";
