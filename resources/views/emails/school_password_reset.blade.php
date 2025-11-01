<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Reset Your Password</title>
</head>
<body style="font-family: Arial, sans-serif; color:#0f172a;">
    <h2 style="color:#0ea5e9;">Reset Your Password for {{ $school->name }}</h2>
    <p>
        You are receiving this email because we received a password reset request for your school's admin account.
    </p>

    <p>
        Please click the button below to reset your password:
    </p>

    <a href="{{ $resetLink }}" style="display: inline-block; background-color: #0ea5e9; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Reset Password</a>

    <p>
        If you did not request a password reset, no further action is required.
    </p>

    <p style="color:#475569; font-size: 12px;">
        This password reset link will expire in {{ config('auth.passwords.users.expire') }} minutes.
    </p>
</body>
</html>
