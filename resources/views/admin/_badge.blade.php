{{-- Shared status badge. Semantic colour comes from the raw status string the models
     already use; the visible text is $label when given (e.g. PayoutController::STATUS_LABELS),
     otherwise ucfirst($status). Usage:
       @include('admin._badge', ['status' => $t->status])
       @include('admin._badge', ['status' => 'current', 'label' => 'Current term']) --}}
@php
    $tone = match ($status ?? '') {
        'success', 'paid', 'active', 'graduated' => 'bg-green-50 text-green-800',
        'pending', 'queued', 'initiating', 'sending', 'processing' => 'bg-brand-violet/10 text-brand-violet',
        'failed', 'mismatch', 'needs_review' => 'bg-red-50 text-red-700',
        'reversed', 'left', 'inactive' => 'bg-brand-fog text-brand-slate',
        'current' => 'bg-brand-violet text-white',
        default => 'bg-brand-fog text-brand-obsidian',
    };
    $dot = match ($status ?? '') {
        'success', 'paid', 'active', 'graduated' => 'bg-green-600',
        'pending', 'queued', 'initiating', 'sending', 'processing' => 'bg-brand-violet',
        'failed', 'mismatch', 'needs_review' => 'bg-red-600',
        'current' => 'bg-brand-zest',
        default => 'bg-brand-slate',
    };
@endphp
<span class="badge {{ $tone }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $label ?? ucfirst(str_replace('_', ' ', (string) ($status ?? ''))) }}</span>
