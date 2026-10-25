<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\StudentSignIns;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.login';

    /**
     * Students select their ID before entering an issued PIN. Staff use a password.
     */
    public bool $showPasswordField = false;

    protected function getRateLimitKey($method, $component = null): string
    {
        return parent::getRateLimitKey($method, $component).':'.hash('sha256', mb_strtolower(trim((string) ($this->data['login'] ?? ''))));
    }

    #[Locked]
    public ?string $studentIdentifier = null;

    public function toggleStaffLogin(): void
    {
        StudentSignIns::cancel();
        $this->showPasswordField = ! $this->showPasswordField;
        $this->studentIdentifier = null;
        $this->form->fill(['login' => '', 'password' => '', 'remember' => true]);
        $this->resetValidation();
    }

    public function changeStudentId(): void
    {
        StudentSignIns::cancel();
        $this->studentIdentifier = null;
        $this->form->fill(['login' => '', 'password' => '', 'remember' => true]);
        $this->resetValidation();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->showPasswordField) {
            session()->forget('url.intended');
        }

        $data = $this->form->getState();

        if (! $this->showPasswordField) {
            $identifier = $this->studentIdentifier ?? trim($data['login']);
            $student = User::where('student_id', $identifier)->where('is_student', true)->first();
            if (! $student) {
                $this->throwFailureValidationException();
            }
            if (! $student->pin_issued_at) {
                StudentSignIns::request($student);
                $this->redirect(route('student.pin-pending'));

                return null;
            }
            if ($this->studentIdentifier === null) {
                StudentSignIns::request($student);
                $this->studentIdentifier = $identifier;
                $this->data['password'] = '';

                return null;
            }
            $data['login'] = $identifier;
        }

        // Resolve the typed identifier to a user by student ID first, then email,
        // so bulk-imported students can sign in with the ID number their instructor gave them.
        $login = trim($data['login']);
        $user = $student ?? User::where('student_id', $login)
            ->orWhere('email', $login)
            ->first();

        // Student accounts must complete the ID/PIN flow even when staff mode is selected.
        if ($this->showPasswordField && $user?->is_student) {
            $this->throwFailureValidationException();
        }

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
        StudentSignIns::cancel();

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
                            ->visible(fn () => $this->showPasswordField || $this->studentIdentifier !== null)
                            ->label(fn () => $this->showPasswordField ? 'Password' : 'PIN')
                            ->helperText(fn () => $this->showPasswordField ? null : 'Your instructor-issued PIN for quizzes, exams and activities.')
                            ->extraInputAttributes(fn () => $this->showPasswordField ? ['tabindex' => 2] : ['tabindex' => 2, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code']),
                        $this->getRememberFormComponent()->visible(fn () => $this->showPasswordField || $this->studentIdentifier !== null),
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
            ->disabled(fn () => ! $this->showPasswordField && $this->studentIdentifier !== null)
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

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()
            ->label(fn () => ! $this->showPasswordField && $this->studentIdentifier === null ? 'Continue' : 'Sign in');
    }
}
