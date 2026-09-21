@extends('layouts.admin')

@section('title', 'Fee types')
@section('eyebrow', 'Fee setup')
@section('heading', 'Fee types')
@section('subheading', 'Set the fees students can pay for each academic term.')
@section('actions')
    <a href="{{ route('school.categories.index', ['school' => $school->slug]) }}" class="btn-outline">Categories</a>
    <a href="{{ route('school.subcategories.create', ['school' => $school->slug]) }}" class="btn-obsidian">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add fee type
    </a>
@endsection

@section('content')
@php
    $s = ['school' => $school->slug];
    $money = fn ($v) => '₦'.number_format((float) $v, 2);
@endphp

<div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
    <p class="text-sm text-brand-slate"><span class="font-display text-lg font-bold text-brand-obsidian">{{ $subcategories->count() }}</span> {{ Str::plural('fee type', $subcategories->count()) }} across {{ $categories->count() }} {{ Str::plural('category', $categories->count()) }}</p>
    <p class="text-sm text-brand-slate">A term fee can only be paid for that term; general fees can be paid in any term.</p>
</div>

<x-admin.table
    :columns="$subcategories->isEmpty() ? [] : [['Fee type', 'left', 'xl:w-[26%]'], ['Category', 'left', 'xl:w-[16%]'], ['Amount', 'right', 'xl:w-[14%]'], ['Session', 'left', 'xl:w-[12%]'], ['Term', 'left', 'xl:w-[14%]'], ['Actions', 'actions', 'xl:w-[18%]']]"
    caption="Fee types of {{ $school->name }}, grouped by category"
    class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
    stacked>
    @forelse($subcategories as $sub)
        <tr>
            <td class="td" data-label="Fee type">
                <div class="min-w-0"><span class="font-semibold">{{ $sub->name }}</span></div>
            </td>
            <td class="td" data-label="Category">
                <div class="min-w-0"><span class="font-medium">{{ $sub->category->name ?? '—' }}</span></div>
            </td>
            <td class="td text-right lg:whitespace-nowrap" data-label="Amount">
                <div class="min-w-0">
                    @if($sub->price === null)
                        <span class="text-sm text-brand-slate">Not set</span>
                    @else
                        <span class="whitespace-nowrap font-display text-base font-bold tabular-nums">{{ $money($sub->price) }}</span>
                    @endif
                </div>
            </td>
            <td class="td" data-label="Session">
                <div class="min-w-0">{{ $sub->academicTerm?->session?->name ?? '—' }}</div>
            </td>
            <td class="td" data-label="Term">
                <div class="min-w-0">
                    @if($sub->academicTerm)
                        <span class="font-medium">{{ $sub->academicTerm->name }}</span>
                    @else
                        <span class="font-medium">General</span>
                        <span class="block text-xs text-brand-slate">Payable in any term</span>
                    @endif
                </div>
            </td>
            <td class="td td-actions" data-label="">
                <div class="flex w-full gap-2 md:w-auto md:justify-end">
                    <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.subcategories.edit', $s + ['subcategory' => $sub->id]) }}">Edit<span class="sr-only"> {{ $sub->name }}</span></a>
                    <form method="POST" action="{{ route('school.subcategories.destroy', $s + ['subcategory' => $sub->id]) }}" class="flex flex-1 md:flex-none"
                          data-confirm="Parents will no longer be able to pay for it. Past payments keep their records. This cannot be undone."
                          data-confirm-title="Delete “{{ $sub->name }}”?"
                          data-confirm-label="Delete fee type"
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
            @if($categories->isEmpty())
                <x-admin.empty title="No fee types yet" description="Fee types need a category to live in. Create your first category, then add the fees parents can pay under it." icon="M12 4v16m4-12H10a2.5 2.5 0 000 5h4a2.5 2.5 0 010 5H8">
                    <a class="btn-obsidian" href="{{ route('school.categories.index', $s) }}">Add a category first</a>
                </x-admin.empty>
            @else
                <x-admin.empty title="No fee types yet" description="Add the fees parents can pay — each one belongs to a category, has an amount, and can be tied to an academic term." icon="M12 4v16m4-12H10a2.5 2.5 0 000 5h4a2.5 2.5 0 010 5H8">
                    <a class="btn-obsidian" href="{{ route('school.subcategories.create', $s) }}">Add fee type</a>
                </x-admin.empty>
            @endif
        </x-slot:empty>
    @endforelse
</x-admin.table>
@endsection
