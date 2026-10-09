@extends('layouts.admin')

@section('subnav')
    @include('admin._subnav', ['section' => 'fees'])
@endsection

@section('title', 'Fees')
@section('eyebrow', 'Fees')
@section('heading', 'Fees')
@section('subheading', 'School fees and additional fees parents can pay, by academic year and term.')
@section('actions')
    <a href="{{ route('school.subcategories.export', ['school' => $school->slug]) }}" class="btn-outline">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0l-4-4m4 4l4-4M5 19h14"/></svg>
        Export CSV
    </a>
    <a href="{{ route('school.subcategories.create', ['school' => $school->slug]) }}" class="btn-obsidian">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add fee
    </a>
@endsection

@section('content')
@php
    $s = ['school' => $school->slug];
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
@endphp

@include('subcategories._current_term')

<div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
    <p class="text-sm text-brand-slate"><span class="font-display text-lg font-bold text-brand-obsidian">{{ $subcategories->count() }}</span> {{ Str::plural('fee', $subcategories->count()) }} across {{ $categories->count() }} {{ Str::plural('category', $categories->count()) }}</p>
    <p class="text-sm text-brand-slate">A term fee can only be paid for that term, and a fee assigned to classes only by students in those classes. School fees are paid once per student each term.</p>
</div>

<x-admin.table
    :columns="$subcategories->isEmpty() ? [] : [['Fee', 'left', 'xl:w-[22%]'], ['Category', 'left', 'xl:w-[12%]'], ['Applies to', 'left', 'xl:w-[15%]'], ['Amount', 'right', 'xl:w-[12%]'], ['Academic year', 'left', 'xl:w-[10%]'], ['Term', 'left', 'xl:w-[11%]'], ['Actions', 'actions', 'xl:w-[18%]']]"
    caption="Fees of {{ $school->name }}, school fees first"
    class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
    stacked>
    @forelse($subcategories as $sub)
        <tr>
            <td class="td" data-label="Fee">
                <div class="min-w-0">
                    <span class="font-semibold">{{ $sub->name }}</span>
                    @if($sub->is_tuition)
                        <span class="block text-xs text-brand-slate">School fees · main class fee</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Category">
                <div class="min-w-0"><span class="font-medium">{{ $sub->category->name ?? '—' }}</span></div>
            </td>
            <td class="td" data-label="Applies to">
                <div class="min-w-0">
                    @if($sub->classLevels->isEmpty())
                        <span class="font-medium">All classes</span>
                    @else
                        <span class="font-medium">{{ $sub->classLevels->sortBy([['position', 'asc'], ['id', 'asc']])->pluck('name')->implode(', ') }}</span>
                    @endif
                </div>
            </td>
            <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                <div class="min-w-0">
                    @if($sub->price === null)
                        <span class="text-sm text-brand-slate">Not set</span>
                    @else
                        <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">{{ $money($sub->price) }}</span>
                    @endif
                    @if($sub->allows_quantity)
                        <span class="block text-xs text-brand-slate">Per unit · multiple allowed</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Academic year">
                <div class="min-w-0">{{ $sub->academicTerm?->session?->name ?? '—' }}</div>
            </td>
            <td class="td" data-label="Term">
                <div class="min-w-0">
                    @if($sub->academicTerm)
                        <span class="font-medium">{{ $sub->academicTerm->name }}</span>
                    @else
                        <span class="font-medium">Any term</span>
                    @endif
                </div>
            </td>
            <td class="td td-actions" data-label="">
                <div class="flex w-full gap-2 md:w-auto md:justify-end">
                    <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.subcategories.edit', $s + ['subcategory' => $sub->id]) }}">Edit<span class="sr-only"> {{ $sub->name }}</span></a>
                    <form method="POST" action="{{ route('school.subcategories.destroy', $s + ['subcategory' => $sub->id]) }}" class="flex flex-1 md:flex-none"
                          data-confirm="Parents will no longer be able to pay for it. Past payments keep their records. This cannot be undone."
                          data-confirm-title="Delete “{{ $sub->name }}”?"
                          data-confirm-label="Delete fee"
                          data-confirm-tone="danger">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger btn-sm !min-h-[48px] w-full !px-4 text-sm md:w-auto">Delete<span class="sr-only"> {{ $sub->name }}</span></button>
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <x-slot:empty>
            <x-admin.empty title="No fees yet" description="Start with your school fees: choose the academic year and term, the amount, and the classes they are for. Add uniforms, books and other additional fees the same way." icon="M12 4v16m4-12H10a2.5 2.5 0 000 5h4a2.5 2.5 0 010 5H8">
                <a class="btn-obsidian" href="{{ route('school.subcategories.create', $s) }}">Add school fees</a>
            </x-admin.empty>
        </x-slot:empty>
    @endforelse
</x-admin.table>
@endsection
