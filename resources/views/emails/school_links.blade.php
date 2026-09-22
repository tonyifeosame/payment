{{-- L5: FEYRA-branded, matching the receipt email's palette and type. Rendered
     through ->view() exactly as before — the mailable is unchanged, so every link
     is the same route() the controller built. "Subcategories" is now "Fee types",
     the name the admin area itself uses. Table layout and inline styles only:
     Gmail strips <style> and ignores classes. --}}
@php
    $font = "font-family: 'Plus Jakarta Sans', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $rowLabel = 'padding: 10px 0 2px; font-size: 12px; letter-spacing: 1.2px; text-transform: uppercase; font-weight: 700; color: #6C6C89; '.$font;
    $rowLink = 'padding: 0 0 10px; font-size: 14px; line-height: 1.5; color: #5423E7; word-break: break-all; '.$font;

    // Label => url, in the order an admin actually needs them. The labels mirror
    // the admin navigation, so the email and the app name things the same way.
    // Only links the controller actually supplied are shown.
    $rows = array_filter([
        'Dashboard' => $links['dashboard'] ?? null,
        'Your payment page' => $links['payment'] ?? null,
        'Categories' => $links['categories'] ?? null,
        'Fee types' => $links['subcategories'] ?? null,
        'Transactions' => $links['transactions'] ?? null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $school->name }} is set up</title>
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

<h1 style="margin: 0; font-size: 22px; line-height: 1.25; font-weight: 800; letter-spacing: -0.3px; color: #121217; {{ $font }}">{{ $school->name }} is set up</h1>

<p style="margin: 12px 0 0; font-size: 15px; line-height: 1.6; color: #6C6C89; {{ $font }}">
Your school is registered. Here are the links you will use — keep this email for your records.
</p>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin: 20px 0 0;">
@foreach($rows as $label => $url)
<tr><td style="{{ $rowLabel }}">{{ $label }}</td></tr>
<tr><td style="{{ $rowLink }}"><a href="{{ $url }}" style="color: #5423E7; text-decoration: underline;">{{ $url }}</a></td></tr>
@endforeach
</table>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse: collapse; margin: 14px 0 0;">
<tr><td style="border-top: 1px solid #D1D1DB; font-size: 0; line-height: 0;">&nbsp;</td></tr>
</table>

<p style="margin: 16px 0 0; font-size: 14px; line-height: 1.6; color: #6C6C89; {{ $font }}">
<strong style="color: #121217;">What to do next.</strong> Create your academic session, add your categories and fee types, then share your payment page with parents. Every successful payment sends a receipt automatically and is paid out to your bank account.
</p>

</td>
</tr>

<tr>
<td align="center" style="padding: 18px 8px 0; font-size: 12px; line-height: 1.5; color: #6C6C89; {{ $font }}">
Sent by @include('marketing.partials.brand-name') because {{ $school->name }} was registered. If this was not you, please contact us.
</td>
</tr>

</table>

</td>
</tr>
</table>
</body>
</html>
