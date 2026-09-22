{{-- L5: FEYRA-branded, matching the receipt email's palette and type. Rendered
     through ->view() exactly as before — the mailable is unchanged, so the reset
     link, its token and the expiry rule are untouched. Table layout and inline
     styles only: Gmail strips <style> and ignores classes. --}}
@php
    $font = "font-family: 'Plus Jakarta Sans', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $expiry = (int) config('auth.passwords.users.expire');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset your {{ $school->name }} password</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F7F7F8;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; background-color: #F7F7F8;">
<tr>
<td align="center" style="padding: 24px 12px;">

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; max-width: 560px;">

<tr>
<td align="center" style="padding: 0 0 20px;">
<img src="{{ asset('images/feyra-mark.png') }}" width="28" height="28" alt="" style="width: 28px; height: 28px; border-radius: 7px; vertical-align: middle;">
<span style="font-size: 19px; font-weight: 700; color: #121217; vertical-align: middle; {{ $font }}">@include('marketing.partials.brand-name')</span>
</td>
</tr>

<tr>
<td style="background-color: #FFFFFF; border-radius: 20px; padding: 28px 24px;">

<h1 style="margin: 0; font-size: 22px; line-height: 1.25; font-weight: 800; letter-spacing: -0.3px; color: #121217; {{ $font }}">Reset your password</h1>

<p style="margin: 12px 0 0; font-size: 15px; line-height: 1.6; color: #6C6C89; {{ $font }}">
We received a request to reset the admin password for <strong style="color: #121217;">{{ $school->name }}</strong>. Choose a new one using the button below.
</p>

<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: separate; margin: 24px 0 0;">
<tr>
<td style="border-radius: 12px; background-color: #121217;">
<a href="{{ $resetLink }}" style="display: inline-block; padding: 13px 26px; font-size: 15px; font-weight: 700; color: #FFFFFF; text-decoration: none; border-radius: 12px; {{ $font }}">Reset password</a>
</td>
</tr>
</table>

<p style="margin: 20px 0 0; font-size: 13px; line-height: 1.6; color: #6C6C89; {{ $font }}">
If the button does not work, copy this link into your browser:<br>
<a href="{{ $resetLink }}" style="color: #5423E7; word-break: break-all;">{{ $resetLink }}</a>
</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin: 24px 0 0;">
<tr><td style="border-top: 1px solid #D1D1DB; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>

<p style="margin: 16px 0 0; font-size: 13px; line-height: 1.6; color: #6C6C89; {{ $font }}">
This link expires in {{ $expiry }} minutes and can be used once. If you did not ask to reset your password, no action is needed — your current password still works.
</p>

</td>
</tr>

<tr>
<td align="center" style="padding: 18px 8px 0; font-size: 12px; line-height: 1.5; color: #6C6C89; {{ $font }}">
Sent by @include('marketing.partials.brand-name') because a password reset was requested for {{ $school->name }}.
</td>
</tr>

</table>

</td>
</tr>
</table>
</body>
</html>
