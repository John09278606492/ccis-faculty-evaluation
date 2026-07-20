<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Submitted Successfully</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md mx-auto px-4">
        <div class="bg-white rounded-lg shadow-md p-8 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Thank You!</h1>
            <p class="text-gray-600 mb-6">Your evaluation has been submitted successfully.</p>

            <div class="bg-gray-50 rounded-lg p-4 text-left text-sm text-gray-600">
                <p><strong>Course:</strong> {{ $courseOffering->course->code }} - {{ $courseOffering->course->name }}
                </p>
                <p><strong>{{ $courseOffering->evaluatee_label ?? 'Faculty' }}:</strong>
                    {{ $courseOffering->evaluatee->name ?? 'N/A' }}</p>
            </div>
        </div>
    </div>
</body>

</html>