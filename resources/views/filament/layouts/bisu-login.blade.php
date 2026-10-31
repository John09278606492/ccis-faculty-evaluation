@push('styles')
    <link rel="stylesheet" href="{{ asset('css/bisu-login.css') }}?v={{ filemtime(public_path('css/bisu-login.css')) }}">
@endpush

<x-filament-panels::layout.base :livewire="$livewire">
    <main class="bisu-login-layout">
        {{ $slot }}
    </main>
</x-filament-panels::layout.base>
