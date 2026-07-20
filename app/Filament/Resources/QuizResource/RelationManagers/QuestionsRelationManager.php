<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Models\QuizQuestion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Questions';

    protected static ?string $recordTitleAttribute = 'question_text';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->options(QuizQuestion::TYPES)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('options', null))
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('question_text')
                    ->label('Question')
                    ->required()
                    ->rows(2)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('points')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),

                // Multiple choice: repeater of options, each flaggable as correct.
                Forms\Components\Repeater::make('options')
                    ->label('Choices')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->label('Choice text')
                            ->required(),
                        Forms\Components\Checkbox::make('is_correct')
                            ->label('Correct answer'),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add Choice')
                    ->reorderable()
                    ->defaultItems(2)
                    ->minItems(2)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'multiple_choice')
                    ->required(fn (Forms\Get $get) => $get('type') === 'multiple_choice'),

                // True/false: which answer is correct.
                Forms\Components\Radio::make('true_false_correct')
                    ->label('Correct answer')
                    ->options(['true' => 'True', 'false' => 'False'])
                    ->default(fn ($record) => $record?->correct_answer[0] ?? 'true')
                    ->inline()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'true_false')
                    ->dehydrated(fn (Forms\Get $get) => $get('type') === 'true_false'),

                // Short answer: list of accepted answers (case-insensitive match at grading time).
                Forms\Components\TagsInput::make('correct_answer')
                    ->label('Accepted Answers')
                    ->helperText('Any of these (case-insensitive) will be marked correct.')
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'short_answer')
                    ->required(fn (Forms\Get $get) => $get('type') === 'short_answer'),

                Forms\Components\Hidden::make('order')
                    ->default(fn () => $this->ownerRecord->questions()->max('order') + 1),
            ]);
    }

    /**
     * Convert the builder-friendly form fields (options repeater with is_correct flags,
     * true_false_correct radio) into the normalized options/correct_answer columns.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['type'] === 'multiple_choice') {
            $rawOptions = collect($data['options'] ?? [])->values();
            $options = [];
            $correctAnswer = [];

            foreach ($rawOptions as $i => $option) {
                $key = (string) $i;
                $options[] = ['key' => $key, 'label' => $option['label'] ?? ''];

                if (! empty($option['is_correct'])) {
                    $correctAnswer[] = $key;
                }
            }

            $data['options'] = $options;
            $data['correct_answer'] = $correctAnswer;
        } elseif ($data['type'] === 'true_false') {
            $data['options'] = [
                ['key' => 'true', 'label' => 'True'],
                ['key' => 'false', 'label' => 'False'],
            ];
            $data['correct_answer'] = [$data['true_false_correct'] ?? 'true'];
        } else {
            $data['options'] = null;

            if ($data['type'] !== 'short_answer') {
                $data['correct_answer'] = null;
            }
        }

        unset($data['true_false_correct']);

        return $data;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateFormDataBeforeSave($data);
    }

    /**
     * Reshape the stored options/correct_answer columns back into the builder-friendly
     * shape (options repeater with an is_correct flag per choice) when opening Edit.
     */
    protected function mutateRecordDataBeforeFill(array $data): array
    {
        if (($data['type'] ?? null) === 'multiple_choice') {
            $correctKeys = $data['correct_answer'] ?? [];
            $data['options'] = collect($data['options'] ?? [])
                ->map(fn ($option) => [
                    'label' => $option['label'] ?? '',
                    'is_correct' => in_array($option['key'] ?? null, $correctKeys, true),
                ])
                ->toArray();
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->defaultSort('order')
            ->columns([
                Tables\Columns\TextColumn::make('order')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('question_text')
                    ->label('Question')
                    ->limit(60)
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QuizQuestion::TYPES[$state] ?? $state),

                Tables\Columns\TextColumn::make('points')
                    ->sortable(),

                Tables\Columns\TextColumn::make('options')
                    ->label('Choices')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state).' choices' : '-')
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(QuizQuestion::TYPES),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Question')
                    ->mutateFormDataUsing(fn (array $data) => $this->mutateFormDataBeforeCreate($data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => $this->mutateRecordDataBeforeFill($data))
                    ->mutateFormDataUsing(fn (array $data) => $this->mutateFormDataBeforeSave($data)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
