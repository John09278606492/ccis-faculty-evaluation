<?php

namespace App\Providers;

use App\Models\ClassRecordSheet;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\ClassroomUpdates;
use App\Services\StudentDatabase\MySqlStudentDatabases;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Google\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        // Prevent N+1 queries in development - helps identify slow loading issues
        Model::preventLazyLoading(! app()->isProduction());

        // Filament 3 equivalent of stackedOnMobile: labels follow each column's
        // configured/translated label and survive Livewire updates without JS.
        Column::configureUsing(function (Column $column) {
            $column->extraCellAttributes(fn (Column $column) => [
                'data-mobile-label' => trim(strip_tags((string) $column->getLabel())),
            ], merge: true);
        }, isImportant: true);

        foreach (['saved', 'deleted'] as $action) {
            Event::listen('eloquent.'.$action.': '.Quiz::class, fn ($quiz) => ClassroomUpdates::course($quiz->course_offering_id, 'quiz', $quiz->id));
            Event::listen('eloquent.'.$action.': '.QuizAttempt::class, fn ($attempt) => ClassroomUpdates::quiz($attempt->quiz_id, 'attempt'));
            Event::listen('eloquent.'.$action.': '.QuizQuestion::class, fn ($question) => ClassroomUpdates::quiz($question->quiz_id, 'question'));
            Event::listen('eloquent.'.$action.': '.ClassRecordSheet::class, fn ($sheet) => ClassroomUpdates::course($sheet->course_offering_id, 'workbook'));
        }

        // Self-hosted CodeMirror bundle (npm run build:code-editor), published to
        // public/js/app/components by `php artisan filament:assets` — no CDN needed.
        FilamentAsset::register([
            AlpineComponent::make('code-editor', resource_path('js/dist/components/code-editor.js')),
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
