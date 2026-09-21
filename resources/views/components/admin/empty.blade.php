{{-- Empty state for lists, tables and dashboard cards.
       <x-admin.empty title="No students yet" description="Add the first one to get started.">
           <a href="…" class="btn-obsidian">Add student</a>   (optional CTA slot)
       </x-admin.empty>
     $icon is an SVG path (24×24 viewBox); $compact tightens the padding for use inside cards. --}}
@props([
    'title',
    'description' => null,
    'icon' => 'M4 7a2 2 0 012-2h4l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V7z',
    'compact' => false,
])
<div {{ $attributes->class(['flex flex-col items-center text-center', $compact ? 'px-4 py-8' : 'px-6 py-12 sm:py-16']) }}>
    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-fog text-brand-slate" aria-hidden="true">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
    </span>
    <p class="mt-4 font-display text-lg font-bold text-brand-obsidian">{{ $title }}</p>
    @if($description)
        <p class="mt-1 max-w-sm text-sm text-brand-slate">{{ $description }}</p>
    @endif
    @if(trim($slot))
        <div class="mt-5 flex w-full flex-col gap-2 sm:w-auto sm:flex-row">{{ $slot }}</div>
    @endif
</div>
