@extends('layouts.marketing')

@section('title')
    Terms of Service — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'The terms for using FEYRA to collect school fees online: school accounts, payment pages, payments through Paystack, the service fee, payouts and receipts.')

@section('nav')
    @include('marketing.partials.slim-header', [
        'actionLabel' => 'Sign in',
        'actionHref' => route('admin.login'),
    ])
@endsection

@section('footer')
    @include('marketing.partials.slim-footer')
@endsection

@php
    // Set both before publishing (e.g. '1 November 2026'). While either is null the
    // date line is not shown: live terms must never show a placeholder date.
    $effectiveDate = null;
    $lastUpdated = null;

    $sections = [
        'about' => 'About FEYRA and These Terms',
        'who-these-terms-apply-to' => 'Who These Terms Apply To',
        'school-accounts' => 'School Accounts',
        'school-information' => 'School Information and Student Records',
        'fees-and-payment-pages' => 'School Fees and Payment Pages',
        'payments' => 'Payments Through Paystack',
        'service-fee' => 'Service Fee',
        'payouts' => 'Payouts to Schools',
        'payment-concerns' => 'Payment Concerns and Refund Requests',
        'receipts' => 'Receipts and Records',
        'acceptable-use' => 'Acceptable Use',
        'account-closure' => 'Closing a School Account',
        'intellectual-property' => 'Intellectual Property',
        'third-party-services' => 'Third-Party Services',
        'availability' => 'Availability and Changes to the Service',
        'changes' => 'Changes to These Terms',
        'contact' => 'Contact Us',
    ];
@endphp

