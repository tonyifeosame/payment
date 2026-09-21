{{-- Shared admin flash/validation messages. Session keys are the ones controllers already
     use (success, error, status) plus the validator's $errors; nothing is renamed.

     $inlineErrors (bool): the including page renders every field error next to its field,
     so the banner only points at them instead of repeating each message. --}}
@php
    $inlineErrors = $inlineErrors ?? false;
    $alerts = [];
    if (session('success')) {
        $alerts[] = ['success', session('success'), null];
    }
    if (session('error')) {
        $alerts[] = ['error', session('error'), null];
    }
    if (session('status')) {
        $alerts[] = ['info', session('status'), null];
    }
    if ($errors->any()) {
        $alerts[] = ['error', $inlineErrors
            ? 'Please check the '.($errors->count() === 1 ? 'highlighted field' : $errors->count().' highlighted fields').' below.'
            : 'Please fix the following:', $inlineErrors ? null : $errors->all()];
    }
    $styles = [
        'success' => ['border-green-200 bg-green-50 text-green-900', 'bg-green-600', 'M5 12.5l4.5 4.5L19 7'],
        'error' => ['border-red-200 bg-red-50 text-red-900', 'bg-red-600', 'M12 8v5m0 3.5v.5M5.1 19h13.8a1.5 1.5 0 001.3-2.2L13.3 4.6a1.5 1.5 0 00-2.6 0L3.8 16.8A1.5 1.5 0 005.1 19z'],
        'info' => ['border-brand-violet/20 bg-brand-violet/10 text-brand-obsidian', 'bg-brand-violet', 'M12 8h.01M12 11v5m9-4a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
@endphp
@foreach($alerts as [$type, $message, $list])
    @php [$box, $dot, $icon] = $styles[$type]; @endphp
    <div class="mb-5 flex gap-3 rounded-2xl border p-4 {{ $box }}" role="{{ $type === 'error' ? 'alert' : 'status' }}">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white {{ $dot }}" aria-hidden="true">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
        </span>
        <div class="min-w-0 pt-0.5 text-sm">
            <p class="font-semibold">{{ $message }}</p>
            @if($list)
                <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
                    @foreach($list as $item)<li>{{ $item }}</li>@endforeach
                </ul>
            @endif
        </div>
    </div>
@endforeach
