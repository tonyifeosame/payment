{{-- One native <dialog> for confirming form submissions. Rendered once by layouts/admin.

     Opt a form in with data attributes — the request itself is unchanged (same action,
     method, CSRF token and fields), it is simply held until the admin confirms:

       <form method="POST" action="…"
             data-confirm="This changes the school's current term."
             data-confirm-title="Set Second Term as current?"
             data-confirm-label="Set as current"
             data-confirm-tone="danger">          (optional: red confirm button)

     Without JavaScript the form submits as before. Escape / the Cancel button close the
     dialog; focus returns to the button that submitted the form. --}}
<dialog id="adminConfirm" class="w-[calc(100%-2rem)] max-w-md rounded-3xl border border-brand-ash/60 bg-white p-0 text-brand-obsidian shadow-xl backdrop:bg-brand-obsidian/40" aria-labelledby="adminConfirmTitle" aria-describedby="adminConfirmMessage">
    <form method="dialog" class="p-6 sm:p-7">
        <h2 id="adminConfirmTitle" class="font-display text-xl font-bold leading-tight">Are you sure?</h2>
        <p id="adminConfirmMessage" class="mt-2 text-sm text-brand-slate"></p>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="submit" value="cancel" class="btn-outline w-full sm:w-auto">Cancel</button>
            <button type="submit" value="confirm" id="adminConfirmOk" class="btn-obsidian w-full sm:w-auto">Confirm</button>
        </div>
    </form>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('adminConfirm');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var title = document.getElementById('adminConfirmTitle');
    var message = document.getElementById('adminConfirmMessage');
    var ok = document.getElementById('adminConfirmOk');
    var pending = null;   // the form waiting for confirmation
    var opener = null;    // the element to return focus to

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm') || form === pending) return;
        e.preventDefault();
        pending = form;
        opener = document.activeElement;
        title.textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
        message.textContent = form.getAttribute('data-confirm') || '';
        ok.textContent = form.getAttribute('data-confirm-label') || 'Confirm';
        var danger = form.getAttribute('data-confirm-tone') === 'danger';
        ok.className = (danger ? 'btn bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500/30' : 'btn-obsidian') + ' w-full sm:w-auto';
        dialog.showModal();
        ok.focus();
    });

    dialog.addEventListener('close', function () {
        var form = pending;
        pending = null;
        if (dialog.returnValue === 'confirm' && form) {
            // requestSubmit re-runs validation and the submit event; `form === pending`
            // is false now, so the listener above lets it through.
            pending = form;
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
            pending = null;
        } else if (opener && typeof opener.focus === 'function') {
            opener.focus();
        }
        dialog.returnValue = '';
    });
})();
</script>
