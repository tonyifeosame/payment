{{-- Submit button with a busy state, for forms marked data-submit-once. On submit the
     form's button is disabled and the idle label swapped for a spinner and $busy, so a
     double click or a second Enter cannot post the form twice. The browser's own
     validation runs first (an invalid form never fires submit), and the button is
     re-enabled if the page comes back from the back/forward cache. --}}
<button type="submit" class="btn-obsidian w-full" data-submit>
    <span data-submit-idle>{{ $label }}</span>
    <span data-submit-busy class="hidden items-center gap-2">
        <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        {{ $busy }}
    </span>
</button>

@once
    @push('scripts')
        <script>
        (function () {
            document.querySelectorAll('form[data-submit-once]').forEach(function (form) {
                var button = form.querySelector('[data-submit]');
                if (!button) return;
                var idle = button.querySelector('[data-submit-idle]');
                var busy = button.querySelector('[data-submit-busy]');

                function setBusy(on) {
                    button.disabled = on;
                    button.setAttribute('aria-busy', on ? 'true' : 'false');
                    idle.classList.toggle('hidden', on);
                    busy.classList.toggle('hidden', !on);
                    busy.classList.toggle('inline-flex', on);
                }

                form.addEventListener('submit', function (e) {
                    if (form.dataset.submitting) { e.preventDefault(); return; }
                    form.dataset.submitting = '1';
                    setBusy(true);
                });
                window.addEventListener('pageshow', function (e) {
                    if (e.persisted) { delete form.dataset.submitting; setBusy(false); }
                });
            });
        })();
        </script>
    @endpush
@endonce
