<?php

namespace App\Filament\Resources\QuizResource\RelationManagers;

use App\Filament\Forms\Components\CodeEditor;
use App\Models\QuizQuestion;
use App\Support\CodeProject;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

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

                // Code: language preset, starter files, run setting and a reference solution for grading.
                Forms\Components\Select::make('code_language')
                    ->label('Language')
                    ->options(QuizQuestion::CODE_LANGUAGES)
                    ->default('web')
                    ->live()
                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $state) {
                        $files = CodeProject::decode($get('starter_files'), []);
                        if ($state && CodeProject::length($files) === 0) {
                            $set('starter_files', CodeProject::encode(CodeProject::preset($state)));
                        }
                    })
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code')
                    ->required(fn (Forms\Get $get) => $get('type') === 'code'),

                Forms\Components\Toggle::make('allow_run')
                    ->label('Students can run their code')
                    ->helperText("HTML, CSS, JavaScript, PHP and SQL run in each student's own browser, never on this server.")
                    ->default(true)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

                CodeEditor::make('starter_files')
                    ->label('Starter files')
                    ->helperText('Students get exactly these files. Double-click a tab to rename it.')
                    ->default(CodeProject::encode(CodeProject::preset('web')))
                    ->canManageFiles()
                    ->canRun()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

                CodeEditor::make('reference_files')
                    ->label('Reference solution')
                    ->helperText("Optional. Only you see this, next to each student's code when grading.")
                    ->starterFiles(fn (Forms\Get $get) => CodeProject::decode($get('starter_files'), []))
                    ->canManageFiles()
                    ->canRun()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

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
        } elseif ($data['type'] === 'code') {
            $data['options'] = [
                'language' => $data['code_language'] ?? 'plaintext',
                'files' => CodeProject::decode($data['starter_files'] ?? null, []),
                'allow_run' => (bool) ($data['allow_run'] ?? true),
            ];
            $reference = filled($data['reference_files'] ?? null) ? CodeProject::decode($data['reference_files'], []) : [];
            $data['correct_answer'] = CodeProject::length($reference) > 0 ? ['files' => $reference] : null;
        } else {
            $data['options'] = null;

            if ($data['type'] !== 'short_answer') {
                $data['correct_answer'] = null;
            }
        }

        if ($issues = (new QuizQuestion($data))->validationIssues()) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.options' => implode(' ', $issues)]);
        }
        unset($data['true_false_correct'], $data['code_language'], $data['allow_run'], $data['starter_files'], $data['reference_files']);

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
        if (($data['type'] ?? null) === 'code') {
            $question = new QuizQuestion($data);
            $data['code_language'] = $question->codeLanguage();
            $data['allow_run'] = $question->options['allow_run'] ?? true;
            $data['starter_files'] = CodeProject::encode($question->starterFiles());
            $data['reference_files'] = $question->referenceFiles() ? CodeProject::encode($question->referenceFiles()) : null;
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description($this->getOwnerRecord()->attempts()->exists() ? 'Questions are locked because students have started. Duplicate the quiz to make changes.' : 'Add questions and one correct answer key per choice question.')
            ->reorderable('order', fn () => ! $this->getOwnerRecord()->attempts()->exists())
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

                Tables\Columns\TextColumn::make('details')
                    ->label('Choices')
                    ->state(fn (QuizQuestion $record) => match ($record->type) {
                        'multiple_choice' => count($record->options ?? []).' choices',
                        'code' => QuizQuestion::CODE_LANGUAGES[$record->codeLanguage()] ?? $record->codeLanguage(),
                        default => '-',
                    })
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(QuizQuestion::TYPES),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Question')->visible(fn () => ! $this->getOwnerRecord()->attempts()->exists())
                    ->mutateFormDataUsing(fn (array $data) => $this->mutateFormDataBeforeCreate($data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => ! $this->getOwnerRecord()->attempts()->exists())
                    ->mutateRecordDataUsing(fn (array $data): array => $this->mutateRecordDataBeforeFill($data))
                    ->mutateFormDataUsing(fn (array $data) => $this->mutateFormDataBeforeSave($data)),
                Tables\Actions\DeleteAction::make()->visible(fn () => ! $this->getOwnerRecord()->attempts()->exists()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->visible(fn () => ! $this->getOwnerRecord()->attempts()->exists()),
                ]),
            ]);
    }
}
