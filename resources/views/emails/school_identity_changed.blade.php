<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Your school sign-in details were changed</title>
</head>
<body style="font-family: Arial, sans-serif; color:#0f172a;">
    <h2 style="color:#0ea5e9;">{{ $previous['name'] ?? $school->name }}: sign-in details changed</h2>
    <p>
        The details used to sign in to and recover your school's admin account were
        changed from the school settings page on {{ \App\Support\BusinessTime::display(now())->format('d M Y, H:i') }} ({{ \App\Support\BusinessTime::label() }}).
    </p>

    <table cellpadding="6" style="border-collapse: collapse;">
        @if(($previous['name'] ?? null) !== $school->name)
            <tr>
                <td><strong>School name (login)</strong></td>
                <td>{{ $previous['name'] ?? '—' }} → {{ $school->name }}</td>
            </tr>
        @endif
        @if(($previous['email'] ?? null) !== $school->email)
            <tr>
                <td><strong>Email (password resets)</strong></td>
                <td>{{ $previous['email'] ?? '—' }} → {{ $school->email }}</td>
            </tr>
        @endif
    </table>

    <p>
        This notice was sent to the address that was on file before the change.
        Password-reset links are now sent to the address shown above.
    </p>

    <p style="color:#b91c1c;">
        <strong>If you did not make this change, contact FEYRA support immediately so the account can be secured.</strong>
    </p>
</body>
</html>
