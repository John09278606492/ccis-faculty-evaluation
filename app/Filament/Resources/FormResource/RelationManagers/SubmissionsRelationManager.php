<?php

namespace App\Filament\Resources\FormResource\RelationManagers;

use App\Models\FormSubmission;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class SubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'submissions';

    protected static ?string $title = 'Submissions';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        // Get the form fields dynamically
        $formFields = $this->ownerRecord->fields()->orderBy('order')->get();

        // Build dynamic columns based on form fields
        $columns = [
            Tables\Columns\TextColumn::make('id')
                ->label('#')
                ->sortable(),

            Tables\Columns\TextColumn::make('respondent')->label('Evaluator')
                ->state(fn (FormSubmission $record) => $record->form?->isSupervisorInstrument() ? 'Supervisor' : 'Student'),

            Tables\Columns\TextColumn::make('department.name')
                ->label('Department')
                ->default('-')
                ->toggleable(),
        ];

        // Add columns for up to 5 form fields
        foreach ($formFields->take(5) as $field) {
            $columns[] = Tables\Columns\TextColumn::make("data.{$field->name}")
                ->label($field->label)
                ->limit(30)
                ->toggleable();
        }

        $columns[] = Tables\Columns\TextColumn::make('submitted_at')
            ->dateTime()
            ->sortable();

        return $table
            ->columns($columns)
            ->filters([
                Tables\Filters\SelectFilter::make('department')
                    ->relationship('department', 'name'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalContent(fn (FormSubmission $record) => view('filament.resources.form-resource.modals.view-submission', [
                        'submission' => $record,
                        'fields' => $this->ownerRecord->fields()->orderBy('order')->get(),
                    ])),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
