@extends('layouts.admin')

@section('title', $student->full_name)
@section('eyebrow', 'School · Student')
@section('heading', $student->full_name)
@section('subheading', $student->admission_number.' · '.($student->class_name ?: 'No class').($student->session ? ' · '.$student->session->name : ''))
@section('actions')
    <a href="{{ route('school.students.index', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
        All students
    </a>
    <a href="{{ route('school.students.edit', ['school' => $school->slug, 'student' => $student->id]) }}" class="btn-obsidian">Edit student</a>
@endsection

@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-1">
        {{-- Identity --}}
        <section class="card p-5 sm:p-6" aria-labelledby="identity-heading">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <h2 id="identity-heading" class="font-display text-lg font-bold tracking-tight">Student</h2>
                @include('admin._badge', ['status' => $student->status, 'label' => \App\Models\Student::STATUS_LABELS[$student->status] ?? ucfirst($student->status)])
            </div>
            <dl class="mt-3 divide-y divide-brand-fog text-sm">
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Admission no.</dt>
                    <dd class="break-all text-right font-mono text-[13px] font-semibold">{{ $student->admission_number }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Class</dt>
                    <dd class="text-right font-medium">
                        {{ $student->class_name ?: '—' }}
                        @if($student->class_name && ! $student->class_level_id)<span class="block text-xs font-normal text-brand-slate">Not yet assigned to a class</span>@endif
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Session</dt>
                    <dd class="text-right font-medium">{{ $student->session?->name ?? '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-brand-slate">Added</dt>
                    @php $studentCreatedAt = \App\Support\BusinessTime::display($student->created_at); @endphp
                    <dd class="text-right font-medium"><time datetime="{{ $studentCreatedAt?->toIso8601String() }}">{{ $studentCreatedAt?->format('d M Y') }}</time></dd>
                </div>
            </dl>
        </section>

        {{-- Guardian --}}
        <section class="card p-5 sm:p-6" aria-labelledby="guardian-heading">
            <h2 id="guardian-heading" class="font-display text-lg font-bold tracking-tight">Parent / guardian</h2>
            @if($student->guardian_name || $student->guardian_phone || $student->guardian_email)
                <dl class="mt-3 divide-y divide-brand-fog text-sm">
                    <div class="flex items-baseline justify-between gap-4 py-2.5">
                        <dt class="text-brand-slate">Name</dt>
                        <dd class="text-right font-medium">{{ $student->guardian_name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 py-2.5">
                        <dt class="text-brand-slate">Phone</dt>
                        <dd class="text-right font-medium">{{ $student->guardian_phone ?? '—' }}</dd>
                    </div>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5">
                        <dt class="text-brand-slate">Email</dt>
                        <dd class="break-all text-right font-medium">{{ $student->guardian_email ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <p class="mt-2 text-sm text-brand-slate">No guardian details recorded.</p>
            @endif
        </section>

        {{-- Collections --}}
        <section class="card p-5 sm:p-6" aria-labelledby="paid-heading">
            <h2 id="paid-heading" class="text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate">Total fees paid</h2>
            <p class="mt-1 font-display text-2xl font-extrabold tabular-nums tracking-tight">₦{{ number_format($totalPaid, 2) }}</p>
            <p class="mt-1 text-sm text-brand-slate">School share of successful payments, all time.</p>
        </section>
    </div>

    {{-- Payment history: successful and pending payments by default; other attempts
         only when asked for (?attempts=1) so they never read as money collected. --}}
    <section class="min-w-0 lg:col-span-2" aria-labelledby="history-heading">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
            <h2 id="history-heading" class="font-display text-lg font-bold tracking-tight">Payment history</h2>
            <p class="text-sm text-brand-slate">
                {{ number_format($transactions->total()) }} {{ $showAttempts ? Str::plural('record', $transactions->total()) : Str::plural('payment', $transactions->total()) }}
                @if($otherAttempts > 0)
                    <span aria-hidden="true">·</span>
                    @if($showAttempts)
                        <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id]) }}" class="rounded font-medium text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">Hide {{ $otherAttempts }} unsuccessful {{ Str::plural('attempt', $otherAttempts) }}</a>
                    @else
                        <a href="{{ route('school.students.show', ['school' => $school->slug, 'student' => $student->id, 'attempts' => 1]) }}" class="rounded font-medium text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">Show {{ $otherAttempts }} unsuccessful {{ Str::plural('attempt', $otherAttempts) }}</a>
                    @endif
                @endif
            </p>
        </div>
        <x-admin.table
            :columns="$transactions->isEmpty() ? [] : [['Date'], ['Fee'], ['Amount', 'right'], ['Status'], ['Actions', 'actions']]"
            caption="Payments by {{ $student->full_name }}, newest first"
            class="md:[&_.th]:px-3 md:[&_.td]:px-3 xl:[&_.th]:px-2.5 xl:[&_.td]:px-2.5"
            stacked>
            @forelse($transactions as $t)
                @php $b = $t->receiptBreakdown(); $when = \App\Support\BusinessTime::display($t->paid_at ?? $t->created_at); @endphp
                <tr>
                    <td class="td" data-label="Date">
                        <div class="min-w-0">
                            <time datetime="{{ $when?->toIso8601String() }}" class="whitespace-nowrap font-medium">{{ $when?->format('d M Y') }}</time>
                            <span class="block text-xs text-brand-slate">{{ $when?->format('H:i') }}</span>
                        </div>
                    </td>
                    <td class="td" data-label="Fee">
                        <div class="min-w-0">
                            <span class="font-medium">{{ $t->subcategory_name ?? '—' }}</span>
                            <span class="block text-xs text-brand-slate">{{ $t->category_name }}@if($b['quantity'] > 1) × {{ $b['quantity'] }}@endif @if($t->term_name)· {{ $t->term_name }}, {{ $t->session_name }}@endif</span>
                        </div>
                    </td>
                    <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                        <div class="min-w-0">
                            <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">₦{{ number_format($b['fee_subtotal'], 2) }}</span>
                            @if($b['quantity'] > 1)<span class="block text-xs text-brand-slate">{{ $b['quantity'] }} × ₦{{ number_format($b['unit_price'], 2) }}</span>@endif
                        </div>
                    </td>
                    <td class="td" data-label="Status">@include('admin._badge', ['status' => $t->status])</td>
                    <td class="td td-actions" data-label="">
                        <div class="flex w-full flex-wrap gap-2 md:w-auto md:justify-end xl:flex-nowrap">
                            @if($t->status === 'success')
                                <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-3 text-sm md:flex-none" href="{{ route('payment.receipt', $t->id) }}">Receipt<span class="sr-only"> for {{ $t->reference }}</span></a>
                            @endif
                            <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-3 text-sm md:flex-none" href="{{ route('school.transactions.show', ['school' => $school->slug, 'transaction' => $t->id]) }}">View<span class="sr-only"> transaction {{ $t->reference }}</span></a>
                        </div>
                    </td>
                </tr>
            @empty
                <x-slot:empty>
                    <x-admin.empty
                        title="No payments yet"
                        description="Payments made for {{ $student->full_name }} on your payment page will appear here."
                        icon="M4 8h16M4 16h16M8 4l-4 4 4 4M16 12l4 4-4 4"
                        compact />
                </x-slot:empty>
            @endforelse
            @if($transactions->hasPages())
                <x-slot:footer>{{ $transactions->links() }}</x-slot:footer>
            @endif
        </x-admin.table>
    </section>
</div>
@endsection
