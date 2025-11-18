<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Time Tracker Account</title>
</head>
<body style="font-family: Arial, sans-serif; color:#1f2933; background:#f8fafc; padding:24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; padding:24px;">
        <tr>
            <td>
                <h2 style="margin-top:0; color:#111827;">Welcome to Time Tracker!</h2>
                <p>Hi {{ $userName }},</p>
                <p>Your account has been created. Use the credentials below to log in:</p>
                <ul style="list-style:none; padding-left:0;">
                    <li><strong>Login URL:</strong> <a href="{{ $loginUrl }}" style="color:#4f46e5;">{{ $loginUrl }}</a></li>
                    <li><strong>Username:</strong> {{ $email }}</li>
                    <li><strong>Password:</strong> {{ $password }}</li>
                </ul>
                <p>Please sign in and change your password after your first login.</p>
                <p style="margin-top:24px;">Thanks,<br>The Time Tracker Team</p>
            </td>
        </tr>
    </table>
</body>
</html>

