<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasResourceFormDraft;
use App\Filament\Concerns\ShowsEvaluationWorkflow;
use Filament\Resources\Pages\EditRecord;

abstract class DraftEditRecord extends EditRecord
{
    use HasResourceFormDraft;
    use ShowsEvaluationWorkflow;
}
