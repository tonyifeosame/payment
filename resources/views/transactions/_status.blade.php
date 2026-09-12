@php
    $classes = match($status) {
        'success' => 'bg-green-100 text-green-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'mismatch' => 'bg-red-100 text-red-800',
        default => 'bg-slate-200 text-slate-800',
    };
@endphp
<span class="badge {{ $classes }}">{{ ucfirst($status) }}</span>
