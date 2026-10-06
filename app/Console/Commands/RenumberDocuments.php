<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\DailyJob;
use App\Models\Invoice;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// One-off: gives the bills/invoices already entered in this system the first
// numbers of the carried-over sequence (see config/numbering.php) — e.g.
// BILL-000006 -> BILL-000572/2026 — so everything created afterwards simply
// continues from there.
//
//   php artisan numbers:renumber            preview only (changes nothing)
//   php artisan numbers:renumber --apply    do it
//
// Only LIVE (not deleted) bills and invoices are renumbered, oldest first (by
// id), and each keeps its relative order. Deleted ones are left alone. The
// vouchers that quote a number in their reference/remarks (the bill's own
// receivable voucher, a Party-to-Party vendor-payable voucher, the invoice's
// sales-tax voucher and any payment-receipt vouchers) are updated to match, so
// the ledger keeps reading correctly. Runs in one transaction.
//
// Refuses to run twice: once any live bill/invoice already has a number in the
// new PREFIX-######/YYYY form, it stops (pass --force to override — doing so
// would close any gaps left by deleted documents, so don't unless you mean it).
class RenumberDocuments extends Command
{
    protected $signature = 'numbers:renumber {--apply : Actually write the changes (default is a preview)} {--force : Run even if some live documents already use the new numbering}';

    protected $description = 'Renumber existing bills/invoices so they carry on from the previous system (BILL-000572/2026, INV-000478/2026 ...)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $bills    = Bill::orderBy('id')->get();
        $invoices = Invoice::orderBy('id')->get();

        if (!$this->option('force')) {
            $already = $bills->contains(fn ($b) => str_contains((string) $b->bill_no, '/'))
                || $invoices->contains(fn ($i) => str_contains((string) $i->invoice_no, '/'));
            if ($already) {
                $this->error('Some live bills/invoices already use the new PREFIX-######/YYYY numbering — nothing done. (Use --force only if you really intend to renumber again.)');
                return self::FAILURE;
            }
        }

        $billPlan    = $this->plan($bills, 'bill', 'bill_date');
        $invoicePlan = $this->plan($invoices, 'invoice', 'invoice_date');

        $this->table(['Bill id', 'Bill date', 'Old no.', 'New no.'], collect($billPlan)->map(fn ($r) => [$r['model']->id, $r['date'], $r['old'], $r['new']])->all());
        $this->table(['Invoice id', 'Invoice date', 'Old no.', 'New no.'], collect($invoicePlan)->map(fn ($r) => [$r['model']->id, $r['date'], $r['old'], $r['new']])->all());

        if (!$apply) {
            $this->warn('Preview only — nothing was changed. Take a database backup, then run again with --apply.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($billPlan, $invoicePlan) {
            // Phase 1 — park every affected row on a throw-away unique value so
            // an old number that happens to equal another row's new number can
            // never trip the unique index mid-way.
            foreach ($billPlan as $r)    { DB::table('bills')->where('id', $r['model']->id)->update(['bill_no' => 'TMP-B' . $r['model']->id]); }
            foreach ($invoicePlan as $r) { DB::table('invoices')->where('id', $r['model']->id)->update(['invoice_no' => 'TMP-I' . $r['model']->id]); }

            // Phase 2 — final numbers + the vouchers that quote them.
            foreach ($billPlan as $r) {
                /** @var Bill $bill */
                $bill = $r['model'];
                DB::table('bills')->where('id', $bill->id)->update(['bill_no' => $r['new']]);

                // The bill's own Dr Customer / Cr Revenue voucher.
                if ($bill->voucher_id) {
                    DB::table('vouchers')->where('id', $bill->voucher_id)->update(['reference' => $r['new'], 'remarks' => "Bill {$r['new']}"]);
                }
                // Party-to-Party vendor-payable vouchers posted for this bill's jobs.
                $ptyVoucherIds = DailyJob::where('bill_id', $bill->id)->whereNotNull('pty_voucher_id')->pluck('pty_voucher_id');
                foreach (Voucher::whereIn('id', $ptyVoucherIds)->get() as $v) {
                    $v->update(['reference' => $r['new'], 'remarks' => str_replace($r['old'], $r['new'], (string) $v->remarks)]);
                }
            }

            foreach ($invoicePlan as $r) {
                /** @var Invoice $invoice */
                $invoice = $r['model'];
                DB::table('invoices')->where('id', $invoice->id)->update(['invoice_no' => $r['new']]);

                // The invoice's sales-tax voucher.
                if ($invoice->voucher_id) {
                    $v = Voucher::find($invoice->voucher_id);
                    if ($v) {
                        $v->update(['reference' => $r['new'], 'remarks' => str_replace($r['old'], $r['new'], (string) $v->remarks)]);
                    }
                }
                // Receipt vouchers of payments already recorded against it
                // ("Payment PAY-… against Invoice <no> (Cheque)").
                foreach ($invoice->payments()->with('lines')->get() as $payment) {
                    foreach ($payment->lines as $line) {
                        $v = $line->voucher_id ? Voucher::find($line->voucher_id) : null;
                        if ($v) {
                            $v->update(['remarks' => str_replace($r['old'], $r['new'], (string) $v->remarks)]);
                        }
                    }
                }
            }
        });

        $this->info('Done. ' . count($billPlan) . ' bills and ' . count($invoicePlan) . ' invoices renumbered. New documents will continue from these numbers.');
        return self::SUCCESS;
    }

    // Old -> new for each row: sequence per year of the document's own date,
    // starting at config/numbering.php's carried-over number for its start
    // year (and at 1 for any other year).
    private function plan($models, string $kind, string $dateColumn): array
    {
        $cfg      = config("numbering.{$kind}");
        $column   = $kind === 'bill' ? 'bill_no' : 'invoice_no';
        $counters = [];
        $plan     = [];

        foreach ($models as $m) {
            $year = Carbon::parse($m->{$dateColumn})->year;
            $counters[$year] ??= ($year === (int) $cfg['start_year'] ? (int) $cfg['start_number'] : 1);
            $plan[] = [
                'model' => $m,
                'date'  => Carbon::parse($m->{$dateColumn})->format('d-m-Y'),
                'old'   => $m->{$column},
                'new'   => sprintf('%s-%06d/%d', $cfg['prefix'], $counters[$year]++, $year),
            ];
        }

        return $plan;
    }
}