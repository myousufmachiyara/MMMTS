<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['invoice.customer', 'lines.account']);

        if ($request->filled('invoice_id') && $request->invoice_id !== 'all') {
            $query->where('invoice_id', $request->invoice_id);
        }

        $payments = $query->latest('payment_date')->latest('id')->get();
        $invoices = Invoice::orderByDesc('id')->get();

        return view('payments.index', compact('payments', 'invoices'));
    }

    public function create()
    {
        // Only invoices with an outstanding balance are payable
        $invoices = Invoice::where('status', '!=', 'cleared')->with('customer')->orderByDesc('id')->get();
        $accounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();

        return view('payments.create', compact('invoices', 'accounts'));
    }

    private function nextPaymentNo(): string
    {
        $last = Payment::withTrashed()
            ->where('payment_no', 'like', 'PAY-%')
            ->pluck('payment_no')
            ->map(fn ($no) => (int) substr($no, 4))
            ->sort()
            ->last();

        return 'PAY-' . str_pad(($last ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function rules(): array
    {
        return [
            'invoice_id'              => 'required|exists:invoices,id',
            'payment_date'            => 'required|date',
            'amount'                  => 'required|numeric|min:0.01',
            'remarks'                 => 'nullable|string|max:1000',

            'lines'                   => 'required|array|min:1',
            'lines.*.method'          => 'required|in:cash,cheque,online_transfer',
            'lines.*.account_id'      => 'required|exists:chart_of_accounts,id',
            'lines.*.amount'          => 'required|numeric|min:0.01',
            'lines.*.cheque_no'       => 'nullable|required_if:lines.*.method,cheque|string|max:50',
            'lines.*.cheque_date'     => 'nullable|required_if:lines.*.method,cheque|date',
            'lines.*.reference'       => 'nullable|string|max:255',
        ];
    }

    public function store(Request $request)
    {
        try {
            Log::info('[Payment] Store called', ['user_id' => auth()->id()]);

            $data = $request->validate($this->rules());

            $linesTotal = round(collect($data['lines'])->sum('amount'), 2);
            if (abs($linesTotal - round($data['amount'], 2)) > 0.01) {
                return redirect()->back()->withInput()
                    ->with('error', "The payment method lines (total {$linesTotal}) must add up to the payment amount ({$data['amount']}).");
            }

            $payment = DB::transaction(function () use ($data) {
                $invoice = Invoice::whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail();

                $balance = round($invoice->total_amount - $invoice->paid_amount, 2);
                if ($data['amount'] > $balance + 0.001) {
                    throw new \RuntimeException("Payment amount exceeds the outstanding balance of {$balance} on invoice {$invoice->invoice_no}.");
                }

                $payment = Payment::create([
                    'payment_no'   => $this->nextPaymentNo(),
                    'invoice_id'   => $invoice->id,
                    'payment_date' => $data['payment_date'],
                    'amount'       => $data['amount'],
                    'remarks'      => $data['remarks'] ?? null,
                    'created_by'   => auth()->id(),
                    'updated_by'   => auth()->id(),
                ]);

                foreach ($data['lines'] as $line) {
                    // Auto-post per line: Dr Cash/Bank (account picked) / Cr Customer (Receivable)
                    $voucher = Voucher::create([
                        'voucher_type' => 'receipt',
                        'date'         => $data['payment_date'],
                        'ac_dr_sid'    => $line['account_id'],
                        'ac_cr_sid'    => $invoice->customer_id,
                        'amount'       => $line['amount'],
                        'reference'    => $line['method'] === 'cheque' ? ($line['cheque_no'] ?? null) : ($line['reference'] ?? null),
                        'remarks'      => "Payment {$payment->payment_no} against Invoice {$invoice->invoice_no} ("
                            . ucfirst(str_replace('_', ' ', $line['method'])) . ')',
                    ]);

                    PaymentLine::create([
                        'payment_id'  => $payment->id,
                        'method'      => $line['method'],
                        'account_id'  => $line['account_id'],
                        'amount'      => $line['amount'],
                        'cheque_no'   => $line['method'] === 'cheque' ? ($line['cheque_no'] ?? null) : null,
                        'cheque_date' => $line['method'] === 'cheque' ? ($line['cheque_date'] ?? null) : null,
                        'reference'   => $line['reference'] ?? null,
                        'voucher_id'  => $voucher->id,
                    ]);
                }

                $invoice->paid_amount += $data['amount'];
                $invoice->refreshStatus();

                return $payment;
            });

            return redirect()->route('payments.index')->with('success', "Payment {$payment->payment_no} recorded successfully.");

        } catch (\Throwable $e) {
            Log::error('[Payment] Store error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $payment = Payment::with(['invoice.customer', 'lines.account'])->findOrFail($id);
        return response()->json($payment);
    }

    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $payment = Payment::with('lines')->whereKey($id)->lockForUpdate()->firstOrFail();
                $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

                $invoice->paid_amount = max(0, $invoice->paid_amount - $payment->amount);
                $invoice->refreshStatus();

                foreach ($payment->lines as $line) {
                    if ($line->voucher_id) {
                        Voucher::whereKey($line->voucher_id)->delete();
                    }
                }
                $payment->lines()->delete();

                $payment->delete();
            });

            return redirect()->route('payments.index')->with('success', 'Payment deleted successfully.');

        } catch (\Throwable $e) {
            Log::error('[Payment] Destroy error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    // Print — Payment receipt, itemising every method line.
    public function print($id)
    {
        $payment = Payment::with(['invoice.customer', 'lines.account'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('MMMTS');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Payment ' . $payment->payment_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 8, 40);
        }

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY(120, 12);
        $pdf->Cell(80, 8, 'PAYMENT RECEIPT', 0, 1, 'R');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);

        $infoHtml = '
        <table cellpadding="3" cellspacing="0" width="60%">
            <tr>
                <td width="60%">
                    <b>' . e($payment->invoice->customer->name ?? '') . '</b><br>
                    ' . e($payment->invoice->customer->address ?? '') . '
                </td>
                <td width="40%">
                    <table border="1" cellpadding="4" cellspacing="0" style="font-size:10px;">
                        <tr><td width="45%"><b>Payment No.</b></td><td width="55%">' . e($payment->payment_no) . '</td></tr>
                        <tr><td width="45%"><b>Payment Date</b></td><td width="55%">' . $payment->payment_date->format('d-m-Y') . '</td></tr>
                        <tr><td width="45%"><b>Against Invoice</b></td><td width="55%">' . e($payment->invoice->invoice_no ?? '') . '</td></tr>
                    </table>
                </td>
            </tr>
        </table>';
        $pdf->writeHTML($infoHtml, true, false, false, false, '');

        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="20%">Method</th>
                <th width="25%">Account</th>
                <th width="17%">Cheque No.</th>
                <th width="14%">Cheque Date</th>
                <th width="18%">Amount</th>
            </tr>';

        foreach ($payment->lines as $i => $line) {
            $html .= '<tr>
                <td>' . ($i + 1) . '</td>
                <td>' . e(ucfirst(str_replace('_', ' ', $line->method))) . '</td>
                <td>' . e($line->account->name ?? '') . '</td>
                <td>' . e($line->cheque_no ?? '—') . '</td>
                <td>' . ($line->cheque_date ? $line->cheque_date->format('d-m-Y') : '—') . '</td>
                <td align="right">' . number_format($line->amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Total Amount</b></td>
                <td align="right"><b>' . number_format($payment->amount, 2) . '</b></td>
            </tr>';
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($payment->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br(e($payment->remarks)) . '</span>', true, false, true, false, '');
        }

        $pdf->Ln(20);
        $yPos = $pdf->GetY();
        $lineWidth = 40;
        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Received By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('payment_' . $payment->payment_no . '.pdf', 'I');
    }
}