@section('content')
<div class="container-x py-10 sm:py-12 lg:py-16">
    <div class="mx-auto max-w-3xl">
        <span class="eyebrow-violet">Terms</span>
        <h1 class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl lg:text-5xl">Terms of Service</h1>
        @if ($effectiveDate && $lastUpdated)
            <p class="mt-4 text-sm text-brand-slate">Effective {{ $effectiveDate }} · Last updated {{ $lastUpdated }}</p>
        @endif
        <p class="mt-4 text-lg text-brand-slate">These Terms explain how FEYRA works and the responsibilities of the schools and payers who use it. Please read them together with our <a href="{{ route('privacy.show') }}" class="font-medium text-brand-violet underline underline-offset-2 hover:text-brand-obsidian">Privacy Policy</a>.</p>

        <nav class="mt-8 rounded-2xl border border-brand-ash/60 bg-white p-5 sm:p-6" aria-labelledby="terms-contents">
            <h2 id="terms-contents" class="font-sans text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">Contents</h2>
            <ol class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ($sections as $id => $heading)
                    <li><a href="#{{ $id }}" class="text-brand-obsidian hover:text-brand-violet">{{ $loop->iteration }}. {{ $heading }}</a></li>
                @endforeach
            </ol>
        </nav>

        <article class="policy mt-8 rounded-4xl bg-white p-6 sm:p-10">

            <h2 id="about" class="!mt-0">1. About FEYRA and These Terms</h2>
            <p>FEYRA is an online school fee collection service for schools in Nigeria. Schools use FEYRA to set up their fees and student records, share a payment page with parents, receive payments through Paystack, send receipts and receive payouts to their bank account.</p>
            <p>In these Terms, “FEYRA”, “we”, “us” and “our” refer to the FEYRA service. These Terms apply to your use of FEYRA. How we handle personal information is described separately in our <a href="{{ route('privacy.show') }}">Privacy Policy</a>.</p>

            <h2 id="who-these-terms-apply-to">2. Who These Terms Apply To</h2>
            <ul>
                <li><strong>Schools and school administrators:</strong> schools that register for FEYRA, and the people who sign in to manage a school’s account.</li>
                <li><strong>Parents, guardians and other payers:</strong> people who pay school fees through a school’s FEYRA payment page. Payers do not need a FEYRA account.</li>
                <li><strong>Website visitors:</strong> anyone who visits the FEYRA website.</li>
            </ul>
            <p>Students do not use FEYRA or have FEYRA accounts. Their records are managed by their school (see <a href="#school-information">section 4</a>).</p>

            <h2 id="school-accounts">3. School Accounts</h2>
            <p>To use FEYRA, a school registers with its name, email address, an administrator password and a bank account for payouts. The school name is also the name used to sign in.</p>
            <p>Each school account has one administrator sign-in, which the school may share with the staff it chooses. The school is responsible for:</p>
            <ul>
                <li>providing accurate information, and keeping it up to date;</li>
                <li>keeping its administrator password safe, and deciding who has access to it;</li>
                <li>changing the password (from the school’s settings, or with a password reset link) if it believes someone else knows it;</li>
                <li>using “Remember me” only on devices the school trusts.</li>
            </ul>
            <p>If you believe your school’s account has been used without permission, contact FEYRA support at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>.</p>

            <h2 id="school-information">4. School Information and Student Records</h2>
            <p>Schools add and manage their own student records, fees, sessions, terms and classes in FEYRA. Each school is responsible for the accuracy of that information, and for having the appropriate authority to provide student and guardian information to FEYRA.</p>
            <p>How FEYRA handles this information is described in our <a href="{{ route('privacy.show') }}">Privacy Policy</a>.</p>

            <h2 id="fees-and-payment-pages">5. School Fees and Payment Pages</h2>
            <p><strong>Schools set their own school fees.</strong> FEYRA does not decide what a school charges. Each school chooses its fee amounts, which term a fee applies to, and whether a fee can be paid in multiples. A fee without an amount cannot be paid.</p>
            <p>Each school has a public payment page that it can share with parents. To pay for a student, the payer enters the student’s full name and complete admission number exactly as the school recorded them. If a student cannot be found, the payer should check the details with the school.</p>
            <p>The school is responsible for the fees it publishes. Questions about what a fee is for, or how much it should be, should be directed to the school.</p>

            <h2 id="payments">6. Payments Through Paystack</h2>
            <p>FEYRA uses <strong>Paystack</strong> as its payment provider. When a payer continues to payment, they are taken to Paystack’s hosted checkout, where card and bank payment details are entered.</p>
            <ul>
                <li>FEYRA confirms each payment with Paystack, including the amount and currency, before treating it as successful.</li>
                <li>If the amount or currency reported by Paystack does not match the payment, it is not recorded as successful and is held for review.</li>
                <li>A payment that Paystack has not yet confirmed stays pending. A payment still pending after 24 hours is checked with Paystack again, and may be recorded as failed, for example if the checkout was never completed.</li>
                <li>A failed or uncompleted payment is not recorded as a successful payment.</li>
            </ul>

            <h2 id="service-fee">7. Service Fee</h2>
            <p>When a school fee is paid through FEYRA, a FEYRA service fee is added to the school’s fee. <strong>The service fee is currently 2.5%</strong> of the fee amount.</p>
            <p>The payment page shows the fee amount, the service fee and the total before the payer continues to Paystack. The school’s payout for a payment is its fee amount; the service fee is kept by FEYRA.</p>

            <h2 id="payouts">8. Payouts to Schools</h2>
            <p>When a payment is confirmed, FEYRA records the amount due to the school and starts a transfer of that amount to the school’s verified bank account through Paystack. Each confirmed payment is paid out separately.</p>
            <ul>
                <li><strong>Verified bank account:</strong> a school’s payout account is checked with Paystack when it is added or changed. Changing it requires the administrator password, and FEYRA emails the school when it changes. The school is responsible for keeping its bank details correct.</li>
                <li><strong>Timing:</strong> how long a transfer takes to reach the school’s account depends on Paystack and the banks involved. FEYRA cannot promise when a transfer will arrive.</li>
                <li><strong>Review and intervention:</strong> some payouts need manual review or action by FEYRA before they can be sent or completed, for example if a transfer fails, if its outcome is unclear, or if the amount needs to be confirmed.</li>
                <li><strong>Tracking:</strong> schools can follow the status of each payout in their dashboard.</li>
            </ul>
            <p>If a payout needs attention or you were expecting a payout that has not arrived, contact FEYRA support with the payout reference.</p>

            <h2 id="payment-concerns">9. Payment Concerns and Refund Requests</h2>
            <p>FEYRA does not currently provide an automated refund feature.</p>
            <ul>
                <li><strong>Questions about a fee, including refund requests:</strong> contact the school the payment was made to, because the school sets its fees. You can also contact FEYRA support about a payment made through FEYRA.</li>
                <li><strong>Paying more than once:</strong> FEYRA does not currently prevent the same fee from being paid more than once. Please check your receipts before paying again. If you think you have paid twice, contact the school or FEYRA support.</li>
                <li><strong>Unconfirmed payments:</strong> if you were charged but the payment has not been confirmed, contact the school or FEYRA support with your payment reference.</li>
                <li><strong>Other concerns:</strong> for any unresolved concern about a payment made through FEYRA, contact the school or FEYRA support at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>, quoting the payment reference.</li>
            </ul>

            <h2 id="receipts">10. Receipts and Records</h2>
            <p>After a successful payment, the payer receives a receipt on screen, by email and as a PDF. Please keep your receipt and payment reference.</p>
            <p>Receipt links do not currently expire automatically, so anyone with a receipt link can open that receipt. Please treat a receipt link as you would the receipt itself.</p>
            <p>Schools can view their payments in their dashboard and export their transaction records.</p>

            <h2 id="acceptable-use">11. Acceptable Use</h2>
            <p>When using FEYRA, you must not:</p>
            <ul>
                <li>access, or try to access, another school’s account or information;</li>
                <li>use another person’s sign-in details without permission;</li>
                <li>provide information you know to be false, or pay for a student you are not entitled to pay for;</li>
                <li>try to find students’ details by guessing names or admission numbers;</li>
                <li>interfere with, overload or try to get around the security of FEYRA;</li>
                <li>upload content (such as a school logo) that you do not have the right to use;</li>
                <li>use FEYRA for any unlawful purpose.</li>
            </ul>
            <p>FEYRA limits repeated requests, such as repeated sign-in attempts, to protect the service.</p>

            <h2 id="account-closure">12. Closing a School Account</h2>
            <p>FEYRA does not currently offer self-service closure or deletion of school accounts. To ask about closing your school’s account, contact FEYRA support at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>.</p>
            <p>Requests may be subject to applicable legal, financial record-keeping, payment, security or operational requirements. For example, records of completed payments and payouts may need to be kept. See our <a href="{{ route('privacy.show') }}">Privacy Policy</a> for more about requests concerning personal information.</p>

            <h2 id="intellectual-property">13. Intellectual Property</h2>
            <p>The FEYRA service, including its software, interface, design and written materials, belongs to FEYRA or its licensors.</p>
            <p>Information a school provides to FEYRA, such as its fees, student records and logo, remains the school’s information.</p>

            <h2 id="third-party-services">14. Third-Party Services</h2>
            <p>FEYRA relies on third-party services to operate. Payments and payouts are handled through <strong>Paystack</strong>, and FEYRA also uses providers for hosting and email. Our <a href="{{ route('privacy.show') }}">Privacy Policy</a> explains which services receive information and why.</p>
            <p>Paystack’s checkout is provided by Paystack, not FEYRA. FEYRA does not control the availability of third-party services.</p>

            <h2 id="availability">15. Availability and Changes to the Service</h2>
            <p>FEYRA may sometimes be unavailable, for example during maintenance or when a service it relies on, such as Paystack, is unavailable. FEYRA does not promise uninterrupted availability or particular response times.</p>
            <p>We may change, add or remove features of FEYRA over time.</p>

            <h2 id="changes">16. Changes to These Terms</h2>
            <p>We may update these Terms from time to time. When we do, we will publish the updated version on this page and change the “Last updated” date.</p>

            <h2 id="contact">17. Contact Us</h2>
            <p>For questions about these Terms, a payment or a payout, contact FEYRA at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>.</p>
        </article>
    </div>
</div>
@endsection
