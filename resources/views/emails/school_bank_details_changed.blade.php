<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Your payout bank account was changed</title>
</head>
<body style="font-family: Arial, sans-serif; color:#0f172a;">
    <h2 style="color:#0ea5e9;">{{ $school->name }}: payout account changed</h2>
    <p>
        The bank account that receives your school's fee payouts was changed from the
        school settings page on {{ now()->format('d M Y, H:i') }}.
    </p>

    @php
        $mask = fn ($n) => $n ? str_repeat('•', max(strlen($n) - 4, 0)).substr($n, -4) : '—';
    @endphp

    <table cellpadding="6" style="border-collapse: collapse;">
        <tr>
            <td><strong>Previous</strong></td>
            <td>{{ $previous['bank'] ?? '—' }} — {{ $mask($previous['account_number'] ?? null) }} ({{ $previous['account_name'] ?? '—' }})</td>
        </tr>
        <tr>
            <td><strong>New</strong></td>
            <td>{{ $school->bank ?? '—' }} — {{ $mask($school->account_number) }} ({{ $school->account_name ?? '—' }})</td>
        </tr>
    </table>

    <p>
        All future payouts will be sent to the new account. Payouts already in progress
        are not affected.
    </p>

    <p style="color:#b91c1c;">
        <strong>If you did not make this change, reset your admin password immediately and contact support.</strong>
    </p>
</body>
</html>
