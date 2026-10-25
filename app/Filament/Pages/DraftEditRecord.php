<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasResourceFormDraft;

abstract class DraftEditRecord extends \Filament\Resources\Pages\EditRecord
{
    use HasResourceFormDraft;
    use \App\Filament\Concerns\ShowsEvaluationWorkflow;
}
