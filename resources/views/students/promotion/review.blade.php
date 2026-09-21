@extends('layouts.admin')

@section('title', 'Confirm promotion')
@section('eyebrow', 'School · Students')
@section('heading', 'Confirm promotion')
@section('subheading', 'Check the summary. Nothing has changed yet.')

@section('content')
@php
    $s = ['school' => $school->slug];
    $promoting = $rows->filter(fn ($r) => $r['to'] !== null)->count();
    $graduating = $rows->count() - $promoting;
@endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:items-start">
    <section class="card p-5 sm:p-6 lg:col-span-2" aria-labelledby="summary-heading">
        <h2 id="summary-heading" class="font-display text-lg font-bold tracking-tight">You are about to promote</h2>
        <p class="mt-2 font-display text-3xl font-extrabold tracking-tight">{{ $rows->count() }} {{ Str::plural('student', $rows->count()) }}</p>
        <p class="mt-1 text-sm text-brand-slate">{{ $current?->name ?? 'Current session' }} → <span class="font-semibold text-brand-obsidian">{{ $to->name }}</span></p>

        <div class="mt-5 overflow-hidden rounded-2xl border border-brand-ash/60">
            <table class="w-full text-sm">
                <caption class="sr-only">Students per class change</caption>
                <thead class="bg-brand-fog"><tr>
                    <th scope="col" class="th">From</th>
                    <th scope="col" class="th">To</th>
                    <th scope="col" class="th w-px whitespace-nowrap text-right">Students</th>
                </tr></thead>
                <tbody class="divide-y divide-brand-fog">
                    @foreach($groups as $g)
                        <tr>
                            <td class="td font-semibold">{{ $g['from']->name }}</td>
                            <td class="td">@if($g['to']){{ $g['to']->name }}@else<span class="font-semibold">Graduated</span>@endif</td>
                            <td class="td text-right tabular-nums">{{ $g['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="mt-4 space-y-1 text-sm text-brand-slate">
            @if($graduating > 0)
                <li>{{ $graduating }} {{ Str::plural('student', $graduating) }} in your last class will be marked <span class="font-semibold text-brand-obsidian">Graduated</span> and kept on record.</li>
            @endif
            <li>{{ $excluded }} {{ Str::plural('student', $excluded) }} excluded — they stay exactly as they are.</li>
            <li>This changes each student's current class. Payments and receipts already issued are not affected.</li>
        </ul>

        <form method="POST" action="{{ route('school.students.promotion.store', $s) }}" class="mt-6"
              data-confirm="{{ $rows->count() }} {{ Str::plural('student', $rows->count()) }} will move to their next class{{ $graduating ? ' or be marked graduated' : '' }}. This is applied in one step and recorded in your promotion history."
              data-confirm-title="Promote {{ $rows->count() }} {{ Str::plural('student', $rows->count()) }} into {{ $to->name }}?"
              data-confirm-label="Confirm promotion">
            @csrf
            <input type="hidden" name="to_session_id" value="{{ $to->id }}">
            @foreach($rows as $row)
                <input type="hidden" name="students[]" value="{{ $row['student']->id }}">
                <input type="hidden" name="from[{{ $row['student']->id }}]" value="{{ $row['from']->id }}">
            @endforeach
            <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                <a href="{{ route('school.students.promotion.index', $s + ['to_session_id' => $to->id]) }}" class="btn-outline">Back to selection</a>
                <button type="submit" class="btn-obsidian">Confirm promotion</button>
            </div>
        </form>
    </section>

    <section class="card p-5 sm:p-6" aria-labelledby="list-heading">
        <h2 id="list-heading" class="font-display text-lg font-bold tracking-tight">Students</h2>
        <ol class="mt-3 max-h-[32rem] divide-y divide-brand-fog overflow-y-auto text-sm">
            @foreach($rows as $row)
                <li class="py-2">
                    <span class="block font-medium">{{ $row['student']->full_name }}</span>
                    <span class="block text-xs text-brand-slate">{{ $row['from']->name }} <span aria-hidden="true">→</span><span class="sr-only">to</span> {{ $row['to']?->name ?? 'Graduated' }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
