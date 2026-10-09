<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\BusinessTime;
use App\Support\RecordsSchoolAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates and selects the periods a school collects fees for.
 *
 * Admins never manage sessions directly: they name an academic year and a term
 * where it matters (creating a fee, promoting students) and termFor() finds or
 * creates the session-and-terms rows behind it. The data model — one session per
 * year, three terms each — is unchanged, so payments, reporting and the paid-once
 * rule keep reading the same ids.
 */
class AcademicPeriodService
{
    public function __construct(private RecordsSchoolAudit $audit) {}

    /**
     * Create a session and its three terms in one transaction. The first session a
     * school creates also provides its current term (term $currentTermNumber,
     * First Term unless the caller asked for another), so the dashboard
     * and payment page have a context without a second click.
     */
    public function createSession(School $school, string $name, ?string $startsOn = null, ?string $endsOn = null, int $currentTermNumber = 1): AcademicSession
    {
        return DB::transaction(function () use ($school, $name, $startsOn, $endsOn, $currentTermNumber) {
            $session = $school->academicSessions()->create([
                'name' => trim($name),
                'starts_on' => $startsOn ?: null,
                'ends_on' => $endsOn ?: null,
            ]);

            foreach (AcademicTerm::NAMES as $number => $termName) {
                $session->terms()->create([
                    'school_id' => $school->id,
                    'number' => $number,
                    'name' => $termName,
                ]);
            }

            if ($school->current_academic_term_id === null) {
                $school->forceFill([
                    'current_academic_term_id' => $session->terms()->where('number', $currentTermNumber)->value('id'),
                ])->save();
            }

            return $session->load('terms');
        });
    }

    /**
     * The school's term for an academic year ("2026/2027") and term number (1–3),
     * creating the year and its three terms on first use. A school that has no
     * current term yet gets this one, so the payment page has a default.
     *
     * Runs in a transaction with the school row locked, so two concurrent requests
     * for a new year cannot both create it.
     */
    public function termFor(School $school, string $year, int $number): AcademicTerm
    {
        $year = trim($year);
        if (! AcademicSession::isValidName($year)) {
            throw new InvalidArgumentException('An academic year is two consecutive years, e.g. 2026/2027.');
        }
        if (! isset(AcademicTerm::NAMES[$number])) {
            throw new InvalidArgumentException('Unknown term.');
        }

        return DB::transaction(function () use ($school, $year, $number) {
            School::whereKey($school->id)->lockForUpdate()->first();

            $session = AcademicSession::where('school_id', $school->id)->where('name', $year)->first();
            if (! $session) {
                $session = $this->createSession($school, $year, currentTermNumber: $number);
                $school->refresh();
            }

            return $session->terms()->where('number', $number)->with('session')->firstOrFail();
        });
    }

    /**
     * The school's EXISTING term for an academic year and term number, or null.
     * Unlike termFor() this never creates anything: it is for flows (cash payments)
     * that may only act on periods the school has already set up.
     */
    public function existingTerm(School $school, ?string $year, mixed $number): ?AcademicTerm
    {
        $year = trim((string) $year);
        if ($year === '' || ! is_numeric($number) || ! isset(AcademicTerm::NAMES[(int) $number])) {
            return null;
        }

        return AcademicTerm::query()
            ->where('academic_terms.school_id', $school->id)
            ->where('academic_terms.number', (int) $number)
            ->whereHas('session', fn ($q) => $q->where('school_id', $school->id)->where('name', $year))
            ->with('session')
            ->first();
    }

    /**
     * Is this term open for recording a cash school-fee payment?
     *
     * The school's current term is the one period FEYRA treats as "now": the admin
     * chooses it on the Fees page (audited), the payment page opens on it and the
     * dashboard reports on it. Cash may only be recorded for it, so a payment can
     * not be back-dated into a term the school has moved on from. Callers re-check
     * this against a freshly read (and, when saving, locked) school row.
     */
    public function isOpenForCashPayment(School $school, ?AcademicTerm $term): bool
    {
        return $term !== null
            && (int) $term->school_id === (int) $school->id
            && $school->current_academic_term_id !== null
            && (int) $school->current_academic_term_id === (int) $term->id;
    }

    /** The academic year after "2026/2027": "2027/2028". */
    public static function nextYear(string $year): string
    {
        if (! AcademicSession::isValidName($year)) {
            throw new InvalidArgumentException('Not an academic year: '.$year);
        }
        $start = (int) substr($year, 0, 4) + 1;

        return $start.'/'.($start + 1);
    }

    /**
     * The academic year the school is in: its current term's year, otherwise the
     * year the calendar suggests (a Nigerian school year starts in September).
     */
    public function currentYear(School $school, ?Carbon $today = null): string
    {
        $name = $school->currentTerm?->session?->name;
        if ($name) {
            return $name;
        }

        $today ??= Carbon::now(BusinessTime::zone());
        $start = $today->month >= 9 ? $today->year : $today->year - 1;

        return $start.'/'.($start + 1);
    }

    /**
     * The academic years a form offers: the year before, the current year and the
     * next one, plus every year the school already has — newest first.
     *
     * @return array<int, string>
     */
    public function yearOptions(School $school): array
    {
        $current = $this->currentYear($school);
        $start = (int) substr($current, 0, 4);

        return collect([($start - 1).'/'.$start, $current, self::nextYear($current)])
            ->merge(AcademicSession::where('school_id', $school->id)->pluck('name'))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Point the school at a term it owns. The ownership check is the whole point:
     * a term id from the request is never written without proving it is this
     * school's, so a school can never "select" another school's term.
     */
    public function setCurrentTerm(School $school, AcademicTerm $term): void
    {
        if ((int) $term->school_id !== (int) $school->id) {
            abort(404);
        }

        $previous = $school->current_academic_term_id;

        if ((int) $previous === (int) $term->id) {
            return; // already current: nothing changed, nothing to audit
        }

        // The current term decides which fees a parent is offered and what a
        // payment is attributed to, so the change and its audit row commit
        // together (M7).
        DB::transaction(function () use ($school, $term, $previous) {
            $school->forceFill(['current_academic_term_id' => $term->id])->save();

            $this->audit->record($school, SchoolAuditEvent::ACTION_TERM_CHANGED, 'academic_term', $term->id, [
                'current_academic_term_id' => ['from' => $previous, 'to' => $term->id],
            ]);
        });
    }

    /**
     * All of a school's terms, newest session first, with sessions loaded — the
     * list every term dropdown (fees, payment page, filters) is built from.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AcademicTerm>
     */
    public function termsForSchool(School $school)
    {
        return AcademicTerm::query()
            ->where('academic_terms.school_id', $school->id)
            ->join('academic_sessions', 'academic_sessions.id', '=', 'academic_terms.academic_session_id')
            ->orderByDesc('academic_sessions.name')
            ->orderBy('academic_terms.number')
            ->select('academic_terms.*')
            ->with('session')
            ->get();
    }
}
