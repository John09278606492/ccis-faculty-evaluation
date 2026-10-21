<?php

namespace App\Filament\Pages;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.login';

    /**
     * Switches the labels between student sign-in (ID number + PIN) and staff sign-in
     * (email + password). Both need a secret; authenticate() checks it either way.
     */
    public bool $showPasswordField = false;

    public function toggleStaffLogin(): void
    {
        $this->showPasswordField = ! $this->showPasswordField;
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        // Resolve the typed identifier to a user by student ID first, then email,
        // so bulk-imported students can sign in with the ID number their instructor gave them.
        $login = trim($data['login']);
        $user = User::where('student_id', $login)
            ->orWhere('email', $login)
            ->first();

        // Check if user exists and was created through social login
        if ($user && is_null($user->password)) {
            throw ValidationException::withMessages([
                'data.login' => 'This account was created using social login. Please login with Google.',
            ]);
        }

        // Students use the PIN their instructor issued; staff use their password. Both are
        // checked against the same hashed password column.
        if (! $user || ! Hash::check($data['password'] ?? '', $user->password)) {
            $this->throwFailureValidationException();
        }

        Filament::auth()->login($user, $data['remember'] ?? false);

        $user = Filament::auth()->user();

        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function mount(): void
    {
        parent::mount();

        $this->form->fill([
            'login' => '',
            'password' => '',
            'remember' => true,
        ]);
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getLoginFormComponent(),
                        $this->getPasswordFormComponent()
                            ->label(fn () => $this->showPasswordField ? 'Password' : 'PIN')
                            ->helperText(fn () => $this->showPasswordField ? null : 'The PIN your instructor gave you.')
                            ->extraInputAttributes(fn () => $this->showPasswordField ? ['tabindex' => 2] : ['tabindex' => 2, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code']),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    /**
     * Accepts either a student ID number or an email address (for staff).
     */
    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label(fn () => $this->showPasswordField ? 'Email or Student ID' : 'Student ID Number')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
