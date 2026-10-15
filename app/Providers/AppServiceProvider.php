<?php

namespace App\Providers;

use App\Models\User;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
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
        //
        parent::register();
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
        \Filament\Tables\Columns\Column::configureUsing(function (\Filament\Tables\Columns\Column $column) {
            $column->extraCellAttributes(fn (\Filament\Tables\Columns\Column $column) => [
                'data-mobile-label' => trim(strip_tags((string) $column->getLabel())),
            ], merge: true);
        }, isImportant: true);

        foreach (['saved', 'deleted'] as $action) {
            Event::listen('eloquent.'.$action.': '.\App\Models\Quiz::class, fn ($quiz) => \App\Services\ClassroomUpdates::course($quiz->course_offering_id, 'quiz', $quiz->id));
            Event::listen('eloquent.'.$action.': '.\App\Models\QuizAttempt::class, fn ($attempt) => \App\Services\ClassroomUpdates::quiz($attempt->quiz_id, 'attempt'));
            Event::listen('eloquent.'.$action.': '.\App\Models\QuizQuestion::class, fn ($question) => \App\Services\ClassroomUpdates::quiz($question->quiz_id, 'question'));
            Event::listen('eloquent.'.$action.': '.\App\Models\ClassRecordSheet::class, fn ($sheet) => \App\Services\ClassroomUpdates::course($sheet->course_offering_id, 'workbook'));
        }

        // Self-hosted CodeMirror bundle (npm run build:code-editor), published to
        // public/js/app/components by `php artisan filament:assets` — no CDN needed.
        FilamentAsset::register([
            AlpineComponent::make('code-editor', resource_path('js/dist/components/code-editor.js')),
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
