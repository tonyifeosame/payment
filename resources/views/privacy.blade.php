@extends('layouts.marketing')

@section('title')
    Privacy Policy — @include('marketing.partials.brand-name')
@endsection
@section('meta_description', 'How FEYRA collects, uses, shares and protects information about schools, parents and other payers, students and website visitors.')

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
    // date line is not shown: a live policy must never show a placeholder date.
    $effectiveDate = null;
    $lastUpdated = null;

    $sections = [
        'who-we-are' => 'Who We Are',
        'who-this-covers' => 'Who This Policy Covers',
        'information-we-collect' => 'Information We Collect',
        'how-we-use-information' => 'How We Use Information',
        'payments' => 'Payments and Paystack',
        'sharing' => 'How We Share Information',
        'cookies' => 'Cookies and Similar Technologies',
        'security' => 'Data Security',
        'retention' => 'Data Retention',
        'your-requests' => 'Data Deletion and Your Requests',
        'students' => 'Student and Children’s Information',
        'third-party-services' => 'International and Third-Party Services',
        'changes' => 'Changes to This Privacy Policy',
        'contact' => 'Contact Us',
    ];
@endphp

@section('content')
<div class="container-x py-10 sm:py-12 lg:py-16">
    <div class="mx-auto max-w-3xl">
        <span class="eyebrow-violet">Privacy</span>
        <h1 class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl lg:text-5xl">Privacy Policy</h1>
        @if ($effectiveDate && $lastUpdated)
            <p class="mt-4 text-sm text-brand-slate">Effective {{ $effectiveDate }} · Last updated {{ $lastUpdated }}</p>
        @endif
        <p class="mt-4 text-lg text-brand-slate">This Privacy Policy explains what information FEYRA collects, how we use it, who we share it with, and the choices available to you. It describes how the FEYRA service currently works.</p>

        <nav class="mt-8 rounded-2xl border border-brand-ash/60 bg-white p-5 sm:p-6" aria-labelledby="policy-contents">
            <h2 id="policy-contents" class="font-sans text-xs font-semibold uppercase tracking-[0.12em] text-brand-slate">Contents</h2>
            <ol class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                @foreach ($sections as $id => $heading)
                    <li><a href="#{{ $id }}" class="text-brand-obsidian hover:text-brand-violet">{{ $loop->iteration }}. {{ $heading }}</a></li>
                @endforeach
            </ol>
        </nav>

        <article class="policy mt-8 rounded-4xl bg-white p-6 sm:p-10">

            <h2 id="who-we-are" class="!mt-0">1. Who We Are</h2>
            <p>FEYRA is an online school fee collection service for schools in Nigeria. Schools use FEYRA to set up their fees and student records, give parents a payment page for school fees, send receipts, track payments and receive payouts to their bank account.</p>
            <p>In this policy, “FEYRA”, “we”, “us” and “our” refer to the FEYRA service.</p>
            <p>You can contact us about this policy or your information at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>.</p>

            <h2 id="who-this-covers">2. Who This Policy Covers</h2>
            <p>This policy covers information about:</p>
            <ul>
                <li><strong>Schools and school administrators:</strong> schools that register for FEYRA, and the people who sign in to manage a school’s account.</li>
                <li><strong>Parents, guardians and other payers:</strong> people who pay school fees through a school’s FEYRA payment page.</li>
                <li><strong>Students:</strong> students whose records a school adds to FEYRA. Students do not create accounts or use FEYRA themselves.</li>
                <li><strong>Website visitors:</strong> anyone who visits the FEYRA website or contacts us.</li>
            </ul>
            <p>Schools provide and manage the student and guardian information in their FEYRA account. FEYRA processes that information to provide its payment and school-management services to the school.</p>

            <h2 id="information-we-collect">3. Information We Collect</h2>

            <h3>Information schools provide when registering and managing their account</h3>
            <ul>
                <li>School name (also used as the sign-in name), email address, address and phone number.</li>
                <li>Payout bank details: bank name, bank code and account number. When a school adds or changes its bank account, we check it with Paystack and store the account name that verification returns.</li>
                <li>An administrator password. We store it only in a one-way hashed form (see <a href="#security">Data Security</a>).</li>
                <li>Optional branding: a school logo, and a short footer message shown on receipts.</li>
                <li>Fee, session, term and class set-up information.</li>
            </ul>

            <h3>Student and guardian information provided by schools</h3>
            <ul>
                <li>Student full name, admission number, class, academic session and status (for example active, graduated or left).</li>
                <li>The school’s records of students moving between classes and sessions.</li>
                <li>Optional guardian details: guardian name, phone number and email address.</li>
            </ul>

            <h3>Information from parents and other payers</h3>
            <ul>
                <li><strong>Email address:</strong> required, so that we can process the payment and send a receipt.</li>
                <li><strong>Name:</strong> optional.</li>
                <li><strong>Student details:</strong> to pay for a student, the payer enters the student’s full name and complete admission number, which we use to find the matching student at that school.</li>
            </ul>

            <h3>Payment and transaction information</h3>
            <ul>
                <li>Payment reference numbers (ours and Paystack’s), amount, fee details, service fee, status and payment time.</li>
                <li>The payment method reported by Paystack, and, if a payment fails, Paystack’s short description of why.</li>
                <li>Copies of the student’s name, admission number and class, and the fee, session and term, stored with the transaction so the record stays accurate over time.</li>
                <li>The payer’s email address and name, if given.</li>
            </ul>
            <p>We do <strong>not</strong> receive or store card details. See <a href="#payments">Payments and Paystack</a>.</p>

            <h3>Payout information</h3>
            <ul>
                <li>Payout amounts, references, status, and the transfer details Paystack returns to us.</li>
                <li>Records of any manual action taken to resolve a payout.</li>
            </ul>

            <h3>Messages you send us</h3>
            <ul>
                <li>When you use our contact form, we receive your name, email address, subject and message. These are sent to us by email.</li>
                <li>If you choose to contact us by WhatsApp or email directly, we receive whatever you send through that channel.</li>
            </ul>

            <h3>Technical and security information</h3>
            <ul>
                <li><strong>Session records:</strong> when you visit the website, we keep a session record that includes your IP address, your browser’s user-agent (the information your browser sends that identifies its type and version) and the session’s recent activity. This happens on public pages as well as signed-in pages.</li>
                <li><strong>Rate limiting:</strong> to limit repeated attempts (for example failed sign-ins), we use IP addresses and, for sign-in and password-reset attempts, the school name or email entered. These are stored only as one-way hashes, in short-lived counters.</li>
                <li><strong>Remember Me:</strong> if a school administrator chooses “Remember me”, we store a hashed security token for that browser (see <a href="#cookies">Cookies</a>).</li>
                <li><strong>Audit trail:</strong> when school information is changed through an administrator account, we keep a record of what changed. It identifies the action by role and a hashed session identifier, not by individual name. Passwords, bank account numbers and security tokens are removed from these records.</li>
                <li><strong>Operational records:</strong> our systems keep logs and records of background tasks, such as sending receipts, to keep the service running and investigate problems. These can include payment references, internal record numbers and, in some security events, IP addresses. Records of background tasks that fail can include the information those tasks were handling, such as a payer’s email address.</li>
            </ul>

            <h2 id="how-we-use-information">4. How We Use Information</h2>
            <p>We use information to:</p>
            <ul>
                <li><strong>Provide school accounts:</strong> registering schools, signing administrators in, and running the school dashboard, settings and public payment page.</li>
                <li><strong>Manage student records:</strong> so a school can maintain its records and parents can pay for the right student.</li>
                <li><strong>Process school-fee payments:</strong> starting a payment with Paystack, confirming the result directly with Paystack, and recording the transaction.</li>
                <li><strong>Provide receipts:</strong> emailing a receipt to the payer, and making the receipt available online and as a PDF.</li>
                <li><strong>Pay schools:</strong> transferring the amounts due to a school’s verified bank account through Paystack, and keeping track of each transfer.</li>
                <li><strong>Keep records:</strong> giving schools a record of their payments, including a downloadable export, and keeping our own financial and transaction records.</li>
                <li><strong>Keep the service secure:</strong> signing people in and keeping them signed in, protecting forms, limiting repeated attempts, keeping each school’s information separate, and recording changes to school information.</li>
                <li><strong>Send service emails:</strong> receipts, account links when a school registers, password reset links, a notice to the school when its payout bank account changes, and operational alerts to FEYRA’s operator.</li>
                <li><strong>Provide support:</strong> reading and replying to messages sent through the contact form.</li>
            </ul>
            <p>We do <strong>not</strong> sell personal information.</p>
            <p>FEYRA currently does not use personal information for advertising, profiling, newsletters or marketing emails.</p>

            <h2 id="payments">5. Payments and Paystack</h2>
            <p>FEYRA uses <strong>Paystack</strong> to process school-fee payments.</p>
            <p>When a payer chooses to pay, we send Paystack:</p>
            <ul>
                <li>the payer’s email address;</li>
                <li>the amount;</li>
                <li>our payment reference;</li>
                <li>details that identify the payment: our transaction number, the quantity, the school’s ID, name and web address, the student’s admission number, and the term.</li>
            </ul>
            <p>The payer is then taken to Paystack’s hosted payment page to complete the payment. <strong>Card and bank payment details are entered on Paystack’s payment page. FEYRA does not receive or store card details.</strong></p>
            <p>After the payment, we confirm its status, amount and currency directly with Paystack before recording it as paid. Paystack also sends us notifications about payment and payout status.</p>
            <p>We also use Paystack to:</p>
            <ul>
                <li><strong>verify bank accounts:</strong> we send the account number and bank code, and Paystack returns the account holder’s name;</li>
                <li><strong>pay schools:</strong> we send the school’s bank details and each transfer’s amount and reference, so Paystack can set up the payout and make the transfer.</li>
            </ul>
            <p>Paystack handles the information it receives under its own terms and privacy policy.</p>

            <h3>Receipts</h3>
            <p>A receipt can be viewed through:</p>
            <ul>
                <li>a secure link we generate (for example, in the receipt email or the download button);</li>
                <li>the browser session that completed the payment;</li>
                <li>the school’s own administrator account.</li>
            </ul>
            <p>Receipt links currently do <strong>not</strong> expire automatically. Anyone who has a receipt link can open that receipt, so please treat a receipt link as you would the receipt itself.</p>

            <h2 id="sharing">6. How We Share Information</h2>
            <p>We share information only as described in this policy:</p>
            <ul>
                <li><strong>With the school.</strong> When you pay a school through FEYRA, that school can see the payment in its dashboard, including the student details, the payer’s name (if given) and email address. The school can also download these records.</li>
                <li><strong>With Paystack</strong>, for payments, payment verification, bank account verification and payouts (see <a href="#payments">section 5</a>).</li>
                <li><strong>With our email service provider</strong>, which delivers the emails FEYRA sends: receipts, password resets, school notifications, contact-form messages and operational emails. The provider receives the contents and recipients of those emails.</li>
                <li><strong>With Render</strong>, which hosts FEYRA’s application, database, background processing and logs.</li>
            </ul>
            <p>FEYRA serves its own fonts and compiled styling files from its own website, so loading our pages does not connect your browser to a separate font or styling provider.</p>
            <p>If you choose to use the WhatsApp link on our contact page, WhatsApp handles that conversation under its own terms and privacy policy.</p>
            <p>FEYRA does not use analytics services, advertising networks or tracking pixels.</p>

            <h2 id="cookies">7. Cookies and Similar Technologies</h2>
            <p>FEYRA uses only the following cookies, all needed for the service to work and stay secure:</p>
            <dl class="space-y-4">
                <div class="rounded-2xl border border-brand-ash/60 p-4 sm:p-5">
                    <dt class="font-semibold text-brand-obsidian">Session cookie</dt>
                    <dd class="mt-1">Keeps your visit working across pages, including keeping administrators signed in. Set on all pages, including public ones. Expires after 120 minutes of inactivity.</dd>
                </div>
                <div class="rounded-2xl border border-brand-ash/60 p-4 sm:p-5">
                    <dt class="font-semibold text-brand-obsidian">XSRF-TOKEN</dt>
                    <dd class="mt-1">A security token that protects forms against cross-site request forgery (another site submitting forms in your name). Lasts as long as the session.</dd>
                </div>
                <div class="rounded-2xl border border-brand-ash/60 p-4 sm:p-5">
                    <dt class="font-semibold text-brand-obsidian">school_remember</dt>
                    <dd class="mt-1">Set only when a school administrator ticks “Remember me” at sign-in, so they stay signed in on that browser. Lasts up to 30 days from sign-in, after which they must sign in again. It is replaced each time it is used, and cancelled on sign-out or when the password changes.</dd>
                </div>
            </dl>
            <p>FEYRA does <strong>not</strong> use analytics, advertising or preference cookies, and does not store information in your browser’s local storage.</p>

            <h2 id="security">8. Data Security</h2>
            <p>We use technical measures designed to protect the information we hold, including:</p>
            <ul>
                <li><strong>Passwords:</strong> administrator passwords are stored only in a one-way hashed form. When a new password is chosen, it is checked against passwords known from public data breaches using the Have I Been Pwned service: only the first five characters of a one-way hash of the password are sent, never the password itself.</li>
                <li><strong>Password resets:</strong> only a hashed copy of each reset token is stored, and reset links expire after 60 minutes.</li>
                <li><strong>Remember Me:</strong> only a hashed version of the secret is stored; the token is replaced each time it is used; and it is cancelled when the administrator signs out or the password changes.</li>
                <li><strong>Secure connections:</strong> the live service only works over encrypted connections (HTTPS).</li>
                <li><strong>Cookies:</strong> encrypted, and sent only over HTTPS on the live service. The session and Remember Me cookies cannot be read by scripts on the page.</li>
                <li><strong>Card details:</strong> FEYRA does not store them.</li>
                <li><strong>Access controls:</strong> each school’s administrators can access only their own school’s information, and receipts are available only through the methods described in <a href="#payments">section 5</a>.</li>
                <li><strong>Rate limits:</strong> sign-in, password reset, payment, bank lookup, student lookup, registration and contact requests are limited to reduce abuse.</li>
                <li><strong>Audit trail:</strong> changes to school information are recorded, with passwords, bank account numbers and security tokens removed from those records.</li>
            </ul>
            <p>No method of transmitting or storing information is completely secure, and we cannot guarantee absolute security.</p>

            <h2 id="retention">9. Data Retention</h2>
            <p>We retain information for as long as reasonably necessary to provide the service, maintain financial and transaction records, meet applicable legal or operational requirements, resolve disputes, and enforce our agreements.</p>
            <p>Some technical items expire on their own, but these are not how long we keep information in general:</p>
            <ul>
                <li>sessions expire after 120 minutes of inactivity;</li>
                <li>password reset links expire after 60 minutes;</li>
                <li>Remember Me sign-ins expire after at most 30 days.</li>
            </ul>

            <h2 id="your-requests">10. Data Deletion and Your Requests</h2>
            <p><strong>School administrators</strong> can update their school’s profile and student records from the FEYRA dashboard.</p>
            <p>FEYRA does not currently offer self-service deletion of school accounts, student records or transactions.</p>
            <p>To ask about the personal information we hold about you, or to request that it be corrected or deleted, email <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>. Please tell us who you are and which school or payment your request relates to. We may need to verify your identity before acting on a request.</p>
            <p>Requests may be subject to applicable legal, financial record-keeping, security, dispute or operational requirements. For example, we may need to keep records of completed payments and payouts.</p>
            <p>If your request concerns student or guardian information that a school provided, we may refer you to that school or involve it, because the school manages that information.</p>

            <h2 id="students">11. Student and Children’s Information</h2>
            <p>Student information in FEYRA may relate to children. Schools provide and manage this information, and each school is responsible for having the appropriate authority and permissions to provide student and guardian information to FEYRA.</p>
            <p>Students do not create FEYRA accounts. FEYRA uses student information only to provide its services to the school: maintaining the school’s records, helping payers find the right student, and recording payments and receipts.</p>
            <p>On a school’s public payment page, a student is found only when the payer enters that student’s full name and complete admission number, as the school recorded them. The page then shows only the student’s name, class and a partly hidden admission number.</p>

            <h2 id="third-party-services">12. International and Third-Party Services</h2>
            <p>FEYRA relies on the third-party services described in <a href="#sharing">section 6</a>. Some of them may process information in countries other than the one where you live, including countries outside Nigeria. This includes our hosting provider, Render, and our email service provider.</p>
            <p>Each third-party service handles information under its own terms and privacy policy.</p>

            <h2 id="changes">13. Changes to This Privacy Policy</h2>
            <p>We may update this Privacy Policy as FEYRA changes. When we do, we will post the updated version on this page and change the “Last updated” date.</p>

            <h2 id="contact">14. Contact Us</h2>
            <p>For questions about this Privacy Policy or requests about your information, contact FEYRA at <a href="mailto:ifeosamenkem@gmail.com">ifeosamenkem@gmail.com</a>.</p>
        </article>
    </div>
</div>
@endsection
