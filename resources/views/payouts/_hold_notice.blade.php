{{-- H3: tells the school why its payouts are not moving. Payments are unaffected. --}}
@if(! $school->canReceivePayouts())
    @php
        $verified = $school->payouts_approved_at !== null;
        $until = $school->payout_hold_until ? \App\Support\BusinessTime::display($school->payout_hold_until) : null;
    @endphp
    <div class="mb-5 flex gap-3 rounded-2xl border border-brand-violet/20 bg-brand-violet/10 p-4 text-brand-obsidian" role="status" data-payout-hold>
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-violet text-white" aria-hidden="true">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8h.01M12 11v5m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </span>
        <div class="min-w-0 pt-0.5 text-sm">
            @if(! $verified)
                <p class="font-semibold">Payouts start once FEYRA has verified your school.</p>
                <p class="mt-1">Parents can already pay you. Every payment is recorded here, and the money is sent to your account as soon as verification is complete.</p>
            @else
                <p class="font-semibold">Payouts are paused until {{ $until?->format('d M Y, H:i') }} ({{ \App\Support\BusinessTime::label() }}) because your payout account changed.</p>
                <p class="mt-1">This protects your school if someone else changed it. Parents can keep paying; their payments are paid out when the pause ends. If you did not make this change, reset your password and contact support.</p>
            @endif
        </div>
    </div>
@endif
