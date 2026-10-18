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

                Forms\Components\Toggle::make('allow_student_files')
                    ->label('Students can create files and folders')
                    ->default(true)
                    ->helperText('Like a VS Code workspace: students add, rename and delete files and folders. Turn off to keep students to exactly your starter files.')
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

                Forms\Components\Toggle::make('autocomplete')
                    ->label('Code suggestions (autocomplete)')
                    ->helperText('Shows keyword and tag suggestions while typing. Leave off when the exam tests what students remember.')
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

                Forms\Components\Toggle::make('database_enabled')
                    ->label('Give each student a MySQL database (phpMyAdmin)')
                    ->helperText('Students open phpMyAdmin from the assessment and work in their own database on the separate student server. It is exported with their answer when they submit.')
                    ->live()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

                CodeEditor::make('database_starter')
                    ->label('Starter SQL')
                    ->helperText("Optional. Runs in each student's new database, for example to create tables and sample rows.")
                    ->starterFiles([['name' => 'setup.sql', 'content' => '']])
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code' && $get('database_enabled')),

                CodeEditor::make('reference_files')
                    ->label('Reference solution')
                    ->helperText("Optional. Only you see this, next to each student's code when grading.")
                    ->starterFiles(fn (Forms\Get $get) => CodeProject::decode($get('starter_files'), []))
                    ->canManageFiles()
                    ->canRun()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code'),

                Forms\Components\Toggle::make('auto_check')
                    ->label('Check answers automatically')
                    ->helperText("Runs each student's code and your reference solution on the same test cases and suggests a score from how many outputs match. Only the output is compared, so students may write the code their own way. Works for PHP, web page and SQL questions.")
                    ->live()
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code' && ! $get('database_enabled')),

                Forms\Components\Repeater::make('check_cases')
                    ->label('Test cases')
                    ->helperText('The score is the share of test cases whose output matches your reference solution; spacing never counts. With no test cases, the code is run once as it is.')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->label('Name')
                            ->placeholder('Optional, for example "Total for 3 items"')
                            ->maxLength(80),
                        Forms\Components\Textarea::make('input')
                            ->label('Input')
                            ->rows(2)
                            ->maxLength(QuizQuestion::MAX_CHECK_INPUT)
                            ->extraInputAttributes(['class' => 'font-mono'])
                            ->helperText(fn (Forms\Get $get) => match (CodeProject::runner(CodeProject::decode($get('../../starter_files'), []))) {
                                'php' => 'Optional. The page to open, with a query string if needed, such as index.php?n=5. Add form fields on the next lines as name=value to send them as a POST. Leave empty to open the first page.',
                                'sql' => "Optional. A query run after the student's SQL, such as SELECT * FROM products ORDER BY id. Leave empty to compare the rows the student's own queries return.",
                                'web' => "Optional. JavaScript run on the page once it loads; its result is compared, for example document.querySelector('#total').textContent. Leave empty to compare the page's text and console output.",
                                default => 'Automatic checking works for PHP, web page and SQL questions.',
                            }),
                    ])
                    ->defaultItems(0)
                    ->maxItems(QuizQuestion::MAX_CHECK_CASES)
                    ->addActionLabel('Add test case')
                    ->collapsible()
                    ->itemLabel(fn (array $state) => $state['label'] ?? null)
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code' && $get('auto_check') && ! $get('database_enabled')),

                Forms\Components\Toggle::make('check_ignore_case')
                    ->label('Ignore upper and lower case when comparing')
                    ->columnSpanFull()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'code' && $get('auto_check') && ! $get('database_enabled')),

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
                'folders' => CodeProject::decodeFolders($data['starter_files'] ?? null),
                'allow_run' => (bool) ($data['allow_run'] ?? true),
                'allow_student_files' => (bool) ($data['allow_student_files'] ?? true),
                'autocomplete' => (bool) ($data['autocomplete'] ?? false),
                'database' => [
                    'enabled' => (bool) ($data['database_enabled'] ?? false),
                    'starter_sql' => CodeProject::decode($data['database_starter'] ?? null, [['name' => 'setup.sql', 'content' => '']])[0]['content'],
                ],
                'checks' => [
                    'enabled' => (bool) ($data['auto_check'] ?? false) && ! ($data['database_enabled'] ?? false),
                    'cases' => collect($data['check_cases'] ?? [])
                        ->map(fn (array $case) => ['label' => trim((string) ($case['label'] ?? '')), 'input' => (string) ($case['input'] ?? '')])
                        ->values()
                        ->all(),
                    'ignore_case' => (bool) ($data['check_ignore_case'] ?? false),
                ],
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
        unset($data['true_false_correct'], $data['code_language'], $data['allow_run'], $data['starter_files'], $data['reference_files'], $data['database_enabled'], $data['database_starter'], $data['allow_student_files'], $data['autocomplete'], $data['auto_check'], $data['check_cases'], $data['check_ignore_case']);

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
            $data['database_enabled'] = $question->hasDatabase();
            $data['database_starter'] = CodeProject::encode([['name' => 'setup.sql', 'content' => $question->databaseStarterSql()]]);
            $data['starter_files'] = $question->starterProject();
            $data['allow_student_files'] = $question->studentsCanManageFiles();
            $data['autocomplete'] = $question->allowsAutocomplete();
            $data['reference_files'] = $question->referenceFiles() ? CodeProject::encode($question->referenceFiles()) : null;
            $data['auto_check'] = (bool) ($question->options['checks']['enabled'] ?? false);
            $data['check_cases'] = $question->options['checks']['cases'] ?? [];
            $data['check_ignore_case'] = $question->checkIgnoresCase();
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
