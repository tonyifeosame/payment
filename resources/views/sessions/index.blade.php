@extends('layouts.admin')

@section('title', 'Academic sessions')
@section('heading', 'Academic sessions & terms')
@section('subheading', 'Every session has First, Second and Third Term. Fees and payments are recorded against a term.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <form method="POST" action="{{ route('school.sessions.store', ['school' => $school->slug]) }}" class="card p-5 self-start">
        @csrf
        <h2 class="font-bold text-slate-900 mb-4">New session</h2>
        <div class="space-y-4">
            <div>
                <label for="name" class="label">Session</label>
                <input id="name" name="name" value="{{ old('name') }}" class="input font-mono" placeholder="2026/2027" required pattern="\d{4}/\d{4}">
                @error('name')<p class="text-red-600 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="starts_on" class="label">Starts <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on') }}" class="input">
                </div>
                <div>
                    <label for="ends_on" class="label">Ends <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on') }}" class="input">
                </div>
            </div>
            <button type="submit" class="btn-primary w-full justify-center">Create session</button>
            <p class="text-xs text-slate-500">The three terms are created automatically. Your first session's First Term becomes the current term.</p>
        </div>
    </form>

    <div class="lg:col-span-2 space-y-4">
        @forelse($sessions as $session)
            <div class="card overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="font-black text-slate-900 text-lg">{{ $session->name }}</h2>
                        @if($session->starts_on || $session->ends_on)
                            <p class="text-xs text-slate-500">{{ $session->starts_on?->format('d M Y') ?? '…' }} – {{ $session->ends_on?->format('d M Y') ?? '…' }}</p>
                        @endif
                    </div>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach($session->terms as $term)
                        <li class="px-5 py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="font-semibold">{{ $term->name }}</span>
                                @if($term->id === $currentTermId)
                                    <span class="badge bg-green-100 text-green-800">Current term</span>
                                @endif
                            </div>
                            @if($term->id !== $currentTermId)
                                <form method="POST" action="{{ route('school.terms.current', ['school' => $school->slug, 'academicTerm' => $term->id]) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary !py-1.5 !px-3">Set as current</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="card p-8 text-center text-slate-500">
                No sessions yet. Create your first one — e.g. <span class="font-mono">{{ now()->year }}/{{ now()->year + 1 }}</span>.
            </div>
        @endforelse
    </div>
</div>
@endsection
