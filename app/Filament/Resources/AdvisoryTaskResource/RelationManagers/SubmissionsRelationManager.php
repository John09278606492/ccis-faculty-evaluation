<?php

namespace App\Filament\Resources\AdvisoryTaskResource\RelationManagers;

use App\Models\AdvisorySubmission;
use App\Services\Advising;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Student submissions';

    public function table(Table $table): Table
    {
        return $table->defaultSort('student_name')->columns([
            Tables\Columns\TextColumn::make('student_name')->label('Student')->searchable(),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => AdvisorySubmission::STATUSES[$state] ?? $state)->color(fn ($state) => AdvisorySubmission::COLORS[$state] ?? 'gray'),
            Tables\Columns\TextColumn::make('submitted_at')->dateTime('M j, Y g:ia', timezone: 'Asia/Manila'),
            Tables\Columns\TextColumn::make('feedback')->wrap()->limit(100),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(AdvisorySubmission::STATUSES),
        ])->actions([
            Tables\Actions\Action::make('files')->label('View files')->icon('heroicon-o-paper-clip')
                ->modalContent(fn (AdvisorySubmission $record) => view('filament.partials.advisory-files', ['submission' => $record]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Tables\Actions\Action::make('review')->label('Review')->icon('heroicon-o-check-circle')
                ->visible(fn (AdvisorySubmission $record) => $record->submitted_at !== null && auth()->user()->can('update', $this->getOwnerRecord()))
                ->fillForm(fn (AdvisorySubmission $record) => ['status' => 'approved', 'feedback' => $record->feedback])
                ->form([
                    Forms\Components\Select::make('status')->label('Decision')->options(['approved' => 'Approve', 'returned' => 'Return for changes'])->required()->live(),
                    Forms\Components\Textarea::make('feedback')->label('Feedback')->required(fn (Forms\Get $get) => $get('status') === 'returned')->maxLength(5000),
                ])
                ->action(function (AdvisorySubmission $record, array $data) {
                    app(Advising::class)->review($record, $data['status'], $data['feedback'] ?? null, auth()->user());
                    Notification::make()->title('Review saved')->success()->send();
                }),
        ]);
    }
}
