{{-- Behaviour for payment/index. The logic here is the audited Phase 1 behaviour: term/fee
     filtering, the student lookup (full name + complete admission number verified server-side,
     only student_id submitted, foreign ids fail closed server-side), client-side totals for display only,
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
    const studentSearchUrl = {!! json_encode(route(request()->routeIs('public.payment') ? 'public.payment.student-search' : 'school.payment.student-search', ['school' => $school->slug])) !!};
    const maxQuantity = {{ \App\Http\Controllers\PaymentController::MAX_QUANTITY }};

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

    // Student lookup (L8). The parent types the student's full name AND complete
    // admission number and presses "Find student"; the server answers with the
    // student only when both match the same active student of THIS school, and
    // with an identical "not found" otherwise. The form submits the verified id;
    // the server re-resolves it within the school on submit, so the admission
    // number and class shown here are display-only.
    (function () {
        const picker = document.getElementById('studentPicker');
        if (!picker) return;

        const idInput = document.getElementById('student_id');
        const nameInput = document.getElementById('student_name');
        const admissionInput = document.getElementById('student_admission_number');
        const findBtn = document.getElementById('studentFind');
        const status = document.getElementById('studentSearchStatus');
        const selectedBox = document.getElementById('studentSelected');
        const selectedName = document.getElementById('selectedStudentName');
        const selectedInitials = document.getElementById('selectedStudentInitials');
        const admissionDisplay = document.getElementById('student_admission_display');
        const classDisplay = document.getElementById('student_class_display');
        const changeBtn = document.getElementById('studentChange');
        const searchWrap = document.getElementById('studentSearchWrap');
        const form = document.getElementById('paymentForm');
        const csrfInput = form ? form.querySelector('input[name="_token"]') : null;

        const NOT_FOUND = 'We could not find a student with that name and admission number at this school. Check that both match the school’s records exactly.';
        let controller = null;

        function setStatus(text, tone) {
            status.textContent = text || '';
            status.className = 'text-sm ' + (tone === 'error' ? 'font-medium text-red-600' : 'text-brand-slate');
        }

        function updateSubmitState() {
            const ok = !!idInput.value;
            if (submitBtn) {
                submitBtn.disabled = !ok;
                submitBtn.classList.toggle('opacity-60', !ok);
                submitBtn.classList.toggle('cursor-not-allowed', !ok);
                submitBtn.title = ok ? '' : 'Find the student first';
            }
        }

        function clearSelection(keepInputs) {
            idInput.value = '';
            selectedBox.hidden = true;
            searchWrap.hidden = false;
            admissionDisplay.value = ''; classDisplay.value = '';
            if (!keepInputs) { nameInput.value = ''; admissionInput.value = ''; }
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
            if (sStudent) sStudent.textContent = student.full_name + (student.class_name ? ' (' + student.class_name + ')' : '');
            setStatus('');
            updateSubmitState();
        }

        async function find() {
            const name = nameInput.value.trim();
            const admission = admissionInput.value.trim();
            if (!name || !admission) {
                setStatus('Enter both the student’s full name and admission number.', 'error');
                (name ? admissionInput : nameInput).focus();
                return;
            }
            if (controller) controller.abort();
            controller = new AbortController();
            setStatus('Checking…');
            findBtn.disabled = true;
            try {
                const r = await fetch(studentSearchUrl, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfInput ? csrfInput.value : '',
                    },
                    body: JSON.stringify({ name: name, admission_number: admission }),
                });
                const d = await r.json().catch(() => ({}));
                if (r.status === 429) { setStatus('Too many attempts. Please wait a minute and try again.', 'error'); return; }
                if (!r.ok) { setStatus('Could not check right now. Please try again.', 'error'); return; }
                if (d.student && d.student.id) select(d.student); else setStatus(NOT_FOUND, 'error');
            } catch (e) {
                if (e.name !== 'AbortError') setStatus('Could not check right now. Please try again.', 'error');
            } finally {
                findBtn.disabled = false;
            }
        }

        findBtn.addEventListener('click', find);
        [nameInput, admissionInput].forEach(function (input) {
            // Enter looks the student up instead of submitting the payment form.
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                if (input === nameInput && !admissionInput.value.trim()) admissionInput.focus(); else find();
            });
            // Any edit un-verifies: the id must always match what was checked.
            input.addEventListener('input', function () { if (idInput.value) clearSelection(true); setStatus(''); });
        });

        changeBtn.addEventListener('click', function () {
            if (controller) controller.abort();
            clearSelection(false);
            setStatus('');
            nameInput.focus();
        });

        // The server enforces this too; this just stops a pointless round trip.
        if (form) form.addEventListener('submit', function (e) {
            if (!idInput.value) {
                e.preventDefault(); e.stopImmediatePropagation();
                searchWrap.hidden = false;
                setStatus('Please find the student you are paying for first.', 'error');
                nameInput.focus();
            }
        }, true);

        // Re-select after a failed submit. The server only provides this when the
        // name and admission number posted with that submit still verify to the id.
        let old = null;
        try { old = JSON.parse(picker.dataset.oldStudent || 'null'); } catch (e) { old = null; }
        if (old && old.id) select(old); else clearSelection(true);
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

        // A single-charge fee is always 1 unit; the fee itself says which it is (L1).
        if (!selectedAllowsQuantity()) { qtyInput.value = 1; }
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
        const typedQty = Number(qtyInput.value) || 0;
        qtyError.textContent = typedQty < 1 ? 'Quantity must be at least 1'
            : (typedQty > maxQuantity ? 'Quantity cannot be more than ' + maxQuantity : '');
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
            opt.setAttribute('data-allows-quantity', sub.allows_quantity ? '1' : '0');
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

    /** Whether the selected fee may be bought in multiples. No fee selected counts as no. */
    function selectedAllowsQuantity() {
        const selectedOption = subSelect.options[subSelect.selectedIndex];
        return !!selectedOption && selectedOption.getAttribute('data-allows-quantity') === '1';
    }

    function toggleQuantityVisibility() {
        const single = !selectedAllowsQuantity();
        // readOnly, not disabled: a disabled input is dropped from the POST and the
        // server (rightly) requires quantity. It refuses anything but 1 for a
        // single-charge fee anyway.
        if (single) {
            qtyInput.value = 1;
            qtyInput.readOnly = true;
            qtyContainer.classList.add('hidden');
        } else {
            qtyInput.readOnly = false;
            qtyContainer.classList.remove('hidden');
        }
        if (sQtyRow) sQtyRow.classList.toggle('hidden', single);
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
