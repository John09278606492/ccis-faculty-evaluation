<div class="bisu-login-root">
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_PAGE_START, scopes: $this->getRenderHookScopes()) }}

    <div @class(['bisu-login', 'is-staff' => $showPasswordField])>
        <aside class="bisu-login-campus" aria-label="BISU Balilihan Campus">
            <div class="bisu-login-brand">
                <img src="{{ asset('images/bisu/bisu-seal.png') }}" width="504" height="504" alt="Bohol Island State University seal" class="bisu-login-seal" fetchpriority="high">
                <div>
                    <span class="bisu-login-university">Bohol Island<br>State University</span>
                    <span class="bisu-login-campus-name">Balilihan Campus</span>
                </div>
            </div>
            <div class="bisu-login-message">
                <h1>Faculty Evaluation<br>Management System</h1>
            </div>
            <figure class="bisu-login-photo-wrap">
                <img src="{{ asset('images/bisu/balilihan-campus-entrance.png') }}" width="2916" height="1386" alt="Front entrance of Bohol Island State University, Balilihan Campus" class="bisu-login-photo" decoding="async">
                <figcaption class="bisu-login-location">
                    <x-heroicon-o-map-pin aria-hidden="true" />
                    <span>Magsija, Balilihan, Bohol</span>
                    <span class="bisu-login-location-label">Balilihan Campus</span>
                </figcaption>
            </figure>
        </aside>

        <section class="bisu-login-pane" aria-labelledby="bisu-access-heading">
            <div class="bisu-login-content">
                <div class="bisu-login-product">
                    <span class="bisu-login-product-icon"><x-heroicon-o-academic-cap aria-hidden="true" /></span>
                    <span>University Evaluation Portal</span>
                </div>
                <header class="bisu-login-heading">
                    <p class="bisu-login-overline">BOHOL ISLAND STATE UNIVERSITY</p>
                    <h2 id="bisu-access-heading">Welcome to BISU</h2>
                    <p>Select your access to continue.</p>
                </header>
                <div class="bisu-login-access" role="group" aria-label="Choose sign-in access">
                    <button type="button" wire:click="toggleStaffLogin" wire:loading.attr="disabled" wire:target="toggleStaffLogin"
                        @disabled(! $showPasswordField) aria-pressed="{{ $showPasswordField ? 'false' : 'true' }}"
                        @class(['bisu-login-access-button', 'is-active' => ! $showPasswordField])>
                        <x-heroicon-o-user aria-hidden="true" /> Student access
                    </button>
                    <button type="button" wire:click="toggleStaffLogin" wire:loading.attr="disabled" wire:target="toggleStaffLogin"
                        @disabled($showPasswordField) aria-pressed="{{ $showPasswordField ? 'true' : 'false' }}"
                        @class(['bisu-login-access-button', 'is-active' => $showPasswordField])>
                        <x-heroicon-o-identification aria-hidden="true" /> Faculty &amp; staff
                    </button>
                </div>
                @if (! $showPasswordField)
                    <div class="bisu-login-evaluation" wire:key="student-evaluation-entry">
                        <div class="bisu-login-evaluation-heading">
                            <span class="bisu-login-evaluation-icon"><x-heroicon-o-clipboard-document-check aria-hidden="true" /></span>
                            <div><h3>Student evaluation</h3><p>View and complete the faculty evaluations assigned to you.</p></div>
                        </div>
                        <a href="{{ route('evaluation.entry') }}" class="bisu-login-primary">
                            Continue with student ID <x-heroicon-o-arrow-right aria-hidden="true" />
                        </a>
                        <p class="bisu-login-id-note"><x-heroicon-o-identification aria-hidden="true" /> Have your campus-issued student ID ready.</p>
                    </div>
                @else
                    <p class="bisu-login-staff-note" wire:key="staff-signin-guidance">Sign in with your assigned faculty or staff account.</p>
                @endif

                @if ($showPasswordField)
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}
                    <x-filament-panels::form id="form" wire:submit="authenticate" class="bisu-login-form">
                        {{ $this->form }}
                        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
                    </x-filament-panels::form>
                @endif
                <div class="bisu-login-other-access">
                    @if ($showPasswordField)
                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
                    @endif
                    @if ($showPasswordField && filament()->hasRegistration())
                        <p class="bisu-login-register">{{ __('filament-panels::pages/auth/login.actions.register.before') }} {{ $this->registerAction }}</p>
                    @endif
                </div>
                <p class="bisu-login-help"><x-heroicon-o-question-mark-circle aria-hidden="true" /><span>For access assistance, contact your department or campus administrator.</span></p>
            </div>
            <footer class="bisu-login-footer">
                <span>&copy; {{ now()->year }} BISU Balilihan Campus</span>
                <a href="https://bisubalilihan.com/" target="_blank" rel="noopener noreferrer">Campus website <x-heroicon-o-arrow-up-right aria-hidden="true" /></a>
            </footer>
        </section>
    </div>
    <x-filament-actions::modals />
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_PAGE_END, scopes: $this->getRenderHookScopes()) }}
</div>
