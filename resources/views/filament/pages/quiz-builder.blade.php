<x-filament-panels::page>
    @php($questionsLocked = $quizRecord->attempts()->exists())
    <div x-data="{
            dirty: false,
            draggedType: null,
            draggedQuestionId: null,
            dragOverIndex: null,
            isDraggingFromPalette: false,

            startDragFromPalette(e, type) {
                this.draggedType = type;
                this.isDraggingFromPalette = true;
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', type);
            },

            startDragQuestion(e, questionId) {
                this.draggedQuestionId = questionId;
                this.isDraggingFromPalette = false;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', questionId);
                const card = e.target.closest('[data-question-id]');
                if (card) card.classList.add('qb-dragging');
            },

            getCanvasCards() {
                const canvas = this.$refs.canvas;
                return canvas ? canvas.querySelectorAll('[data-question-id]') : [];
            },

            getNearestIndex(e) {
                const cards = this.getCanvasCards();
                if (cards.length === 0) return -1;

                const mouseY = e.clientY;
                let closestIdx = cards.length;
                let closestDist = Infinity;

                cards.forEach((card, idx) => {
                    const rect = card.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    const dist = Math.abs(mouseY - midY);
                    if (dist < closestDist) {
                        closestDist = dist;
                        closestIdx = mouseY < midY ? idx : idx + 1;
                    }
                });

                return Math.min(closestIdx, cards.length);
            },

            onDragOverCanvas(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = this.isDraggingFromPalette ? 'copy' : 'move';
                const idx = this.getNearestIndex(e);
                const cards = this.getCanvasCards();
                this.dragOverIndex = idx < cards.length ? idx : (cards.length > 0 ? cards.length - 1 : -1);
            },

            onDragLeave(e) {
                if (!e.currentTarget.contains(e.relatedTarget)) {
                    this.dragOverIndex = null;
                }
            },

            async onDropCanvas(e) {
                e.preventDefault();
                this.dragOverIndex = null;

                if (this.isDraggingFromPalette && this.draggedType) {
                    const cards = this.getCanvasCards();
                    const nearIdx = this.getNearestIndex(e);
                    let afterQuestionId = null;
                    if (cards.length > 0 && nearIdx > 0) {
                        afterQuestionId = parseInt(cards[Math.min(nearIdx - 1, cards.length - 1)].dataset.questionId);
                    }
                    await $wire.call('addQuestion', this.draggedType, afterQuestionId);
                    this.draggedType = null;
                    this.isDraggingFromPalette = false;
                    document.querySelectorAll('.qb-dragging').forEach(el => el.classList.remove('qb-dragging'));
                    return;
                }

                if (this.draggedQuestionId) {
                    const cards = this.getCanvasCards();
                    const orderedIds = Array.from(cards).map(el => parseInt(el.dataset.questionId));
                    const nearIdx = this.getNearestIndex(e);
                    const fromIdx = orderedIds.indexOf(this.draggedQuestionId);

                    if (fromIdx !== -1 && nearIdx !== fromIdx && nearIdx !== fromIdx + 1) {
                        const [moved] = orderedIds.splice(fromIdx, 1);
                        const insertAt = nearIdx > fromIdx ? nearIdx - 1 : nearIdx;
                        orderedIds.splice(insertAt, 0, moved);
                        await $wire.call('reorderQuestions', orderedIds);
                    }
                }

                this.draggedQuestionId = null;
                this.draggedType = null;
                this.isDraggingFromPalette = false;
                document.querySelectorAll('.qb-dragging').forEach(el => el.classList.remove('qb-dragging'));
            },

            onDragEnd(e) {
                this.draggedType = null;
                this.draggedQuestionId = null;
                this.dragOverIndex = null;
                this.isDraggingFromPalette = false;
                document.querySelectorAll('.qb-dragging').forEach(el => el.classList.remove('qb-dragging'));
            },

            getTypeIcon(type) {
                const icons = {
                    multiple_choice: '◉', true_false: '✓', short_answer: 'T', essay: '¶', file_upload: '📎', code: '</>',
                };
                return icons[type] || '?';
            },

            getTypeLabel(type) {
                const labels = {
                    multiple_choice: 'Multiple Choice', true_false: 'True / False', short_answer: 'Short Answer',
                    essay: 'Essay', file_upload: 'File Upload', code: 'Code',
                };
                return labels[type] || type;
            },
        }" class="qb-container" x-on:input="dirty = true" x-on:change="dirty = true"
        x-on:question-saved.window="dirty = false"
        x-on:question-selected.window="if (window.innerWidth <= 640) $nextTick(() => $refs.properties.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
        x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">

        {{-- ═══════ LEFT SIDEBAR — QUESTION TYPE PALETTE ═══════ --}}
        <div class="qb-sidebar">
            <div class="qb-sidebar-header">
                <h3>Question Types</h3>
                <p>Click to add. Drag or use arrows to reorder.</p>
            </div>

            @foreach (static::getQuestionTypeCategories() as $category => $types)
                <div class="qb-category">
                    <h4 class="qb-category-title">{{ $category }}</h4>
                    <div class="qb-palette-grid">
                        @foreach ($types as $typeKey => $typeInfo)
                            <button @disabled($questionsLocked) type="button" class="qb-palette-item" draggable="{{ $questionsLocked ? 'false' : 'true' }}"
                                x-on:dragstart="startDragFromPalette($event, '{{ $typeKey }}')"
                                x-on:dragend="onDragEnd($event)"
                                wire:click="addQuestion('{{ $typeKey }}')" title="Click or drag to add">
                                <span class="qb-palette-icon">{{ $typeInfo['icon'] }}</span>
                                <span class="qb-palette-label">{{ $typeInfo['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ═══════ CENTER — CANVAS DROP ZONE ═══════ --}}
        <div class="qb-canvas-wrapper">
            <div class="qb-canvas-header">
                <div class="qb-canvas-title">
                    <h2>{{ $quizRecord->title }}</h2>
                    <span class="qb-question-count">{{ count($questions) }} {{ Str::plural('question', count($questions)) }} · {{ collect($questions)->sum('points') }} pts total</span>
                </div>
                <div class="qb-canvas-actions">
                    <a href="{{ \App\Filament\Resources\QuizResource::getUrl('edit', ['record' => $quizRecord]) }}" class="qb-btn qb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Settings
                    </a>
                    <a href="{{ \App\Filament\Resources\QuizResource::shareUrl($quizRecord) }}" target="_blank" class="qb-btn qb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        Preview
                    </a>
                </div>
            </div>

            <div class="qb-guidance">
                @if ($questionsLocked)
                    Students have started this {{ strtolower($quizRecord->kindLabel()) }}. Questions are locked; duplicate it to make a revised version.
                @else
                    {{ $quizRecord->is_published ? 'Published' : 'Draft' }} · Edit a question, save its answer key, then preview. Publish from Settings when ready.
                @endif
                <span x-show="dirty" x-cloak role="status"> You have unsaved changes.</span>
            </div>
            @if ($errors->any())
                <div class="qb-validation" role="alert">{{ $errors->first() }}</div>
            @endif
            <div class="qb-canvas" x-ref="canvas"
                x-on:dragover.prevent="onDragOverCanvas($event)"
                x-on:dragleave="onDragLeave($event)"
                x-on:drop="onDropCanvas($event)"
                x-bind:class="{ 'qb-canvas-dragover': dragOverIndex !== null && (isDraggingFromPalette || draggedQuestionId) }">
                @if (count($questions) === 0)
                    <div class="qb-empty-state">
                        <div class="qb-empty-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3>No questions yet</h3>
                        <p>Drag a question type from the sidebar or click one to add</p>
                    </div>
                @endif

                @foreach ($questions as $index => $question)
                    <div class="qb-question-card {{ $selectedQuestionId === $question['id'] ? 'qb-question-selected' : '' }}"
                        wire:key="builder-question-{{ $question['id'] }}" tabindex="0" role="button"
                        x-on:keydown.enter.prevent="$wire.selectQuestion({{ $question['id'] }})"
                        data-question-id="{{ $question['id'] }}" draggable="{{ $questionsLocked ? 'false' : 'true' }}"
                        x-on:dragstart="startDragQuestion($event, {{ $question['id'] }})"
                        x-on:dragend="onDragEnd($event)"
                        x-on:click="$wire.call('selectQuestion', {{ $question['id'] }})">
                        <div class="qb-field-drag-handle" title="Drag to reorder">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" />
                            </svg>
                        </div>

                        <div class="qb-field-type-badge" x-text="getTypeIcon('{{ $question['type'] }}')"></div>

                        <div class="qb-field-info">
                            <div class="qb-field-label">
                                {{ $index + 1 }}. {{ Str::limit($question['question_text'], 60) }}
                            </div>
                            <div class="qb-field-meta">
                                <span class="qb-field-type-text" x-text="getTypeLabel('{{ $question['type'] }}')"></span>
                                <span class="qb-points-badge">{{ $question['points'] }} {{ Str::plural('pt', $question['points']) }}</span>
                            </div>
                        </div>

                        <div class="qb-field-actions">
                            <button type="button" wire:click.stop="moveQuestion({{ $question['id'] }}, -1)" @disabled($questionsLocked || $loop->first) class="qb-field-action-btn" aria-label="Move question up" title="Move up">↑</button>
                            <button type="button" wire:click.stop="moveQuestion({{ $question['id'] }}, 1)" @disabled($questionsLocked || $loop->last) class="qb-field-action-btn" aria-label="Move question down" title="Move down">↓</button>
                            <button @disabled($questionsLocked) wire:click.stop="duplicateQuestion({{ $question['id'] }})" class="qb-field-action-btn" title="Duplicate">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                            </button>
                            <button @disabled($questionsLocked) wire:click.stop="deleteQuestion({{ $question['id'] }})" wire:confirm="Delete this question?" class="qb-field-action-btn qb-field-action-delete" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ═══════ RIGHT SIDEBAR — PROPERTIES PANEL ═══════ --}}
        <div x-ref="properties" class="qb-properties {{ $showProperties ? 'qb-properties-open' : '' }}">
            @if ($showProperties && $selectedQuestionId)
                <div class="qb-properties-header">
                    <h3>Question Properties</h3>
                    <button aria-label="Save and close question" title="Save and close" wire:click="closeProperties" class="qb-properties-close">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="qb-properties-body" wire:key="properties-{{ $selectedQuestionId }}">
                    <div class="qb-prop-group">
                        <label class="qb-prop-label">Question Type</label>
                        <div class="qb-prop-type-display">
                            <span x-text="getTypeIcon('{{ $selectedQuestionData['type'] ?? '' }}')"></span>
                            <span x-text="getTypeLabel('{{ $selectedQuestionData['type'] ?? '' }}')"></span>
                        </div>
                        <span class="qb-prop-help">Delete and re-add to change the type.</span>
                    </div>

                    <div class="qb-prop-group">
                        <label class="qb-prop-label" for="prop-text">Question Text <span class="text-red-500">*</span></label>
                        <textarea @disabled($questionsLocked) id="prop-text" class="qb-prop-input" rows="3"
                            wire:model.live.debounce.300ms="selectedQuestionData.question_text"></textarea>
                    </div>

                    <div class="qb-prop-group">
                        <label class="qb-prop-label" for="prop-points">Points</label>
                        <input @disabled($questionsLocked) type="number" id="prop-points" min="1" class="qb-prop-input"
                            wire:model.live.debounce.300ms="selectedQuestionData.points">
                    </div>

                    @if (($selectedQuestionData['type'] ?? '') === 'multiple_choice')
                        <div class="qb-prop-group">
                            <label class="qb-prop-label">Choices &amp; Answer Key</label>
                            <span class="qb-prop-help">Mark exactly one correct choice. Students select one answer.</span>
                            <div class="qb-options-list">
                                @foreach (($selectedQuestionData['options'] ?? []) as $optIndex => $option)
                                    <div class="qb-option-row" wire:key="option-{{ $optIndex }}">
                                        <label class="qb-option-correct" title="Mark as correct answer">
                                            <input @disabled($questionsLocked) type="checkbox" wire:model="selectedQuestionData.options.{{ $optIndex }}.is_correct">
                                        </label>
                                        <input @disabled($questionsLocked) type="text" class="qb-prop-input qb-option-input"
                                            wire:model="selectedQuestionData.options.{{ $optIndex }}.label" placeholder="Choice text">
                                        <button @disabled($questionsLocked) wire:click="removeOption({{ $optIndex }})" class="qb-option-remove" title="Remove">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button @disabled($questionsLocked) wire:click="addOption" class="qb-add-option-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Add Choice
                            </button>
                        </div>
                    @elseif (($selectedQuestionData['type'] ?? '') === 'true_false')
                        <div class="qb-prop-group">
                            <label class="qb-prop-label">Answer Key</label>
                            <div class="qb-tf-options">
                                <label class="qb-tf-option">
                                    <input @disabled($questionsLocked) type="radio" wire:model="selectedQuestionData.true_false_correct" value="true">
                                    <span>True</span>
                                </label>
                                <label class="qb-tf-option">
                                    <input @disabled($questionsLocked) type="radio" wire:model="selectedQuestionData.true_false_correct" value="false">
                                    <span>False</span>
                                </label>
                            </div>
                        </div>
                    @elseif (($selectedQuestionData['type'] ?? '') === 'short_answer')
                        <div class="qb-prop-group">
                            <label class="qb-prop-label">Accepted Answers (Answer Key)</label>
                            <span class="qb-prop-help">Any of these will be marked correct, case-insensitive.</span>
                            <div class="qb-options-list">
                                @foreach (($selectedQuestionData['correct_answer'] ?? []) as $ansIndex => $answer)
                                    <div class="qb-option-row" wire:key="answer-{{ $ansIndex }}">
                                        <input @disabled($questionsLocked) type="text" class="qb-prop-input qb-option-input"
                                            wire:model="selectedQuestionData.correct_answer.{{ $ansIndex }}" placeholder="Accepted answer">
                                        <button @disabled($questionsLocked) wire:click="removeAcceptedAnswer({{ $ansIndex }})" class="qb-option-remove" title="Remove">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button @disabled($questionsLocked) wire:click="addAcceptedAnswer" class="qb-add-option-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Add Accepted Answer
                            </button>
                        </div>
                    @elseif (($selectedQuestionData['type'] ?? '') === 'code')
                        @php($codeLanguage = $selectedQuestionData['code_language'] ?? 'web')
                        <div class="qb-prop-group">
                            <label class="qb-prop-label" for="prop-code-language">Language</label>
                            <select @disabled($questionsLocked) id="prop-code-language" class="qb-prop-input"
                                wire:model.live="selectedQuestionData.code_language">
                                @foreach (\App\Models\QuizQuestion::CODE_LANGUAGES as $languageKey => $languageLabel)
                                    <option value="{{ $languageKey }}">{{ $languageLabel }}</option>
                                @endforeach
                            </select>
                            <span class="qb-prop-help">Picks the starter files. Add, rename (double-click) or delete files below; each file is highlighted by its extension.</span>
                        </div>
                        <div class="qb-prop-group">
                            <label class="qb-tf-option">
                                <input @disabled($questionsLocked) type="checkbox" wire:model="selectedQuestionData.allow_run">
                                <span>Students can run their code</span>
                            </label>
                            <span class="qb-prop-help">HTML, CSS, JavaScript, PHP and SQL run in each student's own browser, never on this server. Other languages can only be written.</span>
                        </div>
                        <div class="qb-prop-group">
                            <label class="qb-tf-option">
                                <input @disabled($questionsLocked) type="checkbox" wire:model="selectedQuestionData.allow_student_files">
                                <span>Students can create files and folders</span>
                            </label>
                            <span class="qb-prop-help">Like a VS Code workspace: students add, rename and delete files and folders. Otherwise they edit exactly your starter files.</span>
                        </div>
                        <div class="qb-prop-group">
                            <label class="qb-tf-option">
                                <input @disabled($questionsLocked) type="checkbox" wire:model="selectedQuestionData.autocomplete">
                                <span>Code suggestions (autocomplete)</span>
                            </label>
                            <span class="qb-prop-help">Shows keyword and tag suggestions while typing. Leave off when the exam tests what students remember.</span>
                        </div>
                        <div class="qb-prop-group">
                            <label class="qb-tf-option">
                                <input @disabled($questionsLocked) type="checkbox" wire:model.live="selectedQuestionData.database_enabled">
                                <span>Give each student a MySQL database (phpMyAdmin)</span>
                            </label>
                            <span class="qb-prop-help">Students open phpMyAdmin from the {{ strtolower($quizRecord->kindLabel()) }} and work in their own database on a separate student server, never your system's database. It is exported with their answer when they submit.</span>
                            @if (($selectedQuestionData['database_enabled'] ?? false) && ! app(\App\Services\StudentDatabase\StudentDbServer::class)->isInstalled())
                                <span class="qb-prop-help" style="color:#b45309">Not set up on this computer yet. Run <code>php artisan classroom:student-db install</code> once.</span>
                            @endif
                        </div>
                        @if ($selectedQuestionData['database_enabled'] ?? false)
                            <div class="qb-prop-group">
                                <span class="qb-prop-label">Starter SQL</span>
                                <span class="qb-prop-help">Optional. Runs in each student's new database, for example to create tables and sample rows.</span>
                                <x-code-editor state-path="selectedQuestionData.database_starter" :read-only="$questionsLocked"
                                    min-height="6rem" max-height="16rem" wire:key="database-starter-{{ $selectedQuestionId }}" />
                            </div>
                        @endif
                        <div class="qb-prop-group">
                            <span class="qb-prop-label">Starter Files</span>
                            <span class="qb-prop-help">Students get exactly these files and edit them; they cannot add or remove files.</span>
                            <x-code-editor state-path="selectedQuestionData.starter_files"
                                :read-only="$questionsLocked" :can-manage-files="! $questionsLocked" can-run autocomplete
                                min-height="8rem" max-height="24rem"
                                wire:key="starter-{{ $selectedQuestionId }}-{{ $codeLanguage }}" />
                        </div>
                        <div class="qb-prop-group">
                            <span class="qb-prop-label">Reference Solution</span>
                            <span class="qb-prop-help">Optional. Only you see this, next to each student's code while grading.</span>
                            <x-code-editor state-path="selectedQuestionData.reference_files"
                                :starter-files="\App\Support\CodeProject::decode($selectedQuestionData['starter_files'] ?? null, [])"
                                :read-only="$questionsLocked" :can-manage-files="! $questionsLocked" can-run autocomplete
                                min-height="8rem" max-height="24rem"
                                wire:key="reference-{{ $selectedQuestionId }}-{{ $codeLanguage }}" />
                        </div>
                    @else
                        <div class="qb-prop-group">
                            <div class="qb-prop-readonly">
                                {{ $selectedQuestionData['type'] === 'essay' ? 'Essay answers' : 'File uploads' }} are always graded manually in the quiz's Attempts tab — no answer key needed here.
                            </div>
                        </div>
                    @endif
                </div>

                <div class="qb-properties-footer">
                    <button @disabled($questionsLocked) wire:click="saveQuestionProperties" wire:loading.attr="disabled" class="qb-btn qb-btn-primary qb-btn-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        Save Changes
                    </button>
                </div>
            @else
                <div class="qb-properties-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    <p>Select a question to edit its properties</p>
                </div>
            @endif
        </div>

        {{-- Toast notification --}}
        <div x-data="{ show: false, message: '' }"
            x-on:notify.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
            x-show="show" x-cloak x-transition.opacity class="qb-toast">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <span x-text="message"></span>
        </div>
    </div>

    @push('styles')
        <style>
            .qb-container { display: flex; height: calc(100vh - 64px); margin: -1.5rem; overflow: hidden; background: #f3f4f6; }

            .qb-sidebar { width: 260px; min-width: 260px; background: #ffffff; border-right: 1px solid #e5e7eb; overflow-y: auto; display: flex; flex-direction: column; }
            .qb-sidebar-header { padding: 20px 16px 12px; border-bottom: 1px solid #f3f4f6; }
            .qb-sidebar-header h3 { font-size: 1rem; font-weight: 700; color: #111827; margin: 0; }
            .qb-sidebar-header p { font-size: 0.75rem; color: #9ca3af; margin: 4px 0 0; }
            .qb-category { padding: 12px 16px 8px; }
            .qb-category-title { font-size: 0.675rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin: 0 0 8px; }
            .qb-palette-grid { display: flex; flex-direction: column; gap: 4px; }
            .qb-palette-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; cursor: grab; transition: all 0.15s ease; border: 1px solid transparent; font-size: 0.8125rem; color: #374151; user-select: none; }
            .qb-palette-item:hover { background: #f5f0fa; border-color: #d4b8e8; color: #340167; }
            .qb-palette-item:active { cursor: grabbing; transform: scale(0.97); }
            .qb-palette-icon { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: #f9fafb; border-radius: 6px; font-size: 0.75rem; flex-shrink: 0; border: 1px solid #e5e7eb; }
            .qb-palette-item:hover .qb-palette-icon { background: #f0e4f7; border-color: #8a5aad; }
            .qb-palette-label { font-weight: 500; }

            .qb-canvas-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
            .qb-canvas-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: #ffffff; border-bottom: 1px solid #e5e7eb; }
            .qb-canvas-title h2 { font-size: 1.125rem; font-weight: 700; color: #111827; margin: 0; }
            .qb-question-count { font-size: 0.75rem; color: #9ca3af; }
            .qb-canvas-actions { display: flex; gap: 8px; }
            .qb-canvas { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 8px; min-height: 200px; transition: background 0.2s ease; }
            .qb-canvas-dragover { background: #f5f0fa !important; outline: 2px dashed #340167; outline-offset: -8px; border-radius: 12px; }

            .qb-empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #9ca3af; gap: 8px; padding: 60px; }
            .qb-empty-state h3 { font-size: 1.125rem; font-weight: 600; color: #6b7280; margin: 0; }
            .qb-empty-state p { margin: 0; font-size: 0.875rem; }
            .qb-empty-icon { color: #d1d5db; }

            .qb-question-card { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all 0.15s ease; user-select: none; position: relative; }
            .qb-question-card:hover { border-color: #8a5aad; box-shadow: 0 1px 4px rgba(52, 1, 103, 0.08); }
            .qb-question-selected { border-color: #340167 !important; box-shadow: 0 0 0 2px rgba(52, 1, 103, 0.15), 0 1px 4px rgba(52, 1, 103, 0.1) !important; background: #faf8fc; }
            .qb-dragging { opacity: 0.4; transform: scale(0.98); }

            .qb-field-drag-handle { color: #d1d5db; cursor: grab; padding: 4px; border-radius: 4px; transition: color 0.15s ease; flex-shrink: 0; }
            .qb-field-drag-handle:hover { color: #6b7280; }
            .qb-field-type-badge { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: #f3f4f6; border-radius: 8px; font-size: 0.875rem; flex-shrink: 0; border: 1px solid #e5e7eb; }
            .qb-question-selected .qb-field-type-badge { background: #f0e4f7; border-color: #8a5aad; }
            .qb-field-info { flex: 1; min-width: 0; }
            .qb-field-label { font-size: 0.875rem; font-weight: 600; color: #111827; }
            .qb-field-meta { display: flex; gap: 10px; margin-top: 2px; align-items: center; }
            .qb-field-type-text { font-size: 0.75rem; color: #6b7280; }
            .qb-points-badge { font-size: 0.6875rem; font-weight: 600; color: #340167; background: #f5f0fa; padding: 1px 8px; border-radius: 4px; border: 1px solid #d4b8e8; }
            .qb-field-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.15s ease; flex-shrink: 0; }
            .qb-question-card:hover .qb-field-actions { opacity: 1; }
            .qb-field-action-btn { padding: 6px; border-radius: 6px; border: none; background: transparent; color: #9ca3af; cursor: pointer; transition: all 0.15s ease; }
            .qb-field-action-btn:hover { color: #340167; background: #f5f0fa; }
            .qb-field-action-delete:hover { color: #dc2626 !important; background: #fef2f2 !important; }

            .qb-properties { width: 0; min-width: 0; background: #ffffff; border-left: 1px solid #e5e7eb; overflow: hidden; transition: width 0.25s ease, min-width 0.25s ease; display: flex; flex-direction: column; }
            .qb-properties-open { width: 360px; min-width: 360px; }
            .qb-properties-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e5e7eb; }
            .qb-properties-header h3 { font-size: 0.9375rem; font-weight: 700; color: #111827; margin: 0; }
            .qb-properties-close { padding: 4px; border-radius: 6px; border: none; background: transparent; color: #9ca3af; cursor: pointer; transition: all 0.15s; }
            .qb-properties-close:hover { color: #374151; background: #f3f4f6; }
            .qb-properties-body { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
            .qb-properties-footer { padding: 16px 20px; border-top: 1px solid #e5e7eb; }
            .qb-properties-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: #9ca3af; font-size: 0.8125rem; padding: 40px; text-align: center; }

            .qb-prop-group { display: flex; flex-direction: column; gap: 4px; }
            .qb-prop-label { font-size: 0.75rem; font-weight: 600; color: #374151; }
            .qb-prop-input { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.8125rem; color: #111827; background: #ffffff; transition: border-color 0.15s ease, box-shadow 0.15s ease; outline: none; width: 100%; }
            .qb-prop-input:focus { border-color: #340167; box-shadow: 0 0 0 2px rgba(52, 1, 103, 0.15); }
            .qb-prop-help { font-size: 0.6875rem; color: #9ca3af; }
            .qb-prop-type-display { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 0.8125rem; color: #6b7280; }
            .qb-prop-readonly { padding: 10px 12px; background: rgba(0, 0, 0, 0.03); border: 1px solid rgba(0, 0, 0, 0.08); border-radius: 8px; font-size: 0.8125rem; color: #6b7280; }

            .qb-options-list { display: flex; flex-direction: column; gap: 6px; }
            .qb-option-row { display: flex; gap: 6px; align-items: center; }
            .qb-option-correct { display: flex; align-items: center; }
            .qb-option-correct input { width: 18px; height: 18px; accent-color: #22c55e; }
            .qb-option-input { flex: 1; padding: 6px 8px !important; font-size: 0.75rem !important; }
            .qb-option-remove { padding: 4px; border-radius: 4px; border: none; background: transparent; color: #d1d5db; cursor: pointer; flex-shrink: 0; }
            .qb-option-remove:hover { color: #dc2626; background: #fef2f2; }
            .qb-add-option-btn { display: flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px dashed #d1d5db; border-radius: 6px; background: transparent; color: #6b7280; font-size: 0.75rem; cursor: pointer; transition: all 0.15s; margin-top: 4px; }
            .qb-add-option-btn:hover { border-color: #340167; color: #340167; background: #f5f0fa; }

            .qb-tf-options { display: flex; gap: 16px; }
            .qb-tf-option { display: flex; align-items: center; gap: 6px; font-size: 0.8125rem; color: #374151; cursor: pointer; }
            .qb-tf-option input { accent-color: #340167; }

            .qb-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 0.8125rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease; border: none; text-decoration: none; }
            .qb-btn-outline { background: #ffffff; border: 1px solid #d1d5db; color: #374151; }
            .qb-btn-outline:hover { border-color: #9ca3af; background: #f9fafb; color: #111827; }
            .qb-btn-primary { background: #340167; color: #ffffff; }
            .qb-btn-primary:hover { background: #280150; }
            .qb-btn-full { width: 100%; justify-content: center; }

            .qb-toast { position: fixed; bottom: 24px; right: 24px; display: flex; align-items: center; gap: 8px; padding: 12px 20px; background: #ffffff; border: 1px solid #d1fae5; border-radius: 10px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); font-size: 0.875rem; color: #065f46; z-index: 100; }

            .dark .qb-container { background: #111827; }
            .dark .qb-sidebar { background: #1f2937; border-color: #374151; }
            .dark .qb-sidebar-header { border-color: #374151; }
            .dark .qb-sidebar-header h3 { color: #f9fafb; }
            .dark .qb-category-title { color: #9ca3af; }
            .dark .qb-palette-item { color: #d1d5db; }
            .dark .qb-palette-item:hover { background: #1a0833; border-color: #340167; color: #c9a8e0; }
            .dark .qb-palette-icon { background: #374151; border-color: #4b5563; }
            .dark .qb-canvas-header { background: #1f2937; border-color: #374151; }
            .dark .qb-canvas-title h2 { color: #f9fafb; }
            .dark .qb-canvas { background: #111827; }
            .dark .qb-empty-state { color: #6b7280; }
            .dark .qb-question-card { background: #1f2937; border-color: #374151; }
            .dark .qb-question-card:hover { border-color: #340167; }
            .dark .qb-question-selected { border-color: #340167 !important; background: #190730; }
            .dark .qb-field-label { color: #f9fafb; }
            .dark .qb-field-type-badge { background: #374151; border-color: #4b5563; }
            .dark .qb-properties { background: #1f2937; border-color: #374151; }
            .dark .qb-properties-header { border-color: #374151; }
            .dark .qb-properties-header h3 { color: #f9fafb; }
            .dark .qb-properties-footer { border-color: #374151; }
            .dark .qb-prop-label { color: #d1d5db; }
            .dark .qb-prop-input { background: #111827; border-color: #4b5563; color: #f9fafb; }
            .dark .qb-prop-type-display { background: #111827; border-color: #4b5563; color: #9ca3af; }
            .dark .qb-prop-readonly { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); color: #9ca3af; }
            .dark .qb-btn-outline { background: #1f2937; border-color: #4b5563; color: #d1d5db; }
            .dark .qb-toast { background: #1f2937; border-color: #065f46; color: #6ee7b7; }
            .qb-container { height: auto; min-height: 65vh; margin: 0; border: 1px solid #e5e7eb; border-radius: 12px; align-items: stretch; }
            .qb-sidebar { width: 190px; min-width: 190px; }
            .qb-canvas-wrapper { min-width: 0; }
            .qb-canvas-header { flex-wrap: wrap; gap: 12px; padding: 16px; }
            .qb-canvas { padding: 16px; }
            .qb-properties-open { width: 340px; min-width: 300px; }
            .qb-field-actions { opacity: 1; flex-wrap: wrap; max-width: 72px; }
            .qb-container button:disabled { opacity: .4; cursor: not-allowed; }
            .qb-field-action-btn:disabled { opacity: .3; cursor: default; }
            .qb-field-label { overflow-wrap: anywhere; }
            .qb-guidance { padding: 12px 16px; font-size: .8rem; color: #475569; background: #eef2ff; line-height: 1.6; }
            .qb-validation { padding: 12px 16px; color: #b91c1c; background: #fef2f2; font-size: .85rem; }
            .qb-question-count, .qb-prop-help { color: #64748b; }
            .dark .qb-guidance { color: #c7d2fe; background: #1e293b; }
            .dark .qb-prop-help, .dark .qb-question-count { color: #cbd5e1; }
            .qb-container button:focus-visible, .qb-question-card:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; }
            @media (max-width: 1100px) {
                .qb-container { flex-wrap: wrap; }
                .qb-sidebar { width: 100%; min-width: 0; border-right: 0; }
                .qb-category { display: inline-block; padding: 8px 16px; }
                .qb-palette-grid { flex-direction: row; flex-wrap: wrap; }
                .qb-canvas-wrapper { flex-basis: 50%; }
            }
            @media (max-width: 640px) {
                .qb-container { display: block; overflow: visible; }
                .qb-properties-open { width: 100%; min-width: 0; }
                .qb-properties { border-left: 0; }
                .qb-canvas { max-height: 380px; }
                .qb-field-drag-handle, .qb-field-type-badge { display: none; }
                .qb-question-card { padding: 12px; gap: 8px; }
                .qb-empty-state { padding: 24px; }
                .qb-properties-footer { position: sticky; bottom: 0; background: inherit; }
            }
        </style>
    @endpush
</x-filament-panels::page>
