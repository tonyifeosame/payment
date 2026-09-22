{{-- Shared payment → payout timeline. $steps come from App\Services\PaymentTimeline:
     each is [label, note, at (Carbon|null), state (done|current|attention|upcoming)].
     State is conveyed by icon, colour AND sr-only text. --}}
        <ol class="mt-5">
            @foreach($steps as $step)
                @php
                    [$dot, $stateText] = match ($step['state']) {
                        'done' => ['bg-brand-obsidian text-white', 'Completed'],
                        'current' => ['bg-brand-violet text-white', 'In progress'],
                        'attention' => ['bg-red-600 text-white', 'Needs attention'],
                        default => ['border-2 border-brand-ash bg-white text-brand-ash', 'Not yet'],
                    };
                @endphp
                <li class="relative flex gap-4 pb-6 last:pb-0">
                    @unless($loop->last)
                        <span class="absolute left-[11px] top-7 bottom-0 w-0.5 bg-brand-ash/70" aria-hidden="true"></span>
                    @endunless
                    <span class="relative mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full {{ $dot }}" aria-hidden="true">
                        @if($step['state'] === 'done')
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                        @elseif($step['state'] === 'attention')
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 7v6m0 4v.5"/></svg>
                        @elseif($step['state'] === 'current')
                            <span class="h-2 w-2 rounded-full bg-white"></span>
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                            <p class="font-semibold {{ $step['state'] === 'upcoming' ? 'text-brand-slate' : '' }}">
                                {{ $step['label'] }}<span class="sr-only"> — {{ $stateText }}</span>
                            </p>
                            @if($step['at'])
                                @php $stepAt = \App\Support\BusinessTime::display($step['at']); @endphp
                                <time datetime="{{ $stepAt->toIso8601String() }}" class="text-xs text-brand-slate tabular-nums">{{ $stepAt->format('d M Y, H:i') }}</time>
                            @endif
                        </div>
                        <p class="mt-0.5 text-sm text-brand-slate">{{ $step['note'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
