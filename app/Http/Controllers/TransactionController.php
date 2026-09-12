<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\Transaction;
use App\Services\AcademicPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    /**
     * Tenant-aware transaction listing.
     *
     * Every query here starts from the acting school's id. The previous un-scoped
     * index()/store()/destroy() methods were removed: index() listed every school's
     * payer names, emails and amounts to any logged-in admin, and store() allowed an
     * admin to insert an arbitrary 'success' transaction with no school attribution.
     *
     * The list defaults to successful payments — the collections view — and only
     * shows pending/failed/mismatch rows when the admin asks for them explicitly.
     */
    public function indexSchool(Request $request, School $school, AcademicPeriodService $periods)
    {
        $filters = $this->filters($request);

        $transactions = $this->query($school, $filters)
            ->with(['student', 'category', 'academicTerm.session'])
            ->paginate(20)
            ->withQueryString();

        return view('transactions.index', [
            'transactions' => $transactions,
            'q' => $filters['q'],
            'filters' => $filters,
            'school' => $school,
            'categories' => Category::where('school_id', $school->id)->orderBy('name')->get(),
            'terms' => $periods->termsForSchool($school),
            'sessions' => $school->academicSessions()->get(),
            'statuses' => Transaction::STATUSES,
        ]);
    }

    /**
     * CSV export of exactly the rows the list shows, for the acting school only.
     *
     * Streamed so a large history never has to fit in memory. Plain CSV rather
     * than XLSX: it opens in Excel and Google Sheets and needs no extra package.
     */
    public function exportSchool(Request $request, School $school): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($school, $filters)->with(['student', 'payout']);

        $filename = sprintf('%s-transactions-%s.csv', $school->slug, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel decodes names with diacritics correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Reference', 'Paystack Reference', 'Date Paid', 'Status',
                'Student', 'Admission Number', 'Class', 'Session', 'Term',
                'Category', 'Fee Type', 'Quantity',
                'Payer Name', 'Payer Email', 'Payment Method',
                'Fee Amount (NGN)', 'Service Fee (NGN)', 'Total Charged (NGN)',
                'Payout Status', 'Payout Reference',
            ]);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    $breakdown = $t->receiptBreakdown();
                    fputcsv($out, [
                        $t->reference,
                        $t->paystack_reference,
                        optional($t->paid_at ?? $t->created_at)->format('Y-m-d H:i:s'),
                        $t->status,
                        $t->student_name ?? $t->student?->full_name,
                        $t->student_admission_number ?? $t->student?->admission_number,
                        $t->student_class ?? $t->student?->class_name,
                        $t->session_name,
                        $t->term_name,
                        $t->category_name,
                        $t->subcategory_name,
                        $breakdown['quantity'],
                        $t->name,
                        $t->email,
                        $t->payment_method,
                        number_format($breakdown['fee_subtotal'], 2, '.', ''),
                        number_format($breakdown['service_fee'], 2, '.', ''),
                        number_format($breakdown['total'], 2, '.', ''),
                        $t->payout?->status,
                        $t->payout?->reference,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Read the filter set from the query string. Values are only ever used to
     * narrow Transaction::scopeFilter; unknown or malformed ones are ignored there.
     */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->input('q', '')),
            // Default to the collections view. 'all' lifts the status filter.
            'status' => (string) $request->input('status', Transaction::STATUS_SUCCESS),
            'category_id' => $request->input('category_id'),
            'session_id' => $request->input('session_id'),
            'term_id' => $request->input('term_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
    }

    private function query(School $school, array $filters)
    {
        return Transaction::forSchool($school)
            ->filter($filters)
            ->orderByDesc(DB::raw(Transaction::paidAtExpression()))
            ->orderByDesc('id');
    }
}
