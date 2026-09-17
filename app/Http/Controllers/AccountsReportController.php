<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Exports\GenericTableExport;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use DB;

class AccountsReportController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Account nature constants — single source of truth.
    // Every method that needs to know debit vs credit nature
    // reads from here. Adding a new type only requires changing
    // one of these two arrays.
    //
    // DEBIT-NATURED  = assets & expenses (increase with DR)
    //   customer    — trade receivables
    //   receivable  — loan given / other receivables (NEW)
    //   cash / bank — physical money
    //   inventory   — stock in hand
    //   expenses    — operational costs
    //   cogs        — cost of goods sold
    //
    // CREDIT-NATURED = liabilities, equity & revenue (increase with CR)
    //   vendor      — trade payables
    //   payable     — loan taken / other payables (NEW)
    //   liability   — general liabilities
    //   equity      — owner capital / investor accounts
    //   revenue     — sales revenue / other income
    // ─────────────────────────────────────────────────────────────
    private const DEBIT_NATURE  = ['customer', 'receivable', 'cash', 'bank', 'inventory', 'expenses', 'cogs'];
    private const CREDIT_NATURE = ['vendor', 'payable', 'liability', 'equity', 'revenue'];

    // ─────────────────────────────────────────────────────────────
    // MAIN ENTRY POINT
    // ─────────────────────────────────────────────────────────────
    public function accounts(Request $request)
    {
        $from      = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to        = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = $request->account_id;

        $chartOfAccounts = ChartOfAccounts::orderBy('name')->get();

        $reports = [
            'general_ledger'   => $this->generalLedger($accountId, $from, $to),
            'trial_balance'    => $this->trialBalance($from, $to),
            'profit_loss'      => $this->profitLoss($from, $to),
            'balance_sheet'    => $this->balanceSheet($from, $to),
            'party_ledger'     => $this->partyLedger($from, $to, $accountId),
            'receivables'      => $this->receivables($from, $to),
            'payables'         => $this->payables($from, $to),
            'cash_book'        => $this->cashBook($from, $to),
            'bank_book'        => $this->bankBook($from, $to),
            'journal_book'     => $this->journalBook($from, $to),
            'expense_analysis' => $this->expenseAnalysis($from, $to),
            'cash_flow'        => $this->cashFlow($from, $to),
        ];

        return view('reports.accounts_reports', compact('reports', 'from', 'to', 'chartOfAccounts'));
    }

    // ─────────────────────────────────────────────────────────────
    // ITEM 12 — PDF & Excel export
    //
    // Both actions export exactly one report tab (the one currently on
    // screen, identified by ?report=<key>) rather than all 12 at once, and
    // exercise the SAME period/account filters already applied on screen
    // (?from_date, ?to_date, ?account_id) — so what's exported always
    // matches what's currently displayed.
    // ─────────────────────────────────────────────────────────────

    // Column headings for each report tab, in the same order the tab's own
    // table renders them (see accounts_reports.blade.php). Used to both
    // build the exported table AND to trim each report's row arrays down
    // to just the exportable columns — general_ledger/party_ledger rows
    // carry one extra trailing element (voucher_type, see generalLedger()'s
    // FIX 2) that's for the blade's print-link only and isn't a column.
    private function reportHeaders(): array
    {
        return [
            'general_ledger'   => ['Date', 'Account', 'Voucher / Ref', 'Narration', 'Debit', 'Credit', 'Balance'],
            'trial_balance'    => ['Account', 'Type', 'Debit', 'Credit'],
            'profit_loss'      => ['Particulars', 'Amount'],
            'balance_sheet'    => ['Assets', 'Amount', 'Liabilities & Equity', 'Amount'],
            'party_ledger'     => ['Date', 'Party', 'Voucher / Ref', 'Narration', 'Debit', 'Credit', 'Balance'],
            'receivables'      => ['Account', 'Total Receivable'],
            'payables'         => ['Account', 'Total Payable'],
            'cash_book'        => ['Date', 'Debit Account', 'Credit Account', 'Narration', 'Debit', 'Credit', 'Balance'],
            'bank_book'        => ['Date', 'Debit Account', 'Credit Account', 'Narration', 'Debit', 'Credit', 'Balance'],
            'journal_book'     => ['Date', 'Voucher', 'Debit Account', 'Credit Account', 'Narration', 'Amount'],
            'expense_analysis' => ['Expense Head', 'Amount'],
            'cash_flow'        => ['Activity', 'Amount'],
        ];
    }

    private function reportLabels(): array
    {
        return [
            'general_ledger'   => 'General Ledger',
            'trial_balance'    => 'Trial Balance',
            'profit_loss'      => 'Profit & Loss',
            'balance_sheet'    => 'Balance Sheet',
            'party_ledger'     => 'Party Ledger',
            'receivables'      => 'Receivables',
            'payables'         => 'Payables',
            'cash_book'        => 'Cash Book',
            'bank_book'        => 'Bank Book',
            'journal_book'     => 'Journal - Day Book',
            'expense_analysis' => 'Expense Analysis',
            'cash_flow'        => 'Cash Flow',
        ];
    }

    // Builds just the ONE report an export was requested for, rather than
    // all 12 (unlike accounts() above, which builds every tab up front for
    // the tabbed page).
    private function buildReport(string $key, $accountId, $from, $to)
    {
        return match ($key) {
            'general_ledger'   => $this->generalLedger($accountId, $from, $to),
            'trial_balance'    => $this->trialBalance($from, $to),
            'profit_loss'      => $this->profitLoss($from, $to),
            'balance_sheet'    => $this->balanceSheet($from, $to),
            'party_ledger'     => $this->partyLedger($from, $to, $accountId),
            'receivables'      => $this->receivables($from, $to),
            'payables'         => $this->payables($from, $to),
            'cash_book'        => $this->cashBook($from, $to),
            'bank_book'        => $this->bankBook($from, $to),
            'journal_book'     => $this->journalBook($from, $to),
            'expense_analysis' => $this->expenseAnalysis($from, $to),
            'cash_flow'        => $this->cashFlow($from, $to),
            default            => null,
        };
    }

    private function prepareExport(Request $request): array
    {
        $headers = $this->reportHeaders();
        $key     = $request->get('report');

        if (!$key || !isset($headers[$key])) {
            abort(404, 'Unknown report.');
        }

        $from      = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to        = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();
        $accountId = $request->account_id;

        $data = $this->buildReport($key, $accountId, $from, $to);
        $head = $headers[$key];

        $rows = collect($data)->map(function ($row) use ($head) {
            return array_slice(array_values((array) $row), 0, count($head));
        })->values()->all();

        return [
            'label'   => $this->reportLabels()[$key],
            'headers' => $head,
            'rows'    => $rows,
            'from'    => $from,
            'to'      => $to,
        ];
    }

    public function exportExcel(Request $request)
    {
        $export = $this->prepareExport($request);

        $filename = Str::slug($export['label']) . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new GenericTableExport($export['headers'], $export['rows'], $export['label']),
            $filename
        );
    }

    public function exportPdf(Request $request)
    {
        $export = $this->prepareExport($request);

        $pdfContent = $this->renderReportPdf($export['label'], $export['from'], $export['to'], $export['headers'], $export['rows']);

        $filename = Str::slug($export['label']) . '_' . now()->format('Ymd_His') . '.pdf';

        return response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    // Shared PDF table renderer — landscape (reports commonly run 6-7
    // columns wide, e.g. General Ledger/Cash Book, which don't fit
    // comfortably in portrait) — styled the same as every other PDF in
    // this app (writeHTML table, #f5f5f5 header row, 0.3 border).
    private function renderReportPdf(string $label, string $from, string $to, array $headers, array $rows): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle($label);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, $label, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Period: ' . Carbon::parse($from)->format('d-m-Y') . ' to ' . Carbon::parse($to)->format('d-m-Y'), 0, 1, 'L');
        $pdf->Ln(3);

        $colWidth = round(100 / max(count($headers), 1), 2);

        $html = '<table border="0.3" cellpadding="4" cellspacing="0" width="100%" style="font-size:9px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">';
        foreach ($headers as $h) {
            $html .= '<th width="' . $colWidth . '%">' . e($h) . '</th>';
        }
        $html .= '</tr>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . count($headers) . '" align="center">No data found for the selected period.</td></tr>';
        }

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $col) {
                $isNum = $col !== '' && $col !== null && is_numeric(str_replace(',', '', (string) $col));
                $html .= '<td align="' . ($isNum ? 'right' : 'left') . '">' . e((string) $col) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output($label . '.pdf', 'S');
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER — format number
    // ─────────────────────────────────────────────────────────────
    private function fmt($v): string
    {
        return number_format((float) $v, 2);
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER — is this account debit-natured?
    // ─────────────────────────────────────────────────────────────
    private function isDebitNature(?string $accountType): bool
    {
        return in_array($accountType ?? '', self::DEBIT_NATURE);
    }
        // ─────────────────────────────────────────────────────────────
    // CORE BALANCE CALCULATOR
    //
    // Two modes depending on what you pass:
    //
    // Mode A — cumulative balance UP TO a date (for ledgers/opening bal):
    //   Pass $asOfDate. $from is ignored.
    //   Result = COA opening balance + all vouchers up to $asOfDate
    //
    // Mode B — period balance BETWEEN two dates (for P&L, expense report):
    //   Pass $from and $to, leave $asOfDate null.
    //   Result = only vouchers between $from and $to (no opening bal)
    // ─────────────────────────────────────────────────────────────
    private function getAccountBalance(
        $accountId,
        $from,
        $to,
        $asOfDate = null
    ): array {
        $account = ChartOfAccounts::find($accountId);
        if (!$account) return ['debit' => 0, 'credit' => 0];

        if ($asOfDate) {
            // Mode A: cumulative — include COA opening balance
            $openingDr = (float) $account->receivables;
            $openingCr = (float) $account->payables;

            $vDr = Voucher::where('ac_dr_sid', $accountId)
                ->where('date', '<=', $asOfDate)
                ->whereNull('deleted_at')
                ->sum('amount');

            $vCr = Voucher::where('ac_cr_sid', $accountId)
                ->where('date', '<=', $asOfDate)
                ->whereNull('deleted_at')
                ->sum('amount');
        } else {
            // Mode B: period only — no opening balance
            $openingDr = 0;
            $openingCr = 0;

            $vDr = Voucher::where('ac_dr_sid', $accountId)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');

            $vCr = Voucher::where('ac_cr_sid', $accountId)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');
        }

        return [
            'debit'  => $openingDr + $vDr,
            'credit' => $openingCr + $vCr,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // GENERAL LEDGER
    // Shows opening balance + every voucher movement for one account
    // ─────────────────────────────────────────────────────────────
    private function generalLedger($accountId, $from, $to)
    {
        if (!$accountId) return collect();

        $account = ChartOfAccounts::find($accountId);
        if (!$account) return collect();

        // Opening balance = everything before $from
        $opData     = $this->getAccountBalance($accountId, null, null, Carbon::parse($from)->subDay()->toDateString());
        $isDebitNat = $this->isDebitNature($account->account_type);
        $runningBal = $isDebitNat
            ? ($opData['debit'] - $opData['credit'])
            : ($opData['credit'] - $opData['debit']);

        $rows = collect();
        $rows->push([
            $from,
            $account->name,
            'Opening Balance',
            '',                                  // narration
            $this->fmt($opData['debit']),
            $this->fmt($opData['credit']),
            $this->fmt($runningBal),
        ]);

        $vouchers = Voucher::whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('ac_dr_sid', $accountId)->orWhere('ac_cr_sid', $accountId))
            ->orderBy('date')
            ->get();

        foreach ($vouchers as $v) {
            $dr = ($v->ac_dr_sid == $accountId) ? $v->amount : 0;
            $cr = ($v->ac_cr_sid == $accountId) ? $v->amount : 0;

            $runningBal += $isDebitNat ? ($dr - $cr) : ($cr - $dr);

            // FIX 1: include remarks as narration
            $rows->push([
                $v->date,
                $account->name,
                "Voucher #{$v->id}",
                $v->remarks ?? '',               // narration
                $this->fmt($dr),
                $this->fmt($cr),
                $this->fmt($runningBal),
                // FIX 2: the voucher's real type (journal/receipt/etc.) — not
                // rendered as a column, only used by the blade to build a
                // correctly-typed print link instead of always assuming
                // 'journal' (a Payment's auto-posted voucher is 'receipt').
                $v->voucher_type,
            ]);
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // PARTY LEDGER
    // Same as general ledger but designed for a customer/vendor/loan
    // account — shows "Opening Balance" label and running balance
    // FIX: added 'receivable' to debit-nature check
    // ─────────────────────────────────────────────────────────────
    private function partyLedger($from, $to, $accountId = null)
    {
        if (!$accountId) return collect();

        $account = ChartOfAccounts::find($accountId);
        if (!$account) return collect();

        $opData     = $this->getAccountBalance($accountId, null, null, Carbon::parse($from)->subDay()->toDateString());
        $isDebitNat = $this->isDebitNature($account->account_type);
        $runningBal = $isDebitNat
            ? ($opData['debit'] - $opData['credit'])
            : ($opData['credit'] - $opData['debit']);

        $rows = collect();
        $rows->push([
            $from,
            $account->name,
            'Opening Balance',
            '',                                   // narration
            0,
            0,
            $this->fmt($runningBal),
        ]);

        $vouchers = Voucher::whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->where('ac_dr_sid', $accountId)->orWhere('ac_cr_sid', $accountId))
            ->orderBy('date')
            ->get();

        $movements = $vouchers->map(function ($v) use ($accountId, $isDebitNat, &$runningBal) {
            $isDr     = $v->ac_dr_sid == $accountId;
            $drAmount = $isDr ? $v->amount : 0;
            $crAmount = $isDr ? 0 : $v->amount;

            $runningBal += $isDebitNat
                ? ($drAmount - $crAmount)
                : ($crAmount - $drAmount);

            return [
                $v->date,
                '',
                "Voucher #{$v->id} — " . ($isDr ? 'Debit' : 'Credit'),
                $v->remarks ?? '',                // narration
                $drAmount,
                $crAmount,
                $this->fmt($runningBal),
                // FIX 2: real voucher type — see generalLedger()'s note above.
                $v->voucher_type,
            ];
        });

        return $rows->concat($movements);
    }

    // ─────────────────────────────────────────────────────────────
    // PROFIT & LOSS
    // Period-only (Mode B) — revenue, COGS, expenses
    // ─────────────────────────────────────────────────────────────
    private function profitLoss($from, $to)
    {
        $revenue = ChartOfAccounts::where('account_type', 'revenue')->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['credit'] - $bal['debit']];
            })->filter(fn($r) => $r[1] != 0);

        $cogs = ChartOfAccounts::whereIn('account_type', ['cogs', 'cost_of_sales'])->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['debit'] - $bal['credit']];
            })->filter(fn($r) => $r[1] != 0);

        $expenses = ChartOfAccounts::where('account_type', 'expenses')->get()
            ->map(function ($a) use ($from, $to) {
                $bal = $this->getAccountBalance($a->id, $from, $to);
                return [$a->name, $bal['debit'] - $bal['credit']];
            })->filter(fn($r) => $r[1] != 0);

        $totalRev    = $revenue->sum(fn($r) => $r[1]);
        $totalCogs   = $cogs->sum(fn($r) => $r[1]);
        $grossProfit = $totalRev - $totalCogs;
        $totalExp    = $expenses->sum(fn($r) => $r[1]);
        $netProfit   = $grossProfit - $totalExp;

        $data = collect([['REVENUE', '']]);
        $data = $data->concat($revenue);
        $data->push(['Total Revenue', $this->fmt($totalRev)]);

        $data->push(['LESS: COST OF GOODS SOLD', '']);
        $data = $data->concat($cogs);
        $data->push(['GROSS PROFIT', $this->fmt($grossProfit)]);

        $data->push(['OPERATING EXPENSES', '']);
        $data = $data->concat($expenses);
        $data->push(['NET PROFIT / LOSS', $this->fmt($netProfit)]);

        return $data;
    }

    // ─────────────────────────────────────────────────────────────
    // RECEIVABLES
    // FIX: now includes both 'customer' (trade) and 'receivable'
    // (loans given / other receivables)
    // ─────────────────────────────────────────────────────────────
    private function receivables($from, $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['customer', 'receivable'])->get()
            ->map(function ($a) use ($to) {
                // Cumulative up to $to — receivables are balance-sheet items
                $bal   = $this->getAccountBalance($a->id, null, null, $to);
                $total = $bal['debit'] - $bal['credit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────
    // PAYABLES
    // FIX: now includes both 'vendor' (trade) and 'payable'
    // (loans taken / other payables)
    // ─────────────────────────────────────────────────────────────
    private function payables($from, $to)
    {
        return ChartOfAccounts::whereIn('account_type', ['vendor', 'payable'])->get()
            ->map(function ($a) use ($to) {
                // Cumulative up to $to — payables are balance-sheet items
                $bal   = $this->getAccountBalance($a->id, null, null, $to);
                $total = $bal['credit'] - $bal['debit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) > 0)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────
    // TRIAL BALANCE
    // FIX: uses DEBIT_NATURE constant so new types are auto-included
    // ─────────────────────────────────────────────────────────────
    private function trialBalance($from, $to)
    {
        return ChartOfAccounts::all()
            ->map(function ($a) use ($from, $to) {
                // Trial balance uses cumulative balance as of $to
                $bal = $this->getAccountBalance($a->id, null, null, $to);

                if ($this->isDebitNature($a->account_type)) {
                    $diff   = $bal['debit'] - $bal['credit'];
                    $debit  = $diff > 0 ? $diff : 0;
                    $credit = $diff < 0 ? abs($diff) : 0;
                } else {
                    $diff   = $bal['credit'] - $bal['debit'];
                    $credit = $diff > 0 ? $diff : 0;
                    $debit  = $diff < 0 ? abs($diff) : 0;
                }

                return [$a->name, $a->account_type, $this->fmt($debit), $this->fmt($credit)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[2]) != 0 || (float) str_replace(',', '', $r[3]) != 0);
    }

    // ─────────────────────────────────────────────────────────────
    // BALANCE SHEET
    // FIX: added 'receivable' and 'inventory' to assets,
    //      added 'payable' to liabilities
    // ─────────────────────────────────────────────────────────────
    private function balanceSheet($from, $to)
    {
        $trial       = $this->trialBalance($from, $to);
        $assets      = collect();
        $liabilities = collect();

        foreach ($trial as $r) {
            $type   = $r[1];
            $debit  = (float) str_replace(',', '', $r[2]);
            $credit = (float) str_replace(',', '', $r[3]);

            // Assets side: debit-natured accounts (excluding pure expense/cogs — P&L items)
            if (in_array($type, ['customer', 'receivable', 'cash', 'bank', 'inventory'])) {
                $val = $debit - $credit;
                if ($val != 0) $assets->push([$r[0], $this->fmt($val)]);

            // Liabilities & equity side: credit-natured accounts (excluding revenue/cogs — P&L)
            } elseif (in_array($type, ['vendor', 'payable', 'liability', 'equity'])) {
                $val = $credit - $debit;
                if ($val != 0) $liabilities->push([$r[0], $this->fmt($val)]);
            }
            // Note: 'expenses', 'cogs', 'revenue' intentionally excluded —
            // they flow through P&L into Retained Earnings, not directly on balance sheet
        }

        $max  = max($assets->count(), $liabilities->count(), 1);
        $rows = [];
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                $assets[$i][0]      ?? '',
                $assets[$i][1]      ?? '',
                $liabilities[$i][0] ?? '',
                $liabilities[$i][1] ?? '',
            ];
        }

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────
    // CASH BOOK
    // ─────────────────────────────────────────────────────────────
    private function cashBook($from, $to)
    {
        $cashIds = ChartOfAccounts::where('account_type', 'cash')->pluck('id');
        return $this->bookHelper($cashIds, $from, $to);
    }

    // ─────────────────────────────────────────────────────────────
    // BANK BOOK
    // ─────────────────────────────────────────────────────────────
    private function bankBook($from, $to)
    {
        $bankIds = ChartOfAccounts::where('account_type', 'bank')->pluck('id');
        return $this->bookHelper($bankIds, $from, $to);
    }

    private function bookHelper($ids, $from, $to)
    {
        if ($ids->isEmpty()) return collect();

        $vouchers = Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->where(fn($q) => $q->whereIn('ac_dr_sid', $ids)->orWhereIn('ac_cr_sid', $ids))
            ->orderBy('date')
            ->get();

        $bal     = 0;
        $idsArr  = $ids->toArray();

        return $vouchers->map(function ($v) use ($idsArr, &$bal) {
            $dr   = in_array($v->ac_dr_sid, $idsArr) ? $v->amount : 0;
            $cr   = in_array($v->ac_cr_sid, $idsArr) ? $v->amount : 0;
            $bal += ($dr - $cr);

            return [
                $v->date,
                $v->debitAccount->name  ?? '—',
                $v->creditAccount->name ?? '—',
                $v->remarks ?? '',               // narration
                $this->fmt($dr),
                $this->fmt($cr),
                $this->fmt($bal),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────
    // EXPENSE ANALYSIS
    // ─────────────────────────────────────────────────────────────
    private function expenseAnalysis($from, $to)
    {
        return ChartOfAccounts::where('account_type', 'expenses')
            ->get()
            ->map(function ($a) use ($from, $to) {
                $bal   = $this->getAccountBalance($a->id, $from, $to);
                $total = $bal['debit'] - $bal['credit'];
                return [$a->name, $this->fmt($total)];
            })
            ->filter(fn($r) => (float) str_replace(',', '', $r[1]) != 0);
    }

    // ─────────────────────────────────────────────────────────────
    // JOURNAL / DAY BOOK
    // ─────────────────────────────────────────────────────────────
    private function journalBook($from, $to)
    {
        return Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            // FIX 3: this is the manual Journal Voucher book — restrict to
            // voucher_type 'journal' explicitly. Without this, a Payment's
            // auto-posted 'receipt' voucher was also slipping in whenever it
            // had no reference (e.g. a cash payment line, which has no
            // cheque number) since it also passed the whereNull(reference)
            // check below.
            ->where('voucher_type', 'journal')
            ->whereNull('reference')          // exclude system entries (Bill/Invoice auto-posted journal vouchers)
            ->orderBy('date')
            ->get()
            ->map(fn($v) => [
                $v->date,
                "Voucher #{$v->id}",
                $v->debitAccount->name  ?? 'N/A',
                $v->creditAccount->name ?? 'N/A',
                $v->remarks ?? '',               // narration
                $this->fmt($v->amount),
            ]);
    }

    // ─────────────────────────────────────────────────────────────
    // CASH FLOW (simplified — operating activities only)
    // ─────────────────────────────────────────────────────────────
    private function cashFlow($from, $to)
    {
        $cashBankIds = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id');

        $inflow  = Voucher::whereIn('ac_dr_sid', $cashBankIds)->whereBetween('date', [$from, $to])->whereNull('deleted_at')->sum('amount');
        $outflow = Voucher::whereIn('ac_cr_sid', $cashBankIds)->whereBetween('date', [$from, $to])->whereNull('deleted_at')->sum('amount');

        return [
            ['Total Cash Inflow (Receipts)',       $this->fmt($inflow)],
            ['Total Cash Outflow (Payments)',       $this->fmt($outflow)],
            ['Net Increase / Decrease in Cash',    $this->fmt($inflow - $outflow)],
        ];
    }
}