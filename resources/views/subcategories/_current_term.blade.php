{{-- Current term, re-homed from the retired Sessions page. The payment page opens on it and the
     dashboard reports on it; parents can still choose any other term that has fees. The admin
     picks an academic year + term; the server finds or creates the rows behind them. --}}
@php
    $periods = app(\App\Services\AcademicPeriodService::class);
    $currentYearChoice = (string) old('current_academic_year', $currentTerm?->session?->name ?? $periods->currentYear($school));
    $currentTermChoice = (string) old('current_term', $currentTerm?->number ?? '');
    $currentTermError = $errors->first('current_academic_year') ?: $errors->first('current_term');
@endphp
<form method="POST" action="{{ route('school.terms.current.update', ['school' => $school->slug]) }}" class="card mb-6 p-5 sm:p-6" aria-labelledby="current-term-heading">
    @csrf
    @method('PUT')
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0">
            <h2 id="current-term-heading" class="font-display text-lg font-bold tracking-tight">Current term</h2>
            <p class="mt-1 text-sm text-brand-slate">
                @if($currentTerm)
                    <span class="font-semibold text-brand-obsidian">{{ $currentTerm->label }}</span>. Your payment page opens on this term — change it when a new term starts.
                @else
                    Not set yet. It is set for you when you add your first term fee.
                @endif
            </p>
        </div>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div>
                <label for="current_academic_year" class="field-label">Academic year</label>
                <select id="current_academic_year" name="current_academic_year" class="field-input" @if($currentTermError) aria-invalid="true" aria-describedby="current-term-error" @endif>
                    @foreach($periods->yearOptions($school) as $year)
                        <option value="{{ $year }}" @selected($currentYearChoice === $year)>{{ $year }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="current_term" class="field-label">Term</label>
                <select id="current_term" name="current_term" class="field-input" required @if($currentTermError) aria-invalid="true" aria-describedby="current-term-error" @endif>
                    @unless($currentTerm)<option value="">Choose a term</option>@endunless
                    @foreach(\App\Models\AcademicTerm::NAMES as $number => $termName)
                        <option value="{{ $number }}" @selected($currentTermChoice === (string) $number)>{{ $termName }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-outline">Set current term</button>
        </div>
    </div>
    @if($currentTermError)
        <p id="current-term-error" class="field-error">{{ $currentTermError }}</p>
    @endif
</form>
