<?php

namespace App\Filament\Resources;

use App\Filament\Pages\QuizBuilder;
use App\Filament\Pages\TakeQuiz;
use App\Filament\Resources\QuizResource\Pages;
use App\Filament\Resources\QuizResource\RelationManagers;
use App\Models\ClassRecordSheet;
use App\Models\ClassRecordUpload;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Services\ClassRecordSyncService;
use App\Services\WorkbookPreviewService;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form as FilamentForm;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuizResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Quiz::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Quizzes';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Quizzes & Activities';

    // We scope manually via the course offering -> semester -> school year -> campus chain.
    protected static bool $isScopedToTenant = false;

    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'delete',
            'delete_any',
            'replicate',
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('view_any_quiz') ?? false;
    }

    /**
     * Scope quizzes to the current tenant campus, and (unless super_admin)
     * to only the course offerings the logged-in instructor owns.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['courseOffering.course', 'courseOffering.semester.schoolYear']);

        if (auth()->user()?->hasRole('super_admin')) {
            return $query;
        }

        $campusId = filament()->getTenant()?->id;

        $query->whereHas('courseOffering.semester.schoolYear', function ($q) use ($campusId) {
            $q->where('campus_id', $campusId);
        });

        $query->whereHas('courseOffering', function ($q) {
            $q->where('user_id', auth()->id());
        });

        return $query;
    }

    /**
     * The direct URL students use to take this quiz. Sharing this link (e.g. on a
     * classroom projector or over LAN chat) is enough — a student who isn't logged
     * in yet is bounced to /login and redirected straight back here afterward.
     */
    public static function shareUrl(Quiz $record): string
    {
        return TakeQuiz::getUrl(['quiz' => $record->id], panel: 'admin', tenant: $record->campus);
    }

    /**
     * Shows the shareable link in a modal (always readable/selectable). The copy
     * button selects the input's text and tries navigator.clipboard first, falling
     * back to document.execCommand('copy') — the Clipboard API silently refuses to
     * work over plain HTTP on a LAN IP that isn't localhost, which is exactly how
     * this link is meant to be used on a classroom WiFi, so the fallback is what
     * actually does the copying in that case.
     *
     * Table row actions and page header actions are different (non-interchangeable)
     * Filament classes, so this builds whichever one the caller needs from the
     * same config — pass Tables\Actions\Action::class for a table, or
     * \Filament\Actions\Action::class for a resource page's header actions.
     */
    protected static function buildCopyLinkAction(string $actionClass)
    {
        return $actionClass::make('copyLink')
            ->label('Share Link')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (Quiz $record) => auth()->user()?->can('update', $record) ?? false)
            ->modalHeading('Share this quiz with your students')
            ->modalDescription('Anyone with this link who isn\'t signed in yet will be asked to log in first, then sent straight here. Already signed in as an instructor/admin in this browser? You\'ll see the Preview instead — open the link in a private/incognito window (or log out first) to see the student login.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->form(function (Quiz $record) {
                $inputId = 'quiz-share-link-'.$record->id;
                $copyScript = "let el=document.getElementById('{$inputId}');if(!el)return;el.focus();el.select();el.setSelectionRange(0,99999);"
                    .'let done=false;'
                    .'if(window.isSecureContext&&navigator.clipboard){navigator.clipboard.writeText(el.value).then(()=>{}).catch(()=>{});done=true;}'
                    .'try{document.execCommand("copy");}catch(e){}';

                return [
                    Forms\Components\TextInput::make('link')
                        ->label('Link')
                        ->default(static::shareUrl($record))
                        ->readOnly()
                        ->extraInputAttributes(['id' => $inputId, 'onclick' => 'this.select();'])
                        ->helperText('Click the field to select it, or use the copy button — then paste (Ctrl+V / Cmd+V) wherever you\'re sharing it.')
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('copy')
                                ->icon('heroicon-o-clipboard')
                                ->extraAttributes(['onclick' => $copyScript])
                        ),
                ];
            });
    }

    protected static function buildPreviewAction(string $actionClass)
    {
        return $actionClass::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('warning')
            // Matches TakeQuiz::canManage()'s requirement, so this never links to a 403.
            ->visible(fn (Quiz $record) => auth()->user()?->can('update', $record) ?? false)
            ->url(fn (Quiz $record) => static::shareUrl($record))
            ->openUrlInNewTab();
    }

    public static function copyLinkAction(): Tables\Actions\Action
    {
        return static::buildCopyLinkAction(Tables\Actions\Action::class);
    }

    public static function previewAction(): Tables\Actions\Action
    {
        return static::buildPreviewAction(Tables\Actions\Action::class);
    }

    public static function copyLinkHeaderAction(): Action
    {
        return static::buildCopyLinkAction(Action::class);
    }

    public static function previewHeaderAction(): Action
    {
        return static::buildPreviewAction(Action::class);
    }

    /**
     * Opens the drag-and-drop question builder — the same experience as the
     * evaluation Form Builder, just for quiz questions instead of form fields.
     */
    protected static function buildBuildAction(string $actionClass)
    {
        return $actionClass::make('build')
            ->label('Edit questions')
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->visible(fn (Quiz $record) => auth()->user()?->can('update', $record) ?? false)
            ->url(fn (Quiz $record) => QuizBuilder::getUrl(['record' => $record->id], panel: 'admin', tenant: $record->campus));
    }

    public static function buildAction(): Tables\Actions\Action
    {
        return static::buildBuildAction(Tables\Actions\Action::class);
    }

    public static function buildHeaderAction(): Action
    {
        return static::buildBuildAction(Action::class);
    }

    /**
     * Class record sheets for this quiz's course offering that are actually
     * usable (mapped + have a student ID column configured) — set up once
     * per course offering from the Class Records page.
     */
    protected static function mappedClassRecordSheets(Quiz $record)
    {
        return ClassRecordSheet::where('course_offering_id', $record->course_offering_id)
            ->where('student_id_header', '!=', '')
            ->when(! auth()->user()?->hasRole('super_admin'), fn ($q) => $q->whereHas('upload', fn ($u) => $u->where('uploaded_by', auth()->id())))
            ->with('upload')
            ->get();
    }

    /**
     * Writes this quiz's scores into the instructor's uploaded class record —
     * inserting a new raw-score column the first time, updating scores in
     * place on every sync after that (see ClassRecordSyncService).
     */
    protected static function buildSyncClassRecordAction(string $actionClass)
    {
        return $actionClass::make('syncClassRecord')
            ->label('Sync to Class Record')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Quiz $record) => in_array($record->type, ['quiz', 'activity'], true) && (auth()->user()?->can('update', $record) ?? false))
            ->modalHeading('Preview class record sync')->modalWidth('7xl')->stickyModalHeader()->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'workbook-preview-modal'])
            ->modalDescription('Only the latest attempt is considered, and only fully graded scores are written. Existing cells without a final score are left unchanged. A backup is kept before each sync.')
            ->modalSubmitActionLabel('Sync final scores')
            ->form(fn (Quiz $record) => [
                Forms\Components\Select::make('class_record_sheet_id')
                    ->label('Destination workbook and worksheet')
                    ->options(static::mappedClassRecordSheets($record)->mapWithKeys(fn (ClassRecordSheet $sheet) => [
                        $sheet->id => "{$sheet->upload->original_filename} — {$sheet->sheet_name} (".(ClassRecordUpload::PERIODS[$sheet->period] ?? $sheet->period).')',
                    ]))
                    ->default(fn () => static::mappedClassRecordSheets($record)->count() === 1 ? static::mappedClassRecordSheets($record)->first()->id : null)
                    ->required()->live()
                    ->helperText('Missing a worksheet? Open Class Records and map it to this course first.'),
                Forms\Components\Toggle::make('preview_after_sync')->label('Show proposed scores')->default(true)->live()
                    ->helperText('Preview only. The workbook changes when you click Sync final scores.'),
                Forms\Components\TextInput::make('preview_first_row')->label('First preview row')->numeric()->minValue(1)->default(1)->live(onBlur: true),
                Forms\Components\Placeholder::make('sync_preview')->hiddenLabel()
                    ->content(function (Forms\Get $get) use ($record) {
                        $sheet = static::mappedClassRecordSheets($record)->firstWhere('id', (int) $get('class_record_sheet_id'));
                        if (! $sheet) {
                            return 'Select a destination to check student matches and final scores.';
                        }

                        return view('filament.partials.workbook-preview', [
                            'preview' => app(WorkbookPreviewService::class)->preview(
                                $sheet, $record, proposed: (bool) $get('preview_after_sync'), firstRow: (int) $get('preview_first_row'),
                            ),
                        ]);
                    }),
            ])
            ->action(function (Quiz $record, array $data) {
                $sheets = static::mappedClassRecordSheets($record);

                if ($sheets->isEmpty()) {
                    Notification::make()
                        ->title('No class record mapped for this course offering')
                        ->body('Upload the class record and map this course offering from the Class Records page first.')
                        ->warning()
                        ->send();

                    return;
                }

                $sheet = isset($data['class_record_sheet_id'])
                    ? $sheets->firstWhere('id', (int) $data['class_record_sheet_id'])
                    : $sheets->first();

                if (! $sheet) {
                    Notification::make()->title('Choose a valid mapped worksheet')->warning()->send();

                    return;
                }

                try {
                    $result = (new ClassRecordSyncService)->sync($record, $sheet);
                } catch (\Throwable $e) {
                    Notification::make()->title('Sync failed')->body($e->getMessage())->danger()->send();

                    return;
                }

                $body = "{$result['matched']} student(s) updated.";

                if (! empty($result['no_score_yet'])) {
                    $body .= ' '.count($result['no_score_yet']).' enrolled student(s) have no score yet.';
                }

                if (! empty($result['unrecognized'])) {
                    $preview = implode(', ', array_slice($result['unrecognized'], 0, 5));
                    $body .= ' '.count($result['unrecognized'])." ID(s) in the sheet didn't match any enrolled student: {$preview}"
                        .(count($result['unrecognized']) > 5 ? '…' : '');
                }

                Notification::make()
                    ->title('Synced to class record')
                    ->body($body)
                    ->success()
                    ->send();
            });
    }

    public static function syncClassRecordAction(): Tables\Actions\Action
    {
        return static::buildSyncClassRecordAction(Tables\Actions\Action::class);
    }

    public static function syncClassRecordHeaderAction(): Action
    {
        return static::buildSyncClassRecordAction(Action::class);
    }

    /**
     * Clones the quiz and its questions into a fresh, unpublished draft in the
     * same course offering. Filament's ReplicateAction only copies the quiz's
     * own columns by default — the questions are copied explicitly here, and
     * the copy is reset to draft/unscheduled so it doesn't accidentally go
     * live with stale dates or notify_minutes_before already "used up".
     */
    public static function duplicateAction(): Tables\Actions\ReplicateAction
    {
        return Tables\Actions\ReplicateAction::make()
            ->label('Duplicate')
            ->icon('heroicon-o-document-duplicate')
            ->excludeAttributes([
                'is_published',
                'available_from',
                'available_until',
                'notify_minutes_before',
                'notified_at',
                'assign_to_all',
            ])
            ->beforeReplicaSaved(function (Quiz $replica) {
                $replica->title = "{$replica->title} (Copy)";
                $replica->is_published = false;
                $replica->assign_to_all = true;
            })
            ->afterReplicaSaved(function (Quiz $original, Quiz $replica) {
                foreach ($original->questions as $question) {
                    $replica->questions()->create(
                        $question->only(['type', 'question_text', 'points', 'order', 'options', 'correct_answer'])
                    );
                }
            })
            ->successRedirectUrl(fn (Quiz $replica) => static::getUrl('edit', ['record' => $replica]));
    }

    /**
     * Course offerings the current user is allowed to attach a quiz to.
     */
    protected static function courseOfferingOptions(): array
    {
        $campusId = filament()->getTenant()?->id;
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return CourseOffering::query()
            ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campusId))
            ->when(! $isSuperAdmin, fn ($q) => $q->where('user_id', auth()->id()))
            ->with(['course', 'semester.schoolYear'])
            ->get()
            ->mapWithKeys(fn (CourseOffering $co) => [
                $co->id => "{$co->display_name} — {$co->semester?->name} ({$co->semester?->schoolYear?->name})",
            ])
            ->toArray();
    }

    public static function form(FilamentForm $form): FilamentForm
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Quiz Details')
                    ->schema([
                        Forms\Components\Select::make('course_offering_id')
                            ->label('Course and section')
                            ->disabled(fn (?Quiz $record) => $record?->attempts()->exists())
                            ->options(fn () => static::courseOfferingOptions())
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Radio::make('type')
                            ->options(Quiz::TYPES)
                            ->default('quiz')
                            ->live()
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('instructions')
                            ->label('Instructions')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('auto_sync_class_record')
                            ->label('Auto-sync to class record')->default(true)
                            ->visible(fn (Forms\Get $get) => in_array($get('type'), ['quiz', 'activity'], true))
                            ->helperText('Automatically writes final grades and opening dates after grading. Uses the linked worksheet, or the only worksheet mapped by the instructor or a super-admin. With multiple grading periods, sync manually once to choose the destination.')->columnSpanFull(),
                        Forms\Components\Placeholder::make('auto_sync_status')->label('Class record sync status')
                            ->visible(fn (?Quiz $record) => $record && in_array($record->type, ['quiz', 'activity'], true))
                            ->content(function (Quiz $record) {
                                $record = $record->fresh();
                                if (! $record->auto_sync_class_record) {
                                    return 'Automatic syncing is off. You can still sync manually.';
                                }

                                return $record->class_record_sync_error
                                    ? 'Needs attention: '.$record->class_record_sync_error.' Grades are saved. Fix the issue, then use Sync to Class Record to retry.'
                                    : ($record->class_record_synced_at ? 'Last synced: '.$record->class_record_synced_at->format('M j, Y g:i A') : 'Enabled — new or updated final grades sync automatically. Use Sync to Class Record to sync existing grades now.');
                            })->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Timing & Attempts')
                    ->schema([
                        Forms\Components\Radio::make('timing_mode')
                            ->label('Timing')
                            ->options(Quiz::TIMING_MODES)
                            ->default('duration')
                            ->live()
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn (Forms\Get $get) => $get('type') !== 'activity'),

                        Forms\Components\TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Forms\Get $get) => $get('type') !== 'activity' && $get('timing_mode') !== 'scheduled')
                            ->required(fn (Forms\Get $get) => $get('type') !== 'activity' && $get('timing_mode') !== 'scheduled')
                            ->helperText('Each student is auto-submitted this many minutes after they click Start.'),

                        Forms\Components\TextInput::make('max_attempts')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),

                        Forms\Components\TextInput::make('passing_score')
                            ->label('Passing Score')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('No threshold')
                            ->helperText(fn (?Quiz $record) => $record
                                ? "Out of {$record->total_points} total points. Leave blank for no pass/fail threshold."
                                : 'Set once questions are added, so you know the total points available. Leave blank for no pass/fail threshold.'),

                        Forms\Components\DateTimePicker::make('available_from')
                            ->label(fn (Forms\Get $get) => $get('timing_mode') === 'scheduled' && $get('type') !== 'activity' ? 'Opens At' : 'Available From')
                            ->required(fn (Forms\Get $get) => $get('timing_mode') === 'scheduled' && $get('type') !== 'activity')
                            ->helperText(fn (Forms\Get $get) => $get('timing_mode') === 'scheduled' && $get('type') !== 'activity'
                                ? 'Students land on a waiting screen with a live countdown until this exact moment.'
                                : null),

                        Forms\Components\DateTimePicker::make('available_until')
                            ->rule('after:data.available_from', fn (Forms\Get $get) => filled($get('available_from')))
                            ->label(fn (Forms\Get $get) => $get('timing_mode') === 'scheduled' && $get('type') !== 'activity' ? 'Closes At' : 'Available Until')
                            ->required(fn (Forms\Get $get) => $get('timing_mode') === 'scheduled' && $get('type') !== 'activity')
                            ->helperText(fn (Forms\Get $get) => match (true) {
                                $get('timing_mode') === 'scheduled' && $get('type') !== 'activity' => 'Everyone is cut off and auto-submitted at this exact moment, no matter when they started.',
                                $get('type') === 'activity' => 'Deadline for submitting this activity.',
                                default => 'Last moment a student can click Start.',
                            }),

                        Forms\Components\Select::make('notify_minutes_before')
                            ->label('Notify Students Before It Starts')
                            ->options(collect(Quiz::NOTIFY_OPTIONS)->except('')->toArray())
                            ->placeholder("Don't notify")
                            ->native(false)
                            ->visible(fn (Forms\Get $get) => filled($get('available_from')))
                            ->helperText('Sends each enrolled student a database notification (bell icon) this far in advance.'),

                        Forms\Components\Toggle::make('shuffle_questions')
                            ->label('Shuffle question order per student'),

                        Forms\Components\Toggle::make('is_published')
                            ->label('Published (visible to students)')
                            ->disabled(fn (?Quiz $record) => ! $record)
                            ->helperText('Save a draft, add questions and answer keys, then publish. Incomplete quizzes cannot be published.')
                            ->default(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('courseOffering.display_name')
                    ->label('Course Offering')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('courseOffering', fn ($q) => $q->where('section', 'like', "%{$search}%")->orWhereHas('course', fn ($course) => $course->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Quiz::TYPES[$state] ?? $state),

                Tables\Columns\TextColumn::make('questions_count')
                    ->counts('questions')
                    ->label('Questions'),

                Tables\Columns\TextColumn::make('questions_sum_points')
                    ->sum('questions', 'points')
                    ->label('Highest Possible Score')
                    ->sortable(),

                Tables\Columns\TextColumn::make('attempts_count')
                    ->counts('attempts')
                    ->label('Attempts'),

                Tables\Columns\TextColumn::make('timing_mode')
                    ->label('Timing')
                    ->badge()
                    ->color(fn (string $state) => $state === 'scheduled' ? 'warning' : 'gray')
                    ->formatStateUsing(fn (string $state, Quiz $record) => $state === 'scheduled'
                        ? 'Scheduled'
                        : ($record->duration_minutes ? "{$record->duration_minutes} min" : 'Untimed')),

                Tables\Columns\TextColumn::make('is_published')
                    ->label('Visibility')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Published' : 'Draft')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('pending_grading_count')
                    ->counts(['attempts as pending_grading_count' => fn (Builder $query) => $query->where('status', 'submitted')])
                    ->label('To grade')->badge()->color('warning'),

                Tables\Columns\TextColumn::make('available_until')
                    ->label('Deadline')
                    ->dateTime('M j, Y g:ia')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(Quiz::TYPES),
                Tables\Filters\TernaryFilter::make('is_published'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                static::buildAction(),
                Tables\Actions\EditAction::make()->label('Settings & results'),
                Tables\Actions\ActionGroup::make([
                    static::previewAction(),
                    static::copyLinkAction(),
                    static::syncClassRecordAction(),
                    static::duplicateAction(),
                    Tables\Actions\DeleteAction::make(),
                ])->label('More')->button(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Create your first quiz')
            ->emptyStateDescription('Save a draft, add questions, preview it, then publish for your students.')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\QuestionsRelationManager::class,
            RelationManagers\StudentsRelationManager::class,
            RelationManagers\AttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizzes::route('/'),
            'create' => Pages\CreateQuiz::route('/create'),
            'edit' => Pages\EditQuiz::route('/{record}/edit'),
        ];
    }
}
