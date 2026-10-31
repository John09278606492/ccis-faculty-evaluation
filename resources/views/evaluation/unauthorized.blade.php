<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Evaluation</title>
    <x-bisu-favicon />
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8">
    <div class="max-w-md mx-auto px-4">
        <div class="bg-white rounded-lg shadow-lg p-8 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Access Denied</h1>
            <p class="text-gray-600 mb-4">{{ $message ?? 'You do not have permission to access this evaluation form.' }}</p>
            
            @if(isset($courseOffering))
            <div class="bg-gray-50 rounded-lg p-4 mb-4 text-left text-sm">
                <p><span class="text-gray-500">Course:</span> <span class="font-medium">{{ $courseOffering->course->code }}</span></p>
                <p><span class="text-gray-500">{{ $courseOffering->evaluatee_label ?? 'Faculty' }}:</span> <span class="font-medium">{{ $courseOffering->evaluatee->name ?? 'N/A' }}</span></p>
            </div>
            @endif

            <a href="{{ url('/admin') }}" class="inline-block bg-gray-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-gray-700 transition">
                Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
