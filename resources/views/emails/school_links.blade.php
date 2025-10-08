<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Your School Payment Portal Links</title>
</head>
<body style="font-family: Arial, sans-serif; color:#0f172a;">
    <h2 style="color:#0ea5e9;">Welcome, {{ $school->name }}</h2>
    <p>
        Your school has been registered successfully. Below are your important links. Keep this email for your records.
    </p>

    <ul>
        <li><strong>Payment Page:</strong> <a href="{{ $links['payment'] }}">{{ $links['payment'] }}</a></li>
        <li><strong>Categories:</strong> <a href="{{ $links['categories'] }}">{{ $links['categories'] }}</a></li>
        <li><strong>Subcategories:</strong> <a href="{{ $links['subcategories'] }}">{{ $links['subcategories'] }}</a></li>
        <li><strong>Transactions:</strong> <a href="{{ $links['transactions'] }}">{{ $links['transactions'] }}</a></li>
    </ul>

    <p>
        You can create categories and fee types, and share your payment page link with parents/students to accept payments.
    </p>

    <p style="color:#475569; font-size: 12px;">
        If you did not request this, please ignore this email.
    </p>
</body>
</html>
