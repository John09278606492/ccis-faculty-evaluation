<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create your sign-in PIN</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f8fafc; color: #172033; font: 16px/1.6 system-ui, sans-serif; }
        main { box-sizing: border-box; width: calc(100% - 32px); max-width: 460px; margin: 24px 16px; padding: 32px; background: white; border: 1px solid #e2e8f0; border-radius: 16px; }
        h1 { margin-top: 0; font-size: 24px; line-height: 1.3; }
        p { color: #475569; }
        label { display: block; margin-top: 16px; font-size: 14px; font-weight: 600; }
        input { box-sizing: border-box; width: 100%; margin-top: 6px; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font: 600 22px/1 ui-monospace, monospace; letter-spacing: .35em; }
        input:focus { outline: 3px solid rgba(79, 70, 229, .25); border-color: #4f46e5; }
        .error { margin: 16px 0 0; padding: 10px 12px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 14px; }
        .primary { width: 100%; margin-top: 20px; min-height: 46px; border: 0; border-radius: 10px; background: #4f46e5; color: white; font: 600 16px system-ui, sans-serif; cursor: pointer; }
        .skip { width: 100%; margin-top: 8px; min-height: 44px; border: 0; background: none; color: #475569; font: 14px system-ui, sans-serif; text-decoration: underline; cursor: pointer; }
        .hint { font-size: 13px; }
        @media (max-width: 420px) { main { padding: 24px 20px; } }
    </style>
</head>
<body>
    <main>
        <h1>Create your sign-in PIN</h1>
        <p>You're signed in. Choose a {{ \App\Services\StudentPins::LENGTH }}-digit PIN so next time you can sign in with your student ID and PIN, without waiting for approval.</p>
        <p class="hint">Keep it to yourself. If you forget it, ask your instructor to issue a new one.</p>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <form method="POST" action="{{ route('student.create-pin') }}">
            @csrf
            <label for="pin">New PIN</label>
            <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{{ '{'.\App\Services\StudentPins::LENGTH.'}' }}" maxlength="{{ \App\Services\StudentPins::LENGTH }}" autocomplete="new-password" required autofocus>
            <label for="pin_confirmation">Type it again</label>
            <input id="pin_confirmation" name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{{ '{'.\App\Services\StudentPins::LENGTH.'}' }}" maxlength="{{ \App\Services\StudentPins::LENGTH }}" autocomplete="new-password" required>
            <button class="primary" type="submit">Save PIN and continue</button>
        </form>
        <form method="POST" action="{{ $skipUrl }}">
            @csrf
            <button class="skip" type="submit">Not now</button>
        </form>
    </main>
</body>
</html>
