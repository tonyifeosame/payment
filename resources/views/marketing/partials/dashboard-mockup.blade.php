{{--
    CSS-only representation of the school dashboard. Two variants:
      compact — hero: stat tiles, recent payments, payout status
      full    — showcase: real sidebar nav + tiles + collections by category + payouts + recent payments

    Every figure here is demonstration data and is labelled as such. Labels mirror the
    real dashboard (Total collected / Pending / Paid to bank / On the way / Needs attention)
    and the sidebar mirrors the real admin navigation. Nothing is shown that the product
    does not have.
--}}
@php
    $variant = $variant ?? 'compact';
    $isFull = $variant === 'full';

    // Keep the demo context current: the academic session/term follow today's date.
    $now = now();
    $sessionStart = $now->month >= 9 ? $now->year : $now->year - 1;
    $sessionLabel = $sessionStart.'/'.($sessionStart + 1);
    $termLabel = match (true) {
        $now->month >= 9 => 'First Term',
        $now->month >= 5 => 'Third Term',
        default => 'Second Term',
    };
    $d = fn (int $daysAgo) => $now->copy()->subDays($daysAgo)->format('d M');

    $tiles = [
        ['Total collected', '₦4,850,000', '32 payments this term', 'bg-brand-violet/10', 'text-brand-violet'],
        ['Pending payments', '6', '₦180,000 not yet completed', 'bg-brand-zest/25', 'text-brand-obsidian'],
        ['Students', '312', 'on the roster', 'bg-brand-fog', 'text-brand-obsidian'],
        ['Paid to bank', '₦4,610,000', '28 payouts', 'bg-green-50', 'text-green-700'],
    ];
    $recent = [
        ['Adaeze Okafor', 'ADM-0412 · Tuition · '.$termLabel, '₦150,000', 'success', $d(0)],
        ['Chinedu Bello', 'ADM-0387 · Boarding · '.$termLabel, '₦95,000', 'success', $d(0)],
        ['Fatima Yusuf', 'ADM-0290 · Tuition · '.$termLabel, '₦150,000', 'pending', $d(1)],
        ['Tunde Adeyemi', 'ADM-0455 · Uniform · '.$termLabel, '₦18,500', 'success', $d(1)],
        ['Ngozi Eze', 'ADM-0133 · PTA levy · '.$termLabel, '₦5,000', 'success', $d(2)],
    ];
    $categories = [
        ['Tuition', 68, '₦3,298,000'],
        ['Boarding', 20, '₦970,000'],
        ['Uniform', 8, '₦388,000'],
        ['PTA levy', 4, '₦194,000'],
    ];
    $payouts = [
        ['Paid to bank', '₦4,610,000', '28', 'text-green-700'],
        ['On the way', '₦240,000', '2', 'text-amber-600'],
        ['Needs attention', '₦0', '0', 'text-red-600'],
    ];
    $sidebar = ['Dashboard', 'Students', 'Sessions', 'Categories', 'Fee Types', 'Transactions', 'Payouts', 'Share', 'Settings'];
    $badge = fn (string $s) => $s === 'success'
        ? 'bg-green-100 text-green-800'
        : 'bg-amber-100 text-amber-800';
@endphp

