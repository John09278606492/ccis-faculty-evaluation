<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Unavailable</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md mx-auto px-4">
        <div class="bg-white rounded-lg shadow-md p-8 text-center">
            <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                    </path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Evaluation Unavailable</h1>
            <p class="text-gray-600 mb-4">This evaluation form is currently not available.</p>

            @if($form->starts_at && now()->lt($form->starts_at))
                <p class="text-sm text-gray-500">Opens on: {{ $form->starts_at->format('M j, Y g:i A') }}</p>
            @elseif($form->ends_at && now()->gt($form->ends_at))
                <p class="text-sm text-gray-500">This evaluation has ended.</p>
            @else
                <p class="text-sm text-gray-500">The form has been deactivated.</p>
            @endif
        </div>
    </div>
</body>

</html>