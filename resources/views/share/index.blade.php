@extends('layouts.admin')

@section('title', 'Share payment link')
@section('heading', 'Share your payment page')
@section('subheading', 'Tell parents: "Use this link to pay your school fees." They will need the student\'s admission number.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
    <div class="card p-6 space-y-5">
        <div>
            <label for="paymentUrl" class="label">Your payment link</label>
            <div class="flex gap-2">
                <input id="paymentUrl" class="input font-mono text-sm" value="{{ $paymentUrl }}" readonly onclick="this.select()">
                <button type="button" class="btn-primary whitespace-nowrap" id="copyBtn">Copy</button>
            </div>
            <p id="copyStatus" class="text-xs text-green-700 mt-1 h-4"></p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn bg-[#25D366] hover:bg-[#1fb857] text-white">Share on WhatsApp</a>
            <a href="{{ $paymentUrl }}" target="_blank" rel="noopener" class="btn-secondary">Open payment page ↗</a>
            <a href="{{ $qrUrl }}" download="{{ $school->slug }}-payment-qr.svg" class="btn-secondary">Download QR (SVG)</a>
            <button type="button" class="btn-secondary" onclick="window.print()">Print QR poster</button>
        </div>

        <div class="text-sm text-slate-600 space-y-2 border-t border-slate-100 pt-4">
            <p class="font-bold text-slate-800">Suggested message to parents</p>
            <p class="bg-slate-50 rounded-lg p-3 font-mono text-xs whitespace-pre-line">Pay your {{ $school->name }} school fees online here: {{ $paymentUrl }}

You will need the student's admission number.</p>
        </div>
    </div>

    <div class="card p-6 text-center" id="qrPoster">
        @if($school->logoUrl())
            <img src="{{ $school->logoUrl() }}" alt="" class="w-16 h-16 mx-auto mb-3 object-contain">
        @endif
        <h2 class="text-2xl font-black text-slate-900">{{ $school->name }}</h2>
        <p class="text-slate-600 font-medium mb-4">Scan to pay school fees</p>
        <div class="inline-block bg-white p-3 rounded-xl border border-slate-200">{!! $qrSvg !!}</div>
        <p class="font-mono text-xs text-slate-500 mt-4 break-all">{{ $paymentUrl }}</p>
        @if($school->phone || $school->email)
            <p class="text-xs text-slate-500 mt-2">{{ $school->phone }} {{ $school->phone && $school->email ? '·' : '' }} {{ $school->email }}</p>
        @endif
    </div>
</div>
@endsection

@push('head')
<style>
    @media print {
        body * { visibility: hidden; }
        #qrPoster, #qrPoster * { visibility: visible; }
        #qrPoster { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; border: 0; }
    }
</style>
@endpush

@push('scripts')
<script>
document.getElementById('copyBtn').addEventListener('click', async function () {
    const input = document.getElementById('paymentUrl');
    const status = document.getElementById('copyStatus');
    try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'Copied to clipboard.';
    } catch (e) {
        input.select(); document.execCommand('copy');
        status.textContent = 'Copied.';
    }
    setTimeout(() => status.textContent = '', 2500);
});
</script>
@endpush
