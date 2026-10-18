<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a new password</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <form method="POST" action="{{ route('password.change') }}" class="w-full max-w-sm bg-white rounded-xl shadow p-6 space-y-4">
        @csrf
        <h1 class="text-lg font-semibold text-gray-900">Choose a new password</h1>
        <p class="text-sm text-gray-600">Your account still uses the shared starting password. Pick your own before you continue.</p>
        @if ($errors->any())
            <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif
        <label class="block text-sm font-medium text-gray-700">
            New password
            <input type="password" name="password" required minlength="8" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium text-gray-700">
            Confirm new password
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save password</button>
    </form>
</body>
</html>
