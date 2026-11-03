<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\MyAcknowledgements;
use App\Services\AcknowledgementForms;
use Filament\Widgets\Widget;

/** A reminder on the student's dashboard, shown only while something is waiting for them. */
class StudentAcknowledgementsWidget extends Widget
{
    protected static string $view = 'filament.widgets.student-acknowledgements';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user?->is_student
            && $user->can('page_MyAcknowledgements')
            && static::toSign() > 0;
    }

    protected function getViewData(): array
    {
        return ['count' => static::toSign(), 'url' => MyAcknowledgements::getUrl()];
    }

    private static function toSign(): int
    {
        return app(AcknowledgementForms::class)->toSignCount(auth()->user(), filament()->getTenant()?->id);
    }
}
