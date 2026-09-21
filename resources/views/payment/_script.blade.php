{{-- Behaviour for payment/index. The logic here is the audited Phase 1 behaviour: term/fee
     filtering, the student combobox (name first, admission number second, only student_id
     submitted, foreign ids fail closed server-side), client-side totals for display only,
     and the submit loading state. Only presentation classes and three display hooks
     (initials, live total on the pay button, quantity row visibility) were added. --}}
<script>
    // Cache DOM elements
    const catSelect = document.getElementById('category');
    const subSelect = document.getElementById('subcategory');
    const qtyInput = document.getElementById('quantity');
    const qtyContainer = document.getElementById('quantityContainer');
    const totalField = document.getElementById('total');
    const catNameInput = document.getElementById('category_name');
    const subNameInput = document.getElementById('subcategory_name');
    const sCategory = document.getElementById('summaryCategory');
    const sSub = document.getElementById('summarySub');
    const sPrice = document.getElementById('summaryPrice');
    const sQty = document.getElementById('summaryQty');
    const sQtyRow = document.getElementById('summaryQtyRow');
    const sTotal = document.getElementById('summaryTotal');
    const sFee = document.getElementById('summaryFee');
    const submitBtn = document.getElementById('submitBtn');
    const submitTotal = document.getElementById('submitTotal');
    const spinner = document.getElementById('spinner');
    const submitText = document.getElementById('submitText');
    const catError = document.getElementById('catError');
    const subError = document.getElementById('subError');
    const qtyError = document.getElementById('qtyError');
    const unitPriceP = document.getElementById('unitPrice');
    const clientTotalInput = document.getElementById('client_total');

    // Old values from server (if any)
    const oldCategoryId = {!! json_encode(old('category_id')) !!};
    const oldSubcategoryId = {!! json_encode(old('subcategory_id')) !!};

    // Pre-sanitized structure from controller for reliability
    const categories = {!! json_encode($categoriesForJs) !!} || [];
    const sessions = {!! json_encode($sessionsForJs) !!} || [];
    const currentTermId = {!! json_encode($currentTerm?->id) !!};
    const currentSessionId = {!! json_encode($currentTerm?->academic_session_id) !!};
    const oldSessionId = {!! json_encode(old('academic_session_id')) !!};
    const oldTermId = {!! json_encode(old('academic_term_id')) !!};
    const sessionSelect = document.getElementById('academic_session_id');
    const termSelect = document.getElementById('academic_term_id');
    const sTerm = document.getElementById('summaryTerm');
    const sStudent = document.getElementById('summaryStudent');
    const studentSearchUrl = {!! json_encode(route('school.payment.student-search', ['school' => $school->slug])) !!};
    const studentSearchLimit = {{ \App\Http\Controllers\PaymentController::STUDENT_SEARCH_LIMIT }};

    function selectedTermId() { return termSelect && termSelect.value ? Number(termSelect.value) : null; }

    function populateSessions() {
        if (!sessionSelect) return;
        sessionSelect.innerHTML = '';
        sessions.forEach(sess => {
            const o = document.createElement('option');
            o.value = sess.id; o.textContent = sess.name;
            sessionSelect.appendChild(o);
        });
        const want = oldSessionId || currentSessionId;
        if (want && sessions.some(x => String(x.id) === String(want))) sessionSelect.value = String(want);
        populateTerms();
    }

    function populateTerms() {
        if (!termSelect) return;
        termSelect.innerHTML = '';
        const sess = sessions.find(x => String(x.id) === String(sessionSelect.value));
        (sess ? sess.terms : []).forEach(t => {
            const o = document.createElement('option');
            o.value = t.id; o.textContent = t.name;
            termSelect.appendChild(o);
        });
        const want = oldTermId || currentTermId;
        if (want && sess && sess.terms.some(t => String(t.id) === String(want))) termSelect.value = String(want);
        if (sTerm) { const o = termSelect.options[termSelect.selectedIndex]; sTerm.textContent = o && sess ? `${o.textContent}, ${sess.name}` : '—'; }
        populateSubcategories();
    }

    if (sessionSelect) sessionSelect.addEventListener('change', populateTerms);
    if (termSelect) termSelect.addEventListener('change', function () {
        const sess = sessions.find(x => String(x.id) === String(sessionSelect.value));
        const o = termSelect.options[termSelect.selectedIndex];
        if (sTerm) sTerm.textContent = o && sess ? `${o.textContent}, ${sess.name}` : '—';
        populateSubcategories();
    });

    // Student picker. The parent searches by name (or admission number); the
    // server answers with THIS school's matches only, and the form submits just
    // the chosen id. The server re-resolves that id within the school on submit,
    // so the admission number and class shown here are display-only.
    (function () {
        const picker = document.getElementById('studentPicker');
        if (!picker) return;

        const idInput = document.getElementById('student_id');
        const queryInput = document.getElementById('student_query');
        const list = document.getElementById('studentSuggestions');
        const status = document.getElementById('studentSearchStatus');
        const selectedBox = document.getElementById('studentSelected');
        const selectedName = document.getElementById('selectedStudentName');
        const selectedInitials = document.getElementById('selectedStudentInitials');
        const admissionDisplay = document.getElementById('student_admission_display');
        const classDisplay = document.getElementById('student_class_display');
        const changeBtn = document.getElementById('studentChange');
        const searchWrap = document.getElementById('studentSearchWrap');
        const form = document.getElementById('paymentForm');

        const MIN_CHARS = 2, DEBOUNCE_MS = 300;
        let timer = null, controller = null, results = [], activeIndex = -1, lastQuery = '';

        function setStatus(text, tone) {
            status.textContent = text || '';
            status.className = 'mt-1.5 text-sm ' + (tone === 'error' ? 'font-medium text-red-600' : 'text-brand-slate');
        }

        function closeList() {
            list.hidden = true; list.innerHTML = ''; activeIndex = -1;
            queryInput.setAttribute('aria-expanded', 'false');
            queryInput.removeAttribute('aria-activedescendant');
        }

        function updateSubmitState() {
            const ok = !!idInput.value;
            if (submitBtn) {
                submitBtn.disabled = !ok;
                submitBtn.classList.toggle('opacity-60', !ok);
                submitBtn.classList.toggle('cursor-not-allowed', !ok);
                submitBtn.title = ok ? '' : 'Select the student first';
            }
        }

        function clearSelection(keepQuery) {
            idInput.value = '';
            selectedBox.hidden = true;
            searchWrap.hidden = false;
            admissionDisplay.value = ''; classDisplay.value = '';
            if (!keepQuery) queryInput.value = '';
            if (sStudent) sStudent.textContent = '—';
            updateSubmitState();
        }

        function initials(name) {
            return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
        }

        function select(student) {
            idInput.value = String(student.id);
            selectedName.textContent = student.full_name;
            if (selectedInitials) selectedInitials.textContent = initials(student.full_name);
            admissionDisplay.value = student.admission_number_masked || '';
            classDisplay.value = student.class_name || '';
            selectedBox.hidden = false;
            searchWrap.hidden = true;           // one clear "this is who you are paying for"
            queryInput.value = student.full_name;
            lastQuery = student.full_name;
            if (sStudent) sStudent.textContent = student.full_name + (student.class_name ? ' (' + student.class_name + ')' : '');
            closeList(); setStatus('');
            updateSubmitState();
        }

        function render() {
            list.innerHTML = '';
            if (results.length === 0) { closeList(); return; }
            results.forEach((st, i) => {
                const li = document.createElement('li');
                li.id = 'studentOption' + i; li.setAttribute('role', 'option'); li.dataset.index = String(i);
                li.className = 'min-h-[48px] cursor-pointer select-none px-4 py-3 hover:bg-brand-violet/[0.06]';
                const name = document.createElement('div'); name.className = 'font-semibold text-brand-obsidian'; name.textContent = st.full_name;
                const meta = document.createElement('div'); meta.className = 'text-sm text-brand-slate';
                meta.textContent = (st.class_name || '') + (st.admission_number_masked ? ' · ' + st.admission_number_masked : '');
                li.appendChild(name); li.appendChild(meta);
                // mousedown/touch so the choice lands before the input blurs and the list closes
                li.addEventListener('mousedown', function (e) { e.preventDefault(); select(st); });
                li.addEventListener('touchend', function (e) { e.preventDefault(); select(st); });
                list.appendChild(li);
            });
            list.hidden = false;
            queryInput.setAttribute('aria-expanded', 'true');
            setActive(-1);
        }

        function setActive(i) {
            const items = list.querySelectorAll('[role="option"]');
            items.forEach(el => { el.classList.remove('bg-brand-violet/10'); el.removeAttribute('aria-selected'); });
            activeIndex = i;
            if (i >= 0 && items[i]) {
                items[i].classList.add('bg-brand-violet/10'); items[i].setAttribute('aria-selected', 'true');
                queryInput.setAttribute('aria-activedescendant', items[i].id);
                items[i].scrollIntoView({ block: 'nearest' });
            } else {
                queryInput.removeAttribute('aria-activedescendant');
            }
        }

        async function search(q) {
            if (controller) controller.abort();
            controller = new AbortController();
            setStatus('Searching…');
            try {
                const r = await fetch(studentSearchUrl + '?q=' + encodeURIComponent(q), { signal: controller.signal, headers: { 'Accept': 'application/json' } });
                const d = await r.json().catch(() => ({}));
                if (q !== queryInput.value.trim()) return; // a newer keystroke owns the UI now
                if (r.status === 429) { results = []; render(); setStatus('Too many searches. Please wait a moment and try again.', 'error'); return; }
                if (!r.ok) { results = []; render(); setStatus('Could not search right now. Please try again.', 'error'); return; }
                results = Array.isArray(d.students) ? d.students : [];
                render();
                if (results.length === 0) {
                    setStatus('No student matching “' + q + '” was found at this school. Check the spelling, or try the admission number.', 'error');
                } else if (results.length >= studentSearchLimit) {
                    setStatus('Showing the first ' + studentSearchLimit + ' matches — keep typing to narrow it down.');
                } else {
                    setStatus(results.length + (results.length === 1 ? ' match' : ' matches') + ' — pick the right student below.');
                }
            } catch (e) {
                if (e.name !== 'AbortError') { results = []; render(); setStatus('Could not search right now. Please try again.', 'error'); }
            }
        }

        queryInput.addEventListener('input', function () {
            const q = queryInput.value.trim();
            // Any edit after a selection un-selects: the id must always match what is shown.
            if (idInput.value && q !== lastQuery) clearSelection(true);
            clearTimeout(timer);
            if (q.length < MIN_CHARS) { if (controller) controller.abort(); results = []; render(); setStatus(q.length ? 'Keep typing…' : ''); return; }
            timer = setTimeout(() => search(q), DEBOUNCE_MS);
        });

        queryInput.addEventListener('keydown', function (e) {
            if (list.hidden) { if (e.key === 'Enter') e.preventDefault(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(activeIndex + 1, results.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(activeIndex - 1, -1)); }
            else if (e.key === 'Enter') { e.preventDefault(); if (activeIndex >= 0) select(results[activeIndex]); else if (results.length === 1) select(results[0]); }
            else if (e.key === 'Escape') { closeList(); }
        });
        queryInput.addEventListener('blur', function () { setTimeout(closeList, 150); });
        queryInput.addEventListener('focus', function () { if (results.length && !idInput.value) render(); });

        changeBtn.addEventListener('click', function () {
            clearTimeout(timer);
            if (controller) controller.abort();
            clearSelection(false);
            results = []; lastQuery = '';
            closeList(); setStatus('');
            queryInput.focus();
        });

        // The server enforces this too; this just stops a pointless round trip.
        if (form) form.addEventListener('submit', function (e) {
            if (!idInput.value) {
                e.preventDefault(); e.stopImmediatePropagation();
                searchWrap.hidden = false;
                setStatus('Please search for and select the student you are paying for.', 'error');
                queryInput.focus();
            }
        }, true);

        // Re-select after a failed submit (the server only echoes ids that belong to this school).
        let old = null;
        try { old = JSON.parse(picker.dataset.oldStudent || 'null'); } catch (e) { old = null; }
        if (old && old.id) select(old); else clearSelection(false);
    })();

    // Listeners
    catSelect.addEventListener('change', function () {
        try {
            populateSubcategories();
            updateTotal();
        } catch (e) { console.error('Error on category change:', e); }
    });
    subSelect.addEventListener('change', function () {
        try { updateTotal(); } catch (e) { console.error('Error on subcategory change:', e); }
    });
    document.addEventListener('input', function (event) {
        if (event.target === qtyInput) updateTotal();
    });

    // Update total and summary
    function updateTotal() {
        const selectedCatOption = catSelect.options[catSelect.selectedIndex];
        const selectedOption = subSelect.options[subSelect.selectedIndex];
        const price = Number(selectedOption?.getAttribute('data-price')) || 0;

        // Enforce single quantity for School Fees
        const catNameText = (selectedCatOption ? selectedCatOption.textContent : '').toLowerCase();
        const isSchoolFees = catNameText.includes('school fee');
        if (isSchoolFees) { qtyInput.value = 1; }
        const qty = Math.max(1, Number(qtyInput.value) || 1);
        const base = price * qty;
        const markupPercent = Number({{ isset($markupPercent) ? $markupPercent : 0 }});
        const fee = Math.round((base * (markupPercent/100)) * 100) / 100;
        const total = base + fee;
        const catName = selectedCatOption ? selectedCatOption.textContent : '';
        const subName = selectedOption?.getAttribute('data-subname') || (selectedOption ? selectedOption.textContent.trim() : '');

        // Hidden inputs
        catNameInput.value = catName;
        subNameInput.value = subName;

        // Summary UI
        sCategory.textContent = catName || '—';
        sSub.textContent = subName || '—';
        sPrice.textContent = toCurrency(price);
        sQty.textContent = String(qty);
        sTotal.textContent = toCurrency(total);
        sFee.textContent = toCurrency(fee);
        if (submitTotal) submitTotal.textContent = toCurrency(total);

        totalField.value = total ? total.toLocaleString() : '';
        unitPriceP.textContent = 'Unit Price: ' + toCurrency(price);
        clientTotalInput.value = String(total);

        // Basic inline validation
        catError.textContent = !catSelect.value ? 'Please select a category' : '';
        subError.textContent = !subSelect.value ? 'Please select a fee type' : '';
        qtyError.textContent = (Number(qtyInput.value) || 0) < 1 ? 'Quantity must be at least 1' : '';
        toggleQuantityVisibility();
    }

    // Helpers
    function toCurrency(n) { return '₦' + (Number(n) || 0).toLocaleString(); }

    function populateSubcategories() {
        // Clear current options
        while (subSelect.firstChild) subSelect.removeChild(subSelect.firstChild);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Fee Type --';
        subSelect.appendChild(placeholder);

        const catId = Number(catSelect.value || 0);
        if (!catId) { subSelect.disabled = true; unitPriceP.textContent = 'Unit Price: ₦0'; return; }

        const cat = (categories || []).find(c => Number(c.id) === catId);
        // Only fees payable in the selected term: general fees (no term) or that term's fees.
        const termId = selectedTermId();
        const subs = ((cat && cat.subcategories) ? cat.subcategories : []).filter(s => s.term_id === null || s.term_id === undefined || termId === null || Number(s.term_id) === termId);
        if (subs.length === 0) { subSelect.disabled = true; unitPriceP.textContent = 'Unit Price: ₦0'; subError.textContent = 'No fees in this category for the selected term.'; return; }

        subs.forEach(sub => {
            const opt = document.createElement('option');
            opt.value = sub.id;
            opt.textContent = `${sub.name} (₦${Number(sub.price || 0).toLocaleString()})`;
            opt.setAttribute('data-price', Number(sub.price || 0));
            opt.setAttribute('data-subname', sub.name);
            subSelect.appendChild(opt);
        });
        subSelect.disabled = false;

        if (oldSubcategoryId && subs.some(s => String(s.id) === String(oldSubcategoryId))) {
            subSelect.value = String(oldSubcategoryId);
        } else if (subs.length > 0) {
            subSelect.value = String(subs[0].id);
        }

        updateTotal();
    }

    function toggleQuantityVisibility() {
        const selectedCatOption = catSelect.options[catSelect.selectedIndex];
        const catNameText = (selectedCatOption ? selectedCatOption.textContent : '').toLowerCase();
        const isSchoolFees = catNameText.includes('school fee');
        // readOnly, not disabled: a disabled input is dropped from the POST and the
        // server (rightly) requires quantity. It forces 1 for school fees anyway.
        if (isSchoolFees) {
            qtyInput.value = 1;
            qtyInput.readOnly = true;
            qtyContainer.classList.add('hidden');
        } else {
            qtyInput.readOnly = false;
            qtyContainer.classList.remove('hidden');
        }
        if (sQtyRow) sQtyRow.classList.toggle('hidden', isSchoolFees);
    }

    // Initialize on load
    try {
        if (oldCategoryId && (categories || []).some(c => String(c.id) === String(oldCategoryId))) {
            catSelect.value = String(oldCategoryId);
        } else if ((categories || []).length > 0) {
            catSelect.value = String(categories[0].id);
        }
        populateSessions();
        populateSubcategories();
        updateTotal();
    } catch (e) {
        console.error('Initialization error:', e);
    }

    // Submit loading state: disable button, show spinner, change text
    (function(){
        const form = document.getElementById('paymentForm');
        if (!form || !submitBtn || !spinner || !submitText) return;
        form.addEventListener('submit', function(){
            // Prevent multiple clicks
            submitBtn.setAttribute('disabled', 'disabled');
            submitBtn.classList.add('opacity-70', 'cursor-not-allowed');
            spinner.classList.remove('hidden');
            submitText.textContent = 'Redirecting to Paystack…';
            if (submitTotal) submitTotal.classList.add('hidden');
            submitBtn.setAttribute('aria-busy', 'true');
        });
    })();
</script>
