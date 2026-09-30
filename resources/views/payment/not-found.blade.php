{{-- Shown when a parent returns from Paystack (or opens the callback link) and there
     is no payment to return them to: no reference, one FEYRA does not know, or a
     school that no longer exists. Deliberately identical in every case and free of any
     reference, amount or school detail, so it never reveals whether a payment exists.
     Same card as the error pages (errors/_shell), with a payment label. --}}
@extends('layouts.marketing')

@section('title')
    Payment not found — @include('marketing.partials.brand-name')
@endsection

@section('nav')
    @include('marketing.partials.slim-header', [
        'actionLabel' => 'Contact us',
        'actionHref' => route('contact.show'),
    ])
@endsection

@section('footer')
    @include('marketing.partials.slim-footer')
@endsection

@section('content')
<div class="container-x flex min-h-[60vh] items-center justify-center py-12 sm:py-16">
    <div class="w-full max-w-xl rounded-4xl border border-brand-ash/60 bg-white p-6 text-center sm:p-10">
        <span class="eyebrow-violet">Payment</span>
        <h1 class="mt-5 font-display text-3xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian sm:text-4xl">We couldn’t find this payment</h1>
        <p class="mt-4 text-lg text-brand-slate">This link doesn’t match a payment we can show here. If you were charged, please don’t pay again: your receipt is emailed once the payment is confirmed, and your school can confirm your payment status.</p>
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
            <a href="{{ route('home') }}" class="btn-obsidian">Go to the homepage</a>
            <a href="{{ route('contact.show') }}" class="btn-outline">Contact support</a>
        </div>
    </div>
</div>
@endsection
