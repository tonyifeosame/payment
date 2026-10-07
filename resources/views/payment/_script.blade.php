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

    // Class-level fees. With a roster, only the fees the student lookup says apply
    // to the verified student are offered (none before a student is found); a
    // school without a roster is only ever sent fees open to everyone. When exactly
    // one main (tuition) fee applies, it is selected and shown read-only. All of
    // this is presentation: the server re-resolves student -> class -> fee on submit.
    const autoFeeBox = document.getElementById('autoFee');
    const autoFeeClass = document.getElementById('autoFeeClass');
    const autoFeeName = document.getElementById('autoFeeName');
    const autoFeeAmount = document.getElementById('autoFeeAmount');
    const autoFeeOther = document.getElementById('autoFeeOther');
    const autoFeeBack = document.getElementById('autoFeeBack');
    const manualFee = document.getElementById('manualFee');
    const feeHint = document.getElementById('feeHint');
    const requiresStudent = !!document.getElementById('studentPicker');
    let allowedFeeIds = requiresStudent ? new Set() : null;
    let studentFound = false;
    let studentClassName = '';
    let preferOtherFee = false;
    let restoreOldFee = false;
    // Main school fees the verified student has already paid, as {fee_id, term_id}.
    // Paid once per term: never offered again for that term (checkout refuses it too).
    const paidFeeBox = document.getElementById('paidFee');
    const paidFeeText = document.getElementById('paidFeeText');
    let paidFees = [];
    let onFeeChange = null;

    function selectedTermId() { return termSelect && termSelect.value ? Number(termSelect.value) : null; }

    /** Has a main school fee been paid for the selected term? One per term, whichever fee. */
    function schoolFeesPaidThisTerm() {
        const termId = selectedTermId();
        return termId !== null && paidFees.some(p => Number(p.term_id) === termId);
    }

    function isPaidThisTerm(sub) {
        return !!sub.is_tuition && schoolFeesPaidThisTerm();
    }

    /** Fees payable for the selected term and, with a roster, by the verified student. */
    function applicableFees(cat, includePaid) {
        const termId = selectedTermId();
        return ((cat && cat.subcategories) ? cat.subcategories : [])
            .filter(s => s.term_id === null || s.term_id === undefined || termId === null || Number(s.term_id) === termId)
            .filter(s => allowedFeeIds === null || allowedFeeIds.has(Number(s.id)))
            .filter(s => includePaid || !isPaidThisTerm(s));
    }

    /** This student's main fees already paid for the selected term. */
    function paidFeesThisTerm() {
        const termId = selectedTermId();
        if (termId === null) return [];
        // The fee actually paid, which may be another class's (a mid-term class change).
        const all = (categories || []).flatMap(c => (c && c.subcategories) ? c.subcategories : []);
        return paidFees.filter(p => Number(p.term_id) === termId)
            .map(p => all.find(s => Number(s.id) === Number(p.fee_id)) || { name: 'School fees', price: null });
    }

    /** The class fee to select for the parent: the one main fee that applies, or null. */
    function autoFeeCandidate() {
        const tuition = [];
        (categories || []).forEach(c => applicableFees(c).forEach(s => { if (s.is_tuition) tuition.push({ cat: c, sub: s }); }));
        return tuition.length === 1 ? tuition[0] : null;
    }

    /** The fees the category/fee dropdowns offer in the current mode. */
    function offeredFees(cat) {
        const auto = autoFeeCandidate();
        if (auto && !preferOtherFee) return applicableFees(cat).filter(s => s.id === auto.sub.id);
        return applicableFees(cat).filter(s => !auto || s.id !== auto.sub.id);
    }

    function populateCategories() {
        const want = catSelect.value || (oldCategoryId ? String(oldCategoryId) : '');
        while (catSelect.firstChild) catSelect.removeChild(catSelect.firstChild);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Category --';
        catSelect.appendChild(placeholder);
        const offered = (categories || []).filter(c => offeredFees(c).length > 0);
        offered.forEach(c => {
            const o = document.createElement('option');
            o.value = c.id; o.textContent = c.name;
            catSelect.appendChild(o);
        });
        catSelect.disabled = offered.length === 0;
        if (offered.some(c => String(c.id) === want)) catSelect.value = want;
        else if (offered.length > 0) catSelect.value = String(offered[0].id);
    }

    /** Re-decide which fees are offered and whether the class fee is selected automatically. */
    function refreshFees() {
        // After a failed submit of a fee other than the class fee, keep that choice —
        // decided once the term is known, since the class fee depends on it.
        if (restoreOldFee && (!termSelect || termSelect.options.length > 0)) {
            const a = autoFeeCandidate();
            preferOtherFee = !!(a && String(oldSubcategoryId) !== String(a.sub.id));
            restoreOldFee = false;
        }
        const auto = autoFeeCandidate();
        const showAuto = !!auto && !preferOtherFee;
        if (autoFeeBox) autoFeeBox.hidden = !showAuto;
        if (manualFee) manualFee.hidden = showAuto;
        if (autoFeeBack) {
            autoFeeBack.hidden = !auto || showAuto;
            // .btn-outline sets display, which beats the hidden attribute; the utility wins.
            autoFeeBack.classList.toggle('hidden', autoFeeBack.hidden);
        }
        if (showAuto) {
            autoFeeClass.textContent = studentClassName || 'this class';
            autoFeeName.textContent = auto.sub.name;
            autoFeeAmount.textContent = toCurrency(auto.sub.price);
        }

        populateCategories();
        let hint = '';
        if (requiresStudent && !studentFound) hint = 'Find the student first to see the fees that apply to them.';
        else if (catSelect.disabled && paidFeesThisTerm().length > 0) hint = 'There is nothing else to pay for this term.';
        else if (catSelect.disabled) hint = 'There are no fees set up for this student’s class and term yet. Please contact the school.';

        const paid = paidFeesThisTerm();
        if (paidFeeBox) {
            paidFeeBox.hidden = paid.length === 0;
            if (paid.length) {
                const termOpt = termSelect ? termSelect.options[termSelect.selectedIndex] : null;
                const sessOpt = sessionSelect ? sessionSelect.options[sessionSelect.selectedIndex] : null;
                const period = termOpt ? termOpt.textContent + (sessOpt ? ', ' + sessOpt.textContent : '') : 'this term';
                paidFeeText.textContent = paid.map(s => s.price === null ? s.name : `${s.name} (${toCurrency(s.price)})`).join(', ') +
                    ` has already been paid for ${period}.` + (catSelect.disabled ? '' : ' You can still pay other fees below.');
            }
        }
        if (feeHint) { feeHint.textContent = hint; feeHint.hidden = !hint; }

        if (showAuto) catSelect.value = String(auto.cat.id);
        populateSubcategories();
        if (showAuto) subSelect.value = String(auto.sub.id);
        updateTotal();
    }

    if (autoFeeOther) autoFeeOther.addEventListener('click', function () { preferOtherFee = true; refreshFees(); catSelect.focus(); });
    if (autoFeeBack) autoFeeBack.addEventListener('click', function () { preferOtherFee = false; refreshFees(); });

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
        refreshFees();
    }

    if (sessionSelect) sessionSelect.addEventListener('change', populateTerms);
    if (termSelect) termSelect.addEventListener('change', function () {
        const sess = sessions.find(x => String(x.id) === String(sessionSelect.value));
        const o = termSelect.options[termSelect.selectedIndex];
        if (sTerm) sTerm.textContent = o && sess ? `${o.textContent}, ${sess.name}` : '—';
        refreshFees();
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
            // A verified student AND a fee to pay (none when everything due is paid).
            const ok = !!idInput.value && !!subSelect.value;
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
            allowedFeeIds = new Set(); paidFees = []; studentFound = false; studentClassName = ''; preferOtherFee = false;
            refreshFees();
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
            allowedFeeIds = new Set((student.fee_ids || []).map(Number));
            paidFees = Array.isArray(student.paid_fees) ? student.paid_fees : [];
            studentFound = true;
            studentClassName = student.class_name || '';
            preferOtherFee = false;
            refreshFees();
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

        onFeeChange = updateSubmitState;
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
        if (old && old.id) { restoreOldFee = !!oldSubcategoryId; select(old); } else clearSelection(true);
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
        // A placeholder ("-- Select … --") is not a choice: nothing is selected yet.
        const catName = selectedCatOption && catSelect.value ? selectedCatOption.textContent : '';
        const subName = selectedOption && subSelect.value ? (selectedOption.getAttribute('data-subname') || selectedOption.textContent.trim()) : '';

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
        // Nothing to choose from (no student found yet, or no fee for them): the hint
        // under the fee heading explains it, so no field error.
        if (!catSelect.disabled) {
            catError.textContent = !catSelect.value ? 'Please select a category' : '';
            if (!subSelect.disabled) subError.textContent = !subSelect.value ? 'Please select a fee type' : '';
        } else {
            catError.textContent = '';
            subError.textContent = '';
        }
        const typedQty = Number(qtyInput.value) || 0;
        qtyError.textContent = typedQty < 1 ? 'Quantity must be at least 1'
            : (typedQty > maxQuantity ? 'Quantity cannot be more than ' + maxQuantity : '');
        toggleQuantityVisibility();
        if (onFeeChange) onFeeChange();
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
        // Only fees payable in the selected term (general fees or that term's) and,
        // with a roster, by the verified student's class; see offeredFees().
        const subs = offeredFees(cat);
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
        // populateSessions -> populateTerms -> refreshFees fills the category and fee
        // dropdowns (and the class fee) for the selected term.
        populateSessions();
        if (!sessionSelect) refreshFees();
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
