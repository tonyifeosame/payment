@extends('layouts.admin')

@section('title', 'Categories')
@section('eyebrow', 'Fee setup')
@section('heading', 'Categories')
@section('subheading', 'Organize the fees your school collects.')
@section('inline-errors', '1')
@section('actions')
    <a href="#new-category" class="btn-obsidian">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add category
    </a>
@endsection

@section('content')
@php $s = ['school' => $school->slug]; @endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start">

    {{-- New category (the existing POST route; creation has always been inline here) --}}
    <form id="new-category" method="POST" action="{{ route('school.categories.store', $s) }}" class="card scroll-mt-24 p-5 sm:p-6 lg:col-span-2 lg:sticky lg:top-10" aria-labelledby="new-category-heading">
        @csrf
        <h2 id="new-category-heading" class="font-display text-lg font-bold tracking-tight">New category</h2>
        <p class="mt-1 text-sm text-brand-slate">A category groups related fee types — for example everything to do with tuition, or with uniforms.</p>
        <div class="mt-5">
            <label for="name" class="field-label">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" class="field-input {{ $errors->has('name') ? 'field-input-error' : '' }}" required maxlength="255" placeholder="e.g. Tuition" autocomplete="off" aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
            <p id="name-help" class="field-help">Parents see this on the payment page above the fee types it contains.</p>
            @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-obsidian mt-5 w-full">Create category</button>
    </form>

    {{-- Categories --}}
    <div class="lg:col-span-3">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-1">
            <p class="text-sm text-brand-slate"><span class="font-display text-lg font-bold text-brand-obsidian">{{ $categories->count() }}</span> {{ Str::plural('category', $categories->count()) }}</p>
            <a href="{{ route('school.subcategories.index', $s) }}" class="rounded text-sm font-semibold text-brand-violet hover:underline focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30">Manage fee types</a>
        </div>
        <x-admin.table
            :columns="$categories->isEmpty() ? [] : [['Category'], ['Fee types', 'left', 'xl:w-[22%]'], ['Added', 'left', 'xl:w-[18%]'], ['Actions', 'actions', 'xl:w-[32%]']]"
            caption="Fee categories of {{ $school->name }}"
            class="xl:[&_table]:w-full xl:[&_table]:table-fixed md:[&_.th]:px-3 md:[&_.td]:px-3"
            stacked>
            @forelse($categories as $category)
                @php $count = (int) ($category->subcategories_count ?? 0); @endphp
                <tr>
                    <td class="td" data-label="Category">
                        <div class="min-w-0"><span class="font-semibold">{{ $category->name }}</span></div>
                    </td>
                    <td class="td" data-label="Fee types">
                        <div class="min-w-0">
                            <span class="font-medium">{{ $count }} {{ Str::plural('fee type', $count) }}</span>
                            @if($count === 0)<span class="block text-xs text-brand-slate">Parents cannot pay into an empty category</span>@endif
                        </div>
                    </td>
                    <td class="td" data-label="Added">
                        <time datetime="{{ $category->created_at?->toIso8601String() }}" class="whitespace-nowrap">{{ $category->created_at?->format('d M Y') }}</time>
                    </td>
                    <td class="td td-actions" data-label="">
                        <div class="flex w-full gap-2 md:w-auto md:justify-end">
                            <a class="btn-outline btn-sm !min-h-[48px] flex-1 !px-4 text-sm md:flex-none" href="{{ route('school.categories.edit', $s + ['category' => $category->id]) }}">Edit<span class="sr-only"> {{ $category->name }}</span></a>
                            <form method="POST" action="{{ route('school.categories.destroy', $s + ['category' => $category->id]) }}" class="flex flex-1 md:flex-none"
                                  data-confirm="{{ $count > 0 ? 'This also deletes its '.$count.' '.Str::plural('fee type', $count).', so parents can no longer pay for them. ' : '' }}Past payments keep their records. This cannot be undone."
                                  data-confirm-title="Delete “{{ $category->name }}”?"
                                  data-confirm-label="Delete category"
                                  data-confirm-tone="danger">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger btn-sm !min-h-[48px] w-full !px-4 text-sm md:w-auto">Delete<span class="sr-only"> {{ $category->name }}</span></button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <x-slot:empty>
                    <x-admin.empty title="No fee categories yet" description="Categories organize your fee types — create one, then add the fee types parents can pay under it." icon="M4 6h16M4 12h16M4 18h10">
                        <a class="btn-obsidian" href="#new-category">Add category</a>
                    </x-admin.empty>
                </x-slot:empty>
            @endforelse
        </x-admin.table>
    </div>
</div>
@endsection
