@extends('layouts.admin')

@section('title', 'Academic sessions')
@section('eyebrow', 'Academic year')
@section('heading', 'Academic sessions & terms')
@section('subheading', 'Every session has First, Second and Third Term. Fees and payments are recorded against a term.')
{{-- Every field below shows its own error, so the shared banner only points at them. --}}
@section('inline-errors', '1')

@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-5 lg:items-start">

    {{-- New session --}}
    <form method="POST" action="{{ route('school.sessions.store', ['school' => $school->slug]) }}" class="card p-5 sm:p-6 lg:col-span-2 lg:sticky lg:top-10" aria-labelledby="new-session-heading">
        @csrf
        <h2 id="new-session-heading" class="font-display text-lg font-bold tracking-tight">New academic session</h2>
        <p class="mt-1 text-sm text-brand-slate">The three terms are created automatically.</p>

        <div class="mt-5 space-y-5">
            <div>
                <label for="name" class="field-label">Session</label>
                <input id="name" name="name" value="{{ old('name') }}" class="field-input font-mono {{ $errors->has('name') ? 'field-input-error' : '' }}" placeholder="{{ now()->year }}/{{ now()->year + 1 }}" required pattern="\d{4}/\d{4}" inputmode="numeric" autocomplete="off" aria-describedby="name-help{{ $errors->has('name') ? ' name-error' : '' }}" @if($errors->has('name')) aria-invalid="true" @endif>
                <p id="name-help" class="field-help">Two consecutive years, e.g. {{ now()->year }}/{{ now()->year + 1 }}.</p>
                @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="starts_on" class="field-label">Starts <span class="font-normal text-brand-slate">(optional)</span></label>
                    <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on') }}" class="field-input {{ $errors->has('starts_on') ? 'field-input-error' : '' }}" @if($errors->has('starts_on')) aria-invalid="true" aria-describedby="starts_on-error" @endif>
                    @error('starts_on')<p id="starts_on-error" class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="ends_on" class="field-label">Ends <span class="font-normal text-brand-slate">(optional)</span></label>
                    <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on') }}" class="field-input {{ $errors->has('ends_on') ? 'field-input-error' : '' }}" @if($errors->has('ends_on')) aria-invalid="true" aria-describedby="ends_on-error" @endif>
                    @error('ends_on')<p id="ends_on-error" class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <button type="submit" class="btn-obsidian w-full">Create session</button>
            <p class="text-sm text-brand-slate">Your first session's First Term becomes the current term. You can change the current term at any time from the list.</p>
        </div>
    </form>

    {{-- Sessions --}}
    <div class="space-y-4 lg:col-span-3">
        @forelse($sessions as $session)
            <section class="card overflow-hidden" aria-labelledby="session-{{ $session->id }}">
                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-4 sm:px-6">
                    <h2 id="session-{{ $session->id }}" class="font-display text-xl font-bold tracking-tight">{{ $session->name }}</h2>
                    @if($session->starts_on || $session->ends_on)
                        <p class="text-sm text-brand-slate">{{ $session->starts_on?->format('d M Y') ?? '…' }} – {{ $session->ends_on?->format('d M Y') ?? '…' }}</p>
                    @endif
                </div>
                <ul class="divide-y divide-brand-fog border-t border-brand-ash/60">
                    @foreach($session->terms as $term)
                        @php $isCurrent = $term->id === $currentTermId; @endphp
                        <li class="flex min-h-[64px] items-center justify-between gap-4 px-5 py-2.5 sm:px-6 {{ $isCurrent ? 'bg-brand-violet/5' : '' }}">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-semibold {{ $isCurrent ? 'text-brand-violet' : '' }}">{{ $term->name }}</span>
                                @if($isCurrent)
                                    @include('admin._badge', ['status' => 'current', 'label' => 'Current term'])
                                @endif
                            </div>
                            @unless($isCurrent)
                                <form method="POST" action="{{ route('school.terms.current', ['school' => $school->slug, 'academicTerm' => $term->id]) }}" class="shrink-0"
                                      data-confirm="Fees and the payment page will default to {{ $term->name }}, {{ $session->name }} for every parent. You can change it again later."
                                      data-confirm-title="Make {{ $term->name }} the current term?"
                                      data-confirm-label="Set as current">
                                    @csrf
                                    <button type="submit" class="btn-outline btn-sm !min-h-[48px] text-sm" aria-label="Set {{ $term->name }}, {{ $session->name }} as the current term">Set as current</button>
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="card">
                <x-admin.empty
                    title="No sessions yet"
                    description="Create your first session — e.g. {{ now()->year }}/{{ now()->year + 1 }} — and its three terms will be set up for you."
                    icon="M8 3v3M16 3v3M4 9h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z" />
            </div>
        @endforelse
    </div>
</div>
@endsection
