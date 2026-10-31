<?php

namespace App\Providers;

use App\Livewire\DatabaseNotifications;
use App\Livewire\GuidedTours;
use App\Livewire\PanelChrome;
use App\Livewire\WorkspaceContext;
use App\Models\AcademicSection;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\ClassRecordSheet;
use App\Models\Contact;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\Post;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Rating;
use App\Models\RatingCriteria;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSectionMembership;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\ClassroomUpdates;
use App\Services\DatabaseNotificationActions;
use App\Services\StudentDatabase\MySqlStudentDatabases;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;
use Jeffgreco13\FilamentBreezy\Livewire\TwoFactorAuthentication;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use SocialiteProviders\Google\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();
        $this->app->singleton(StudentDbServer::class);
        $this->app->bind(StudentDatabases::class, MySqlStudentDatabases::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Local and campus servers share this checkout's route cache but use
        // different debug settings. Keep the script URL stable in both modes.
        Livewire::setScriptRoute(fn ($handle) => Route::get('/livewire/livewire.js', $handle)->name('livewire.script'));
        Route::get('/livewire/livewire.min.js', [FrontendAssets::class, 'returnJavaScriptAsFile'])->name('livewire.script.min');
        Livewire::component('campus-guided-tours', GuidedTours::class);
        RateLimiter::for('evaluation-identify', function (Request $request) {
            $identifier = mb_strtolower(trim((string) $request->input('student_id', '')));

            return [
                Limit::perMinute(20000)->by('evaluation-network:'.$request->ip()),
                Limit::perMinute(10)->by('evaluation-id:'.hash('sha256', $identifier.'|'.$request->ip())),
            ];
        });
        RateLimiter::for('evaluation-access', function (Request $request) {
            $identity = $request->user()?->id ?? $request->session()->getId();

            return Limit::perMinute(120)->by('evaluation-access:'.$identity);
        });
        $this->app->scoped(AssignedDataAccess::class);
        foreach ([AcademicSection::class, Department::class, Course::class, SchoolYear::class, Semester::class, CourseOffering::class,
            Form::class, FormField::class, RatingCriteria::class, FormSubmission::class, Rating::class, User::class, Quiz::class, QuizQuestion::class,
            QuizAttempt::class, ClassRecordSheet::class, Post::class, Contact::class] as $model) {
            $model::addGlobalScope('assigned_data', function (Builder $query) {
                $scope = app(AssignedDataAccess::class);
                $campus = filament()->getTenant();
                $actor = $scope->actor;
                if ($actor && ! $actor->isStudent() && $campus) {
                    $scope->apply($query, $actor, $campus);
                }
            });
        }

        // Prevent N+1 queries in development - helps identify slow loading issues
        Model::preventLazyLoading(! app()->isProduction());

        // Breezy registers the My Profile forms only while the panel boots, which happens after
        // Livewire looks them up on form requests, so every save or upload there failed with 419.
        Livewire::component('workspace-context', WorkspaceContext::class);
        Livewire::component('panel-chrome', PanelChrome::class);
        Livewire::component('database-notifications', DatabaseNotifications::class);
        // Native Filament import/export chunks run in long-lived workers too.
        Event::listen(JobProcessed::class, function () {
            ClassroomUpdates::sendPanel();
            app(DatabaseNotificationActions::class)->flushAll();
        });
        Event::listen(JobFailed::class, fn () => ClassroomUpdates::sendPanel());
        Livewire::component('personal_info', PersonalInfo::class);
        Livewire::component('update_password', UpdatePassword::class);
        Livewire::component('two_factor_authentication', TwoFactorAuthentication::class);

        // Filament 3 equivalent of stackedOnMobile: labels follow each column's
        // configured/translated label and survive Livewire updates without JS.
        Column::configureUsing(function (Column $column) {
            $column->extraCellAttributes(fn (Column $column) => [
                'data-mobile-label' => trim(strip_tags((string) $column->getLabel())),
            ], merge: true);
        }, isImportant: true);

        // Long campus lists: fewer page clicks than Filament's default of 10 rows.
        Table::configureUsing(fn (Table $table) => $table->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25));

        foreach (['saved', 'deleted'] as $action) {
            Event::listen('eloquent.'.$action.': '.Quiz::class, fn ($quiz) => ClassroomUpdates::course($quiz->course_offering_id, 'quiz', $quiz->id));
            // Staff see every attempt; a student hears only of their own, privately, so a class
            // starting or submitting together does not make every classmate's page call back.
            Event::listen('eloquent.'.$action.': '.QuizAttempt::class, function ($attempt) {
                ClassroomUpdates::quiz($attempt->quiz_id, 'attempt');
                ClassroomUpdates::viewer($attempt->user_id, 'attempt');
            });
            Event::listen('eloquent.'.$action.': '.QuizQuestion::class, fn ($question) => ClassroomUpdates::quiz($question->quiz_id, 'question'));
            Event::listen('eloquent.'.$action.': '.ClassRecordSheet::class, fn ($sheet) => ClassroomUpdates::course($sheet->course_offering_id, 'workbook'));
        }

        // Everything else shown in panel tables and widgets: open pages reload when it changes.
        // Staff-only kinds skip students, whose pages never show them.
        $panelData = [
            FormSubmission::class => ['evaluation', true],
            Rating::class => ['evaluation', true],
            Form::class => ['forms', false],
            FormField::class => ['forms', false],
            RatingCriteria::class => ['forms', false],
            Campus::class => ['setup', false],
            Department::class => ['setup', false],
            Course::class => ['setup', false],
            CourseOffering::class => ['setup', false],
            SchoolYear::class => ['setup', false],
            Semester::class => ['setup', false],
            User::class => ['users', true],
            EvaluationCycle::class => ['setup', false],
            BackgroundTask::class => ['tasks', true],
            StudentAcademicRecord::class => ['users', true],
            StudentSectionMembership::class => ['users', true],
            AcademicSection::class => ['users', true],
            StudentSubjectEnrolment::class => ['users', true],
            Role::class => ['access', true],
            Permission::class => ['access', true],
            Post::class => ['posts', false],
            Contact::class => ['posts', true],
        ];
        foreach ($panelData as $model => [$kind, $staffOnly]) {
            Event::listen('eloquent.saved: '.$model, function (Model $record) use ($kind, $staffOnly) {
                if ($record->wasRecentlyCreated || $record->wasChanged()) {
                    ClassroomUpdates::panel($kind, $staffOnly);
                }
            });
            Event::listen('eloquent.deleted: '.$model, fn () => ClassroomUpdates::panel($kind, $staffOnly));
        }

        // Self-hosted CodeMirror bundle (npm run build:code-editor), published to
        // public/js/app/components by `php artisan filament:assets` — no CDN needed.
        FilamentAsset::register([
            AlpineComponent::make('code-editor', resource_path('js/dist/components/code-editor.js')),
            AlpineComponent::make('code-checker', resource_path('js/dist/components/code-checker.js')),
            Js::make('chart-js', resource_path('js/dist/chart.js'))->loadedOnRequest(),
        ]);

        Gate::define('viewApiDocs', function (User $user) {
            return true;
        });
        // Gate::policy()
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('google', Provider::class);
        });
    }
}