<div class="mx-auto w-full {{ $isFull ? '' : 'max-w-xl lg:max-w-none' }}">
    <div role="img"
         aria-label="Illustration of the @include('marketing.partials.brand-name') school dashboard showing total collected, pending payments, student count, recent payments{{ $isFull ? ', collections by category' : '' }} and payout status, using demonstration data."
         class="overflow-hidden rounded-4xl border border-brand-obsidian/10 bg-brand-obsidian p-1.5 shadow-[0_30px_80px_-20px_rgba(18,18,23,0.45)]">
        <div aria-hidden="true" class="flex overflow-hidden rounded-[26px] bg-white text-brand-obsidian">

            @if($isFull)
                {{-- Sidebar: the real admin navigation. Hidden below md so the main
                     panel gets the full width on phones. --}}
                <aside class="hidden w-44 shrink-0 flex-col bg-brand-obsidian px-3 py-4 text-white md:flex lg:w-52">
                    <div class="flex items-center gap-2 px-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-brand-violet text-xs font-bold">D</span>
                        <span class="truncate text-sm font-semibold">Demo School</span>
                    </div>
                    <ul class="mt-6 space-y-0.5 text-[13px] font-medium">
                        @foreach($sidebar as $i => $item)
                            <li class="rounded-lg px-3 py-2 {{ $i === 0 ? 'bg-brand-violet text-white' : 'text-white/70' }}">{{ $item }}</li>
                        @endforeach
                    </ul>
                    <div class="mt-auto rounded-lg bg-white/10 px-3 py-2 text-[11px] text-white/70">Payment page ↗</div>
                </aside>
            @endif

            <div class="min-w-0 flex-1 bg-brand-fog/60 {{ $isFull ? 'p-4 sm:p-5' : 'p-4 sm:p-5' }}">
                {{-- Header row --}}
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        @unless($isFull)
                            <span class="flex h-7 w-7 items-center justify-center rounded-md bg-brand-violet text-xs font-bold text-white">D</span>
                            <span class="text-sm font-semibold">Demo School</span>
                        @else
                            <span class="font-display text-base font-bold">Dashboard</span>
                        @endunless
                        <span class="rounded-full border border-brand-ash bg-white px-2.5 py-0.5 text-[11px] font-medium text-brand-slate">{{ $termLabel }} · {{ $sessionLabel }}</span>
                    </div>
                    <span class="rounded-full bg-brand-zest px-2.5 py-0.5 text-[11px] font-semibold text-brand-obsidian">Demo data</span>
                </div>

                {{-- Stat tiles --}}
                <div class="mt-4 grid gap-2.5 {{ $isFull ? 'grid-cols-2 xl:grid-cols-4' : 'grid-cols-2 sm:grid-cols-3' }}">
                    @foreach(($isFull ? $tiles : array_slice($tiles, 0, 3)) as [$label, $value, $sub, $bg, $color])
                        <div class="min-w-0 rounded-2xl {{ $bg }} p-3 sm:p-3.5 {{ ! $isFull && $loop->first ? 'col-span-2 sm:col-span-1' : '' }}">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-slate">{{ $label }}</p>
                            <p class="mt-1 truncate font-display text-base font-bold tabular-nums sm:text-lg {{ $color }}">{{ $value }}</p>
                            <p class="mt-0.5 hidden truncate text-[11px] text-brand-slate sm:block">{{ $sub }}</p>
                        </div>
                    @endforeach
                </div>

                @if($isFull)
                    <div class="mt-3 grid gap-3 lg:grid-cols-5">
                        {{-- Collections by category --}}
                        <div class="rounded-2xl bg-white p-4 lg:col-span-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold">Collections by category</p>
                                <span class="text-[11px] text-brand-slate">selected term</span>
                            </div>
                            <ul class="mt-3 space-y-2.5">
                                @foreach($categories as [$name, $pct, $amount])
                                    <li>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="font-medium">{{ $name }}</span>
                                            <span class="tabular-nums text-brand-slate">{{ $amount }} · {{ $pct }}%</span>
                                        </div>
                                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-brand-fog">
                                            <div class="h-full rounded-full {{ $loop->first ? 'bg-brand-violet' : ($loop->index === 1 ? 'bg-brand-iris' : ($loop->index === 2 ? 'bg-brand-zest' : 'bg-brand-ash')) }}" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        {{-- Payouts to your bank --}}
                        <div class="rounded-2xl bg-white p-4 lg:col-span-2">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold">Payouts to your bank</p>
                                <span class="text-[11px] text-brand-slate">Ledger</span>
                            </div>
                            <dl class="mt-3 divide-y divide-brand-fog">
                                @foreach($payouts as [$label, $amount, $count, $color])
                                    <div class="flex items-center justify-between py-2 text-xs">
                                        <dt class="text-brand-slate">{{ $label }}</dt>
                                        <dd class="font-semibold tabular-nums {{ $color }}">{{ $amount }} <span class="font-normal text-brand-slate">({{ $count }})</span></dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                @endif

                {{-- Recent payments --}}
                <div class="mt-3 rounded-2xl bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold">Recent payments</p>
                        <span class="text-[11px] text-brand-slate">All</span>
                    </div>
                    <ul class="mt-2 divide-y divide-brand-fog">
                        @foreach(($isFull ? $recent : array_slice($recent, 0, 4)) as [$name, $meta, $amount, $status, $date])
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-semibold">{{ $name }}</p>
                                    <p class="truncate text-[11px] text-brand-slate">{{ $meta }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $badge($status) }}">{{ ucfirst($status) }}</span>
                                    <div class="text-right">
                                        <p class="text-[13px] font-bold tabular-nums">{{ $amount }}</p>
                                        <p class="hidden text-[11px] text-brand-slate sm:block">{{ $date }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @unless($isFull)
                    {{-- Payout status strip --}}
                    <div class="mt-3 grid grid-cols-2 gap-2.5">
                        @foreach(array_slice($payouts, 0, 2) as [$label, $amount, $count, $color])
                            <div class="rounded-2xl bg-white p-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-slate">{{ $label }}</p>
                                <p class="mt-1 truncate text-sm font-bold tabular-nums {{ $color }}">{{ $amount }}</p>
                            </div>
                        @endforeach
                    </div>
                @endunless
            </div>
        </div>
    </div>
    <p class="mt-3 text-center text-xs {{ $isFull ? 'text-brand-slate' : 'text-white/70' }}">Demonstration data for illustration only — not live platform statistics.</p>
</div>
