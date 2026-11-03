<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Waiting for your sign-in PIN</title>
    @livewireStyles
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #172033; font: 16px/1.6 system-ui, sans-serif; }
        main { max-width: 460px; margin: 24px; padding: 32px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; }
        h1 { font-size: 24px; line-height: 1.3; }
        p { color: #475569; }
        a { display: inline-block; background: #4f46e5; color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; }
    </style>
</head>
<body>
    <main>
        <h1>Your sign-in PIN has not been assigned yet</h1>
        <p>Please wait for your instructor, admin or super-admin to generate and assign your PIN. Contact them if you need access to your account.</p>
        <p>Once you receive your PIN, enter your student ID again to continue signing in.</p>
        @include('auth.approval-poll')
        <a href="{{ route('filament.admin.auth.login') }}">Back to sign in</a>
    </main>
@livewireScripts
</body>
</html>
