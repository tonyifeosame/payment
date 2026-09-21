{{-- Table foundation for admin lists.

       <x-admin.table :columns="[['Name'], ['Class'], ['Amount', 'right'], ['', 'actions']]" stacked>
           <tr>
               <td class="td font-semibold" data-label="Name">…</td>
               <td class="td text-right" data-label="Amount">₦…</td>
               <td class="td td-actions" data-label="">…</td>
           </tr>
           <x-slot:empty><x-admin.empty title="Nothing here yet" compact /></x-slot:empty>
           <x-slot:footer>{{ $rows->links() }}</x-slot:footer>
       </x-admin.table>

     $columns: list of [label, align?, class?] where align is 'left' (default), 'right' or
     'actions' and class is extra classes for the <th> (e.g. 'hidden lg:table-cell' to
     drop a low-priority column on narrow desktops; give its <td>s the same classes).
     $stacked: below md each row renders as a card, with td[data-label] shown as the label.
     $empty: rendered instead of the body when the slot is blank.
     Below md the wrapper scrolls horizontally when not stacked; the shadow on the right
     edge hints that more columns exist. --}}
@props([
    'columns' => [],
    'stacked' => false,
    'empty' => null,
    'footer' => null,
    'caption' => null,
])
@php $hasRows = trim($slot) !== ''; @endphp
<div {{ $attributes->class(['card overflow-hidden']) }}>
    {{-- relative: absolutely positioned helpers inside cells (sr-only text) clip to this
         scroll container instead of escaping it and widening the page. --}}
    <div class="relative {{ $stacked ? 'md:overflow-x-auto' : 'overflow-x-auto' }}">
        <table class="admin-table min-w-full {{ $stacked ? 'admin-table--stacked' : '' }}">
            @if($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
            @if($columns)
                <thead class="bg-brand-fog {{ $stacked ? 'hidden md:table-header-group' : '' }}">
                    <tr>
                        @foreach($columns as $col)
                            @php [$label, $align, $extra] = [$col[0] ?? '', $col[1] ?? 'left', $col[2] ?? '']; @endphp
                            <th scope="col" class="th {{ $align === 'right' ? 'text-right' : '' }} {{ $align === 'actions' ? 'text-right' : '' }} {{ $extra }}">
                                {{ $label !== '' ? $label : '' }}@if($align === 'actions' && $label === '')<span class="sr-only">Actions</span>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            @if($hasRows)
                <tbody class="divide-y divide-brand-fog bg-white">{{ $slot }}</tbody>
            @endif
        </table>
    </div>
    @if(! $hasRows)
        {{ $empty ?? '' }}
    @endif
    @if($footer)
        <div class="border-t border-brand-ash/60 px-4 py-3">{{ $footer }}</div>
    @endif
</div>
@once
    @push('head')
    <style type="text/tailwindcss">
        @layer components {
            .admin-table tbody tr { @apply hover:bg-brand-fog/60 transition-colors; }
            .admin-table .td-actions { @apply text-right whitespace-nowrap; }
            .admin-table .td-actions > * { @apply align-middle; }
            /* Stacked rows below md: every cell becomes a label/value line. */
            @media (max-width: 767px) {
                .admin-table--stacked, .admin-table--stacked tbody, .admin-table--stacked tr, .admin-table--stacked td { @apply block w-full; }
                .admin-table--stacked tr { @apply px-4 py-3; }
                .admin-table--stacked td { @apply flex items-start justify-between gap-4 px-0 py-1.5 text-right; }
                .admin-table--stacked td[data-label]::before { content: attr(data-label); @apply shrink-0 text-left text-xs font-semibold uppercase tracking-[0.08em] text-brand-slate; }
                .admin-table--stacked td[data-label=""]::before { @apply hidden; }
                .admin-table--stacked .td-actions { @apply justify-end pt-3; }
            }
        }
    </style>
    @endpush
@endonce
