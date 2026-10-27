<x-filament-panels::page.simple>
    @if (config('classroom.login.evaluation_entry'))
        <a href="{{ route('evaluation.entry') }}" class="block rounded-lg bg-primary-600 p-4 text-center font-semibold text-white">Faculty evaluation — enter student ID</a>
    @endif
    @if (filament()->hasRegistration())
        <x-slot name="subheading">
            {{ __('filament-panels::pages/auth/login.actions.register.before') }}

            {{ $this->registerAction }}
        </x-slot>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

    <x-filament-panels::form id="form" wire:submit="authenticate">
        {{ $this->form }}

        @if (! $showPasswordField && $studentIdentifier !== null)
            @include('auth.approval-poll')
            <button type="button" wire:click="changeStudentId" class="text-sm text-primary-600 underline">Use a different student ID</button>
        @endif

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>

    <div class="mt-4 text-center">
        <button
            type="button"
            wire:click="toggleStaffLogin"
            class="text-sm font-medium text-primary-600 underline hover:text-primary-500 dark:text-primary-400"
        >
            {{ $showPasswordField ? 'Back to student ID login' : 'Instructor or admin? Sign in with a password' }}
        </button>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
</x-filament-panels::page.simple>
