<x-filament-panels::page>
    <div x-data="{
            draggedType: null,
            draggedFieldId: null,
            dragOverIndex: null,
            isDraggingFromPalette: false,

            startDragFromPalette(e, type) {
                this.draggedType = type;
                this.isDraggingFromPalette = true;
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('text/plain', type);
            },

            startDragField(e, fieldId) {
                this.draggedFieldId = fieldId;
                this.isDraggingFromPalette = false;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', fieldId);
                const card = e.target.closest('[data-field-id]');
                if (card) card.classList.add('fb-dragging');
            },

            getCanvasCards() {
                const canvas = this.$refs.canvas;
                return canvas ? canvas.querySelectorAll('[data-field-id]') : [];
            },

            getNearestIndex(e) {
                const cards = this.getCanvasCards();
                if (cards.length === 0) return -1;

                const mouseY = e.clientY;
                let closestIdx = cards.length; // default: after last
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

            onDragOver(e, index) {
                e.preventDefault();
                e.dataTransfer.dropEffect = this.isDraggingFromPalette ? 'copy' : 'move';
                this.dragOverIndex = index;
            },

            onDragOverCanvas(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = this.isDraggingFromPalette ? 'copy' : 'move';
                // Calculate nearest card index from mouse position
                const idx = this.getNearestIndex(e);
                const cards = this.getCanvasCards();
                // Clamp to valid card index for the visual indicator
                this.dragOverIndex = idx < cards.length ? idx : (cards.length > 0 ? cards.length - 1 : -1);
            },

            onDragLeave(e) {
                if (!e.currentTarget.contains(e.relatedTarget)) {
                    this.dragOverIndex = null;
                }
            },

            async onDropCanvas(e) {
                e.preventDefault();
                const dropIndex = this.dragOverIndex;
                this.dragOverIndex = null;

                if (this.isDraggingFromPalette && this.draggedType) {
                    // Determine insertion point from nearest card
                    const cards = this.getCanvasCards();
                    const nearIdx = this.getNearestIndex(e);
                    let afterFieldId = null;
                    if (cards.length > 0 && nearIdx > 0) {
                        afterFieldId = parseInt(cards[Math.min(nearIdx - 1, cards.length - 1)].dataset.fieldId);
                    }
                    await $wire.call('addField', this.draggedType, afterFieldId);
                    this.draggedType = null;
                    this.isDraggingFromPalette = false;
                    document.querySelectorAll('.fb-dragging').forEach(el => el.classList.remove('fb-dragging'));
                    return;
                }

                // Reorder: handle drops that land on the canvas gap between cards
                if (this.draggedFieldId) {
                    const cards = this.getCanvasCards();
                    const orderedIds = Array.from(cards).map(el => parseInt(el.dataset.fieldId));
                    const nearIdx = this.getNearestIndex(e);
                    const fromIdx = orderedIds.indexOf(this.draggedFieldId);

                    if (fromIdx !== -1 && nearIdx !== fromIdx && nearIdx !== fromIdx + 1) {
                        const [moved] = orderedIds.splice(fromIdx, 1);
                        const insertAt = nearIdx > fromIdx ? nearIdx - 1 : nearIdx;
                        orderedIds.splice(insertAt, 0, moved);
                        await $wire.call('reorderFields', orderedIds);
                    }
                }

                this.draggedFieldId = null;
                this.draggedType = null;
                this.isDraggingFromPalette = false;
                document.querySelectorAll('.fb-dragging').forEach(el => el.classList.remove('fb-dragging'));
            },

            async onDropField(e, targetIndex) {
                e.preventDefault();
                e.stopPropagation();
                this.dragOverIndex = null;

                if (this.isDraggingFromPalette && this.draggedType) {
                    const cards = this.getCanvasCards();
                    const afterFieldId = cards[targetIndex] ? parseInt(cards[targetIndex].dataset.fieldId) : null;
                    await $wire.call('addField', this.draggedType, afterFieldId);
                    this.draggedType = null;
                    this.isDraggingFromPalette = false;
                    return;
                }

                if (this.draggedFieldId) {
                    const cards = this.getCanvasCards();
                    const orderedIds = Array.from(cards).map(el => parseInt(el.dataset.fieldId));

                    const fromIdx = orderedIds.indexOf(this.draggedFieldId);
                    if (fromIdx !== -1 && fromIdx !== targetIndex) {
                        const [moved] = orderedIds.splice(fromIdx, 1);
                        orderedIds.splice(targetIndex, 0, moved);
                        await $wire.call('reorderFields', orderedIds);
                    }
                }

                this.draggedFieldId = null;
                document.querySelectorAll('.fb-dragging').forEach(el => el.classList.remove('fb-dragging'));
            },

            onDragEnd(e) {
                this.draggedType = null;
                this.draggedFieldId = null;
                this.dragOverIndex = null;
                this.isDraggingFromPalette = false;
                document.querySelectorAll('.fb-dragging').forEach(el => el.classList.remove('fb-dragging'));
            },

            getTypeIcon(type) {
                const icons = {
                    section_header: '§',
                    text: 'T', textarea: '¶', number: '#', email: '@', tel: '📱', url: '🔗',
                    select: '▾', multiselect: '☰', checkbox: '☑', checkboxlist: '☑☑', radio: '◉',
                    date: '📅', datetime: '📅', time: '⏰',
                    file: '📎', rating: '⭐', likert_scale: '📊'
                };
                return icons[type] || '?';
            },

            getTypeLabel(type) {
                const labels = {
                    section_header: 'Section',
                    text: 'Text Input', textarea: 'Text Area', number: 'Number', email: 'Email',
                    tel: 'Phone', url: 'URL', select: 'Dropdown', multiselect: 'Multi-Select',
                    checkbox: 'Checkbox', checkboxlist: 'Checkbox List', radio: 'Radio Buttons',
                    date: 'Date', datetime: 'Date & Time', time: 'Time',
                    file: 'File Upload', rating: 'Star Rating', likert_scale: 'Likert Scale'
                };
                return labels[type] || type;
            }
        }" class="fb-container">
        {{-- ═══════ LEFT SIDEBAR — FIELD PALETTE ═══════ --}}
        <div class="fb-sidebar">
            <div class="fb-sidebar-header">
                <h3>Fields</h3>
                <p>Drag a field to the canvas</p>
            </div>

            @if($hasCustomScale)
                <div class="fb-category">
                    <div class="fb-palette-notice" style="padding: 0.5rem 0.75rem; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 0.5rem; margin-bottom: 0.75rem;">
                        <p style="font-size: 0.75rem; color: #93c5fd; margin: 0;">📊 Custom rating scale is active. Only Likert Scale fields are allowed.</p>
                    </div>
                </div>
            @endif

            @foreach($this->getFilteredFieldCategories() as $category => $types)
                <div class="fb-category">
                    <h4 class="fb-category-title">{{ $category }}</h4>
                    <div class="fb-palette-grid">
                        @foreach($types as $typeKey => $typeInfo)
                            <div class="fb-palette-item" draggable="true"
                                x-on:dragstart="startDragFromPalette($event, '{{ $typeKey }}')" x-on:dragend="onDragEnd($event)"
                                wire:click="addField('{{ $typeKey }}')" title="Click or drag to add">
                                <span class="fb-palette-icon">{{ $typeInfo['icon'] }}</span>
                                <span class="fb-palette-label">{{ $typeInfo['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ═══════ CENTER — CANVAS DROP ZONE ═══════ --}}
        <div class="fb-canvas-wrapper">
            <div class="fb-canvas-header">
                <div class="fb-canvas-title">
                    <h2>{{ $formRecord->name }}</h2>
                    @php $fieldCount = collect($fields)->where('type', '!=', 'section_header')->count(); @endphp
                    <span class="fb-field-count">{{ $fieldCount }} {{ Str::plural('field', $fieldCount) }}</span>
                </div>
                <div class="fb-canvas-actions">
                    <a href="{{ \App\Filament\Resources\FormResource::getUrl('index') }}" class="fb-btn fb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back
                    </a>
                    <a href="{{ route('form.preview', $formRecord) }}" target="_blank" class="fb-btn fb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        Preview
                    </a>
                    <a href="{{ \App\Filament\Resources\FormResource::getUrl('edit', ['record' => $formRecord]) }}"
                        class="fb-btn fb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Settings
                    </a>
                    <button x-on:click="$dispatch('open-columns-modal')" class="fb-btn fb-btn-outline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7" />
                        </svg>
                        Columns
                    </button>
                </div>
            </div>

            <div class="fb-canvas" x-ref="canvas"
                x-on:dragover.prevent="onDragOverCanvas($event)"
                x-on:dragleave="onDragLeave($event)"
                x-on:drop="onDropCanvas($event)"
                x-bind:class="{ 'fb-canvas-dragover': dragOverIndex !== null && (isDraggingFromPalette || draggedFieldId) }">
                @if(count($fields) === 0)
                    <div class="fb-empty-state">
                        <div class="fb-empty-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3>No fields yet</h3>
                        <p>Drag fields from the sidebar or click them to add</p>
                    </div>
                @endif

                @foreach($fields as $index => $field)
                    @if($field['type'] === 'section_header')
                        {{-- Section divider card --}}
                        <div class="fb-section-divider {{ $selectedFieldId === $field['id'] ? 'fb-section-divider-selected' : '' }}"
                            data-field-id="{{ $field['id'] }}" draggable="true"
                            x-on:dragstart="startDragField($event, {{ $field['id'] }})"
                            x-on:dragover.prevent.stop="onDragOver($event, {{ $index }})"
                            x-on:drop.stop="onDropField($event, {{ $index }})" x-on:dragend="onDragEnd($event)"
                            x-on:click="$wire.call('selectField', {{ $field['id'] }})" x-bind:class="{
                                'fb-drop-above': dragOverIndex === {{ $index }} && (isDraggingFromPalette || draggedFieldId)
                            }">
                            <div class="fb-field-drag-handle" title="Drag to reorder">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" />
                                </svg>
                            </div>
                            <div class="fb-section-divider-icon">§</div>
                            <div class="fb-section-divider-label">{{ $field['label'] }}</div>
                            <div class="fb-field-actions">
                                <button wire:click.stop="deleteField({{ $field['id'] }})" wire:confirm="Delete this section and ungroup its fields?"
                                    class="fb-field-action-btn fb-field-action-delete" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @else
                        {{-- Regular field card --}}
                        <div class="fb-field-card {{ $selectedFieldId === $field['id'] ? 'fb-field-selected' : '' }}"
                            data-field-id="{{ $field['id'] }}" draggable="true"
                            x-on:dragstart="startDragField($event, {{ $field['id'] }})"
                            x-on:dragover.prevent.stop="onDragOver($event, {{ $index }})"
                            x-on:drop.stop="onDropField($event, {{ $index }})" x-on:dragend="onDragEnd($event)"
                            x-on:click="$wire.call('selectField', {{ $field['id'] }})" x-bind:class="{
                                                    'fb-drop-above': dragOverIndex === {{ $index }} && (isDraggingFromPalette || draggedFieldId)
                                                }">
                            <div class="fb-field-drag-handle" title="Drag to reorder">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" />
                                </svg>
                            </div>

                            <div class="fb-field-type-badge" x-text="getTypeIcon('{{ $field['type'] }}')"></div>

                            <div class="fb-field-info">
                                <div class="fb-field-label">
                                    {{ $field['label'] }}
                                    @if($field['is_required'])
                                        <span class="fb-required-badge">Required</span>
                                    @endif
                                </div>
                                <div class="fb-field-meta">
                                    <span class="fb-field-type-text" x-text="getTypeLabel('{{ $field['type'] }}')"></span>
                                    @if($field['section'])
                                        <span class="fb-field-section-tag">{{ $field['section'] }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="fb-field-actions">
                                <button wire:click.stop="duplicateField({{ $field['id'] }})" class="fb-field-action-btn"
                                    title="Duplicate">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </button>
                                <button wire:click.stop="deleteField({{ $field['id'] }})" wire:confirm="Delete this field?"
                                    class="fb-field-action-btn fb-field-action-delete" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- ═══════ RIGHT SIDEBAR — PROPERTIES PANEL ═══════ --}}
        <div class="fb-properties {{ $showProperties ? 'fb-properties-open' : '' }}">
            @if($showProperties && $selectedFieldId)
                <div class="fb-properties-header">
                    <h3>Field Properties</h3>
                    <button wire:click="closeProperties" class="fb-properties-close">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="fb-properties-body">
                    <div class="fb-prop-group">
                        <label class="fb-prop-label">Field Type</label>
                        <div class="fb-prop-type-display">
                            <span x-text="getTypeIcon('{{ $selectedFieldData['type'] ?? '' }}')"></span>
                            <span x-text="getTypeLabel('{{ $selectedFieldData['type'] ?? '' }}')"></span>
                        </div>
                    </div>

                    @if(($selectedFieldData['type'] ?? '') === 'section_header')
                        {{-- Section header properties --}}
                        <div class="fb-prop-group">
                            <label class="fb-prop-label" for="prop-label">Section Name <span class="text-red-500">*</span></label>
                            <input type="text" id="prop-label" class="fb-prop-input"
                                wire:model.live.debounce.300ms="selectedFieldData.label">
                            <span class="fb-prop-help">Fields placed below this header automatically belong to this section</span>
                        </div>
                    @else
                    <div class="fb-prop-group">
                        <label class="fb-prop-label" for="prop-label">Label <span class="text-red-500">*</span></label>
                        <input type="text" id="prop-label" class="fb-prop-input"
                            wire:model.live.debounce.300ms="selectedFieldData.label"
                            wire:change="$set('selectedFieldData.name', '{{ Str::snake($selectedFieldData['label'] ?? '') }}')">
                    </div>

                    <div class="fb-prop-group">
                        <label class="fb-prop-label" for="prop-name">Field Name</label>
                        <input type="text" id="prop-name" class="fb-prop-input fb-prop-mono"
                            wire:model="selectedFieldData.name">
                        <span class="fb-prop-help">Internal identifier (snake_case)</span>
                    </div>

                    @if($selectedFieldData['section'] ?? null)
                        <div class="fb-prop-group">
                            <label class="fb-prop-label">Section</label>
                            <div class="fb-prop-readonly">{{ $selectedFieldData['section'] }}</div>
                            <span class="fb-prop-help">Auto-assigned from the section header above this field</span>
                        </div>
                    @endif

                    @if(in_array($selectedFieldData['type'] ?? '', ['text', 'textarea', 'email', 'tel', 'url', 'number']))
                        <div class="fb-prop-group">
                            <label class="fb-prop-label" for="prop-placeholder">Placeholder</label>
                            <input type="text" id="prop-placeholder" class="fb-prop-input"
                                wire:model="selectedFieldData.placeholder">
                        </div>
                    @endif

                    <div class="fb-prop-group">
                        <label class="fb-prop-label" for="prop-help">Help Text</label>
                        <textarea id="prop-help" class="fb-prop-input" rows="2"
                            wire:model="selectedFieldData.help_text"></textarea>
                    </div>

                    <div class="fb-prop-group fb-prop-toggle-group">
                        <label class="fb-prop-toggle">
                            <input type="checkbox" wire:model="selectedFieldData.is_required">
                            <span>Required field</span>
                        </label>
                    </div>

                    {{-- Options section for select/radio/checkbox types --}}
                    @if(in_array($selectedFieldData['type'] ?? '', \App\Models\FormField::TYPES_WITH_OPTIONS))
                        <div class="fb-prop-group">
                            <label class="fb-prop-label">Options</label>
                            <div class="fb-options-list">
                                @foreach(($selectedFieldData['options'] ?? []) as $optIndex => $option)
                                    <div class="fb-option-row" wire:key="option-{{ $optIndex }}">
                                        <input type="text" class="fb-prop-input fb-option-input"
                                            wire:model="selectedFieldData.options.{{ $optIndex }}.value" placeholder="Value">
                                        <input type="text" class="fb-prop-input fb-option-input"
                                            wire:model="selectedFieldData.options.{{ $optIndex }}.label" placeholder="Label">
                                        <button wire:click="removeOption({{ $optIndex }})" class="fb-option-remove" title="Remove">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                                stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                            <button wire:click="addOption" class="fb-add-option-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Add Option
                            </button>
                        </div>
                    @endif

                    {{-- Custom column rich text editors --}}
                    @if(count($fieldColumns) > 0)
                        <div class="fb-prop-divider"></div>
                        <div class="fb-prop-group">
                            <label class="fb-prop-label" style="font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.05em; color: #340167;">Custom Columns</label>
                        </div>
                        @foreach($fieldColumns as $col)
                            <div class="fb-prop-group" wire:key="colval-{{ $selectedFieldId }}-{{ $col['key'] }}">
                                <label class="fb-prop-label">{{ $col['label'] }}</label>
                                <div x-data="richTextEditor('{{ $col['key'] }}', @js($selectedFieldData['column_values'][$col['key']] ?? ''))"
                                     class="fb-richtext-wrapper" wire:ignore>
                                    <div class="fb-richtext-toolbar">
                                        <button type="button" x-on:click="exec('bold')" title="Bold" class="fb-rt-btn">
                                            <strong>B</strong>
                                        </button>
                                        <button type="button" x-on:click="exec('italic')" title="Italic" class="fb-rt-btn">
                                            <em>I</em>
                                        </button>
                                        <button type="button" x-on:click="exec('underline')" title="Underline" class="fb-rt-btn">
                                            <u>U</u>
                                        </button>
                                        <span class="fb-rt-sep"></span>
                                        <button type="button" x-on:click="exec('insertUnorderedList')" title="Bullet List" class="fb-rt-btn">
                                            •≡
                                        </button>
                                        <button type="button" x-on:click="exec('insertOrderedList')" title="Numbered List" class="fb-rt-btn">
                                            1.
                                        </button>
                                    </div>
                                    <div class="fb-richtext-editor"
                                        contenteditable="true"
                                        x-ref="editor"
                                        x-on:input="onInput($event)"
                                        x-on:blur="save()"
                                        data-placeholder="Type here..."></div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                    @endif {{-- end section_header check --}}
                </div>

                <div class="fb-properties-footer">
                    <button wire:click="saveFieldProperties" class="fb-btn fb-btn-primary fb-btn-full">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        Save Changes
                    </button>
                </div>
            @else
                <div class="fb-properties-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 opacity-30" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    <p>Select a field to edit its properties</p>
                </div>
            @endif
        </div>

        {{-- ═══════ COLUMNS MANAGEMENT MODAL ═══════ --}}
        <div x-data="{ open: false }" x-on:open-columns-modal.window="open = true"
            x-show="open" x-cloak class="fb-modal-overlay" x-transition.opacity>
            <div class="fb-modal" x-on:click.away="open = false" x-show="open" x-transition>
                <div class="fb-modal-header">
                    <h3>Manage Field Columns</h3>
                    <button x-on:click="open = false" class="fb-properties-close">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="fb-modal-body">
                    <p class="fb-prop-help" style="margin-bottom: 12px;">
                        Add custom columns that appear on every field across all sections. Use them to describe field purpose, guidelines, etc.
                    </p>
                    @foreach($fieldColumns as $colIdx => $col)
                        <div class="fb-column-row" wire:key="col-{{ $colIdx }}">
                            <input type="text" class="fb-prop-input" placeholder="Column label"
                                wire:model.blur="fieldColumns.{{ $colIdx }}.label"
                                wire:change="saveFieldColumns">
                            <button wire:click="removeFieldColumn({{ $colIdx }})" wire:confirm="Remove this column from all fields?"
                                class="fb-option-remove" title="Remove">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    @endforeach
                    <button wire:click="addFieldColumn" class="fb-add-option-btn" style="margin-top: 8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add Column
                    </button>
                </div>
            </div>
        </div>

        {{-- Toast notification --}}
        <div x-data="{ show: false, message: '' }"
            x-on:notify.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
            x-show="show" x-transition.opacity class="fb-toast">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <span x-text="message"></span>
        </div>
    </div>

    @push('styles')
        <style>
            /* ═══════ LAYOUT ═══════ */
            .fb-container {
                display: flex;
                height: calc(100vh - 64px);
                margin: -1.5rem;
                overflow: hidden;
                background: #f3f4f6;
            }

            /* ═══════ LEFT SIDEBAR ═══════ */
            .fb-sidebar {
                width: 260px;
                min-width: 260px;
                background: #ffffff;
                border-right: 1px solid #e5e7eb;
                overflow-y: auto;
                display: flex;
                flex-direction: column;
            }

            .fb-sidebar-header {
                padding: 20px 16px 12px;
                border-bottom: 1px solid #f3f4f6;
            }

            .fb-sidebar-header h3 {
                font-size: 1rem;
                font-weight: 700;
                color: #111827;
                margin: 0;
            }

            .fb-sidebar-header p {
                font-size: 0.75rem;
                color: #9ca3af;
                margin: 4px 0 0;
            }

            .fb-category {
                padding: 12px 16px 8px;
            }

            .fb-category-title {
                font-size: 0.675rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #6b7280;
                margin: 0 0 8px;
            }

            .fb-palette-grid {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .fb-palette-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 10px;
                border-radius: 8px;
                cursor: grab;
                transition: all 0.15s ease;
                border: 1px solid transparent;
                font-size: 0.8125rem;
                color: #374151;
                user-select: none;
            }

            .fb-palette-item:hover {
                background: #f5f0fa;
                border-color: #d4b8e8;
                color: #340167;
            }

            .fb-palette-item:active {
                cursor: grabbing;
                transform: scale(0.97);
            }

            .fb-palette-icon {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f9fafb;
                border-radius: 6px;
                font-size: 0.75rem;
                flex-shrink: 0;
                border: 1px solid #e5e7eb;
            }

            .fb-palette-item:hover .fb-palette-icon {
                background: #f0e4f7;
                border-color: #8a5aad;
            }

            .fb-palette-label {
                font-weight: 500;
            }

            /* ═══════ CANVAS ═══════ */
            .fb-canvas-wrapper {
                flex: 1;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .fb-canvas-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 24px;
                background: #ffffff;
                border-bottom: 1px solid #e5e7eb;
            }

            .fb-canvas-title h2 {
                font-size: 1.125rem;
                font-weight: 700;
                color: #111827;
                margin: 0;
            }

            .fb-field-count {
                font-size: 0.75rem;
                color: #9ca3af;
            }

            .fb-canvas-actions {
                display: flex;
                gap: 8px;
            }

            .fb-canvas {
                flex: 1;
                overflow-y: auto;
                padding: 24px;
                display: flex;
                flex-direction: column;
                gap: 8px;
                min-height: 200px;
                transition: background 0.2s ease;
            }

            .fb-canvas-dragover {
                background: #f5f0fa !important;
                outline: 2px dashed #340167;
                outline-offset: -8px;
                border-radius: 12px;
            }

            /* ═══════ EMPTY STATE ═══════ */
            .fb-empty-state {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                color: #9ca3af;
                gap: 8px;
                padding: 60px;
            }

            .fb-empty-state h3 {
                font-size: 1.125rem;
                font-weight: 600;
                color: #6b7280;
                margin: 0;
            }

            .fb-empty-state p {
                margin: 0;
                font-size: 0.875rem;
            }

            .fb-empty-icon {
                color: #d1d5db;
            }

            /* ═══════ SECTION HEADER ═══════ */
            .fb-section-header {
                padding: 8px 16px;
                margin-top: 8px;
            }

            .fb-section-label {
                font-size: 0.8125rem;
                font-weight: 700;
                color: #340167;
                letter-spacing: 0.01em;
                text-transform: uppercase;
                padding: 6px 14px;
                background: linear-gradient(135deg, #f5f0fa, #ece0f5);
                border-radius: 6px;
                display: inline-block;
                border-left: 3px solid #340167;
            }

            /* ═══════ SECTION DIVIDER (draggable section_header field) ═══════ */
            .fb-section-divider {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 16px;
                background: linear-gradient(135deg, #f5f0fa, #ece0f5);
                border: 2px dashed #8a5aad;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.15s ease;
                user-select: none;
                position: relative;
            }

            .fb-section-divider:hover {
                border-color: #340167;
                box-shadow: 0 1px 4px rgba(52, 1, 103, 0.15);
            }

            .fb-section-divider-selected {
                border-color: #280150 !important;
                border-style: solid !important;
                box-shadow: 0 0 0 2px rgba(52, 1, 103, 0.2), 0 2px 6px rgba(52, 1, 103, 0.15) !important;
                background: linear-gradient(135deg, #ece0f5, #d4b8e8);
            }

            .fb-section-divider-icon {
                font-size: 1.25rem;
                font-weight: 700;
                color: #340167;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.7);
                border-radius: 6px;
                flex-shrink: 0;
            }

            .fb-section-divider-label {
                flex: 1;
                font-size: 0.875rem;
                font-weight: 700;
                color: #340167;
                text-transform: uppercase;
                letter-spacing: 0.02em;
            }

            .fb-section-divider .fb-field-drag-handle {
                color: #8a5aad;
            }

            .fb-section-divider .fb-field-actions {
                flex-shrink: 0;
            }

            /* ═══════ SECTION TAG (shown on field cards) ═══════ */
            .fb-field-section-tag {
                font-size: 0.6875rem;
                font-weight: 600;
                color: #340167;
                background: #f5f0fa;
                padding: 1px 8px;
                border-radius: 4px;
                border: 1px solid #d4b8e8;
            }

            /* ═══════ READ-ONLY PROP ═══════ */
            .fb-prop-readonly {
                padding: 8px 12px;
                background: rgba(0, 0, 0, 0.03);
                border: 1px solid rgba(0, 0, 0, 0.08);
                border-radius: 8px;
                font-size: 0.8125rem;
                color: #6b7280;
                font-weight: 500;
            }

            /* ═══════ FIELD CARD ═══════ */
            .fb-field-card {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                background: #ffffff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                cursor: pointer;
                transition: all 0.15s ease;
                user-select: none;
                position: relative;
            }

            .fb-field-card:hover {
                border-color: #8a5aad;
                box-shadow: 0 1px 4px rgba(52, 1, 103, 0.08);
            }

            .fb-field-selected {
                border-color: #340167 !important;
                box-shadow: 0 0 0 2px rgba(52, 1, 103, 0.15), 0 1px 4px rgba(52, 1, 103, 0.1) !important;
                background: #faf8fc;
            }

            .fb-dragging {
                opacity: 0.4;
                transform: scale(0.98);
            }

            .fb-drop-above {
                border-top: 3px solid #340167 !important;
                margin-top: 2px;
            }

            .fb-field-drag-handle {
                color: #d1d5db;
                cursor: grab;
                padding: 4px;
                border-radius: 4px;
                transition: color 0.15s ease;
                flex-shrink: 0;
            }

            .fb-field-drag-handle:hover {
                color: #6b7280;
            }

            .fb-field-drag-handle:active {
                cursor: grabbing;
            }

            .fb-field-type-badge {
                width: 36px;
                height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: #f3f4f6;
                border-radius: 8px;
                font-size: 0.875rem;
                flex-shrink: 0;
                border: 1px solid #e5e7eb;
            }

            .fb-field-selected .fb-field-type-badge {
                background: #f0e4f7;
                border-color: #8a5aad;
            }

            .fb-field-info {
                flex: 1;
                min-width: 0;
            }

            .fb-field-label {
                font-size: 0.875rem;
                font-weight: 600;
                color: #111827;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .fb-required-badge {
                font-size: 0.625rem;
                font-weight: 600;
                color: #dc2626;
                background: #fef2f2;
                border: 1px solid #fecaca;
                padding: 1px 6px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }

            .fb-field-meta {
                display: flex;
                gap: 10px;
                margin-top: 2px;
            }

            .fb-field-type-text {
                font-size: 0.75rem;
                color: #6b7280;
            }

            .fb-field-name {
                font-size: 0.75rem;
                color: #9ca3af;
                font-family: ui-monospace, SFMono-Regular, monospace;
            }

            .fb-field-actions {
                display: flex;
                gap: 4px;
                opacity: 0;
                transition: opacity 0.15s ease;
                flex-shrink: 0;
            }

            .fb-field-card:hover .fb-field-actions {
                opacity: 1;
            }

            .fb-field-action-btn {
                padding: 6px;
                border-radius: 6px;
                border: none;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                transition: all 0.15s ease;
            }

            .fb-field-action-btn:hover {
                color: #340167;
                background: #f5f0fa;
            }

            .fb-field-action-delete:hover {
                color: #dc2626 !important;
                background: #fef2f2 !important;
            }

            /* ═══════ PROPERTIES PANEL ═══════ */
            .fb-properties {
                width: 0;
                min-width: 0;
                background: #ffffff;
                border-left: 1px solid #e5e7eb;
                overflow: hidden;
                transition: width 0.25s ease, min-width 0.25s ease;
                display: flex;
                flex-direction: column;
            }

            .fb-properties-open {
                width: 340px;
                min-width: 340px;
            }

            .fb-properties-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-bottom: 1px solid #e5e7eb;
            }

            .fb-properties-header h3 {
                font-size: 0.9375rem;
                font-weight: 700;
                color: #111827;
                margin: 0;
            }

            .fb-properties-close {
                padding: 4px;
                border-radius: 6px;
                border: none;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                transition: all 0.15s;
            }

            .fb-properties-close:hover {
                color: #374151;
                background: #f3f4f6;
            }

            .fb-properties-body {
                flex: 1;
                overflow-y: auto;
                padding: 20px;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            .fb-properties-footer {
                padding: 16px 20px;
                border-top: 1px solid #e5e7eb;
            }

            .fb-properties-empty {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                color: #9ca3af;
                font-size: 0.8125rem;
                padding: 40px;
                text-align: center;
            }

            /* ═══════ PROPERTY CONTROLS ═══════ */
            .fb-prop-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .fb-prop-label {
                font-size: 0.75rem;
                font-weight: 600;
                color: #374151;
            }

            .fb-prop-input {
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                font-size: 0.8125rem;
                color: #111827;
                background: #ffffff;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
                outline: none;
                width: 100%;
            }

            .fb-prop-input:focus {
                border-color: #340167;
                box-shadow: 0 0 0 2px rgba(52, 1, 103, 0.15);
            }

            .fb-prop-mono {
                font-family: ui-monospace, SFMono-Regular, monospace;
                font-size: 0.75rem;
            }

            .fb-prop-help {
                font-size: 0.6875rem;
                color: #9ca3af;
            }

            .fb-prop-type-display {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                font-size: 0.8125rem;
                color: #6b7280;
            }

            .fb-prop-toggle-group {
                padding: 4px 0;
            }

            .fb-prop-toggle {
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                font-size: 0.8125rem;
                color: #374151;
            }

            .fb-prop-toggle input[type="checkbox"] {
                width: 18px;
                height: 18px;
                border-radius: 4px;
                accent-color: #340167;
            }

            /* ═══════ OPTIONS LIST ═══════ */
            .fb-options-list {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .fb-option-row {
                display: flex;
                gap: 4px;
                align-items: center;
            }

            .fb-option-input {
                flex: 1;
                padding: 6px 8px !important;
                font-size: 0.75rem !important;
            }

            .fb-option-remove {
                padding: 4px;
                border-radius: 4px;
                border: none;
                background: transparent;
                color: #d1d5db;
                cursor: pointer;
                flex-shrink: 0;
            }

            .fb-option-remove:hover {
                color: #dc2626;
                background: #fef2f2;
            }

            .fb-add-option-btn {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 6px 10px;
                border: 1px dashed #d1d5db;
                border-radius: 6px;
                background: transparent;
                color: #6b7280;
                font-size: 0.75rem;
                cursor: pointer;
                transition: all 0.15s;
                margin-top: 4px;
            }

            .fb-add-option-btn:hover {
                border-color: #340167;
                color: #340167;
                background: #f5f0fa;
            }

            /* ═══════ SECTION COMBOBOX ═══════ */
            .fb-section-combo {
                position: relative;
                display: flex;
                align-items: center;
            }

            .fb-section-combo .fb-prop-input {
                padding-right: 52px;
            }

            .fb-section-combo-btns {
                position: absolute;
                right: 4px;
                display: flex;
                gap: 2px;
            }

            .fb-section-combo-clear,
            .fb-section-combo-toggle {
                padding: 4px;
                border-radius: 4px;
                border: none;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                transition: all 0.15s;
                display: flex;
                align-items: center;
            }

            .fb-section-combo-clear:hover {
                color: #dc2626;
            }

            .fb-section-combo-toggle:hover {
                color: #374151;
            }

            .fb-section-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #ffffff;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                z-index: 50;
                max-height: 200px;
                overflow-y: auto;
                margin-top: 4px;
            }

            .fb-section-option {
                display: flex;
                align-items: center;
                gap: 6px;
                width: 100%;
                padding: 8px 12px;
                border: none;
                background: transparent;
                color: #374151;
                font-size: 0.8125rem;
                cursor: pointer;
                text-align: left;
                transition: background 0.1s;
            }

            .fb-section-option:hover {
                background: #f0f5ff;
                color: #1e40af;
            }

            .fb-section-option-active {
                background: #eff6ff;
                color: #2563eb;
                font-weight: 600;
            }

            .fb-section-new {
                border-top: 1px solid #e5e7eb;
            }

            .fb-section-option-new {
                color: #059669;
                font-weight: 500;
            }

            .fb-section-option-new:hover {
                background: #ecfdf5;
                color: #047857;
            }

            /* ═══════ BUTTONS ═══════ */
            .fb-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 14px;
                border-radius: 8px;
                font-size: 0.8125rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.15s ease;
                border: none;
                text-decoration: none;
            }

            .fb-btn-outline {
                background: #ffffff;
                border: 1px solid #d1d5db;
                color: #374151;
            }

            .fb-btn-outline:hover {
                border-color: #9ca3af;
                background: #f9fafb;
                color: #111827;
            }

            .fb-btn-primary {
                background: #340167;
                color: #ffffff;
            }

            .fb-btn-primary:hover {
                background: #280150;
            }

            .fb-btn-full {
                width: 100%;
                justify-content: center;
            }

            /* ═══════ TOAST ═══════ */
            .fb-toast {
                position: fixed;
                bottom: 24px;
                right: 24px;
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: #ffffff;
                border: 1px solid #d1fae5;
                border-radius: 10px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                font-size: 0.875rem;
                color: #065f46;
                z-index: 100;
            }

            /* ═══════ DARK MODE ═══════ */
            .dark .fb-container {
                background: #111827;
            }

            .dark .fb-sidebar {
                background: #1f2937;
                border-color: #374151;
            }

            .dark .fb-sidebar-header {
                border-color: #374151;
            }

            .dark .fb-sidebar-header h3 {
                color: #f9fafb;
            }

            .dark .fb-sidebar-header p {
                color: #6b7280;
            }

            .dark .fb-category-title {
                color: #9ca3af;
            }

            .dark .fb-palette-item {
                color: #d1d5db;
            }

            .dark .fb-palette-item:hover {
                background: #1a0833;
                border-color: #340167;
                color: #c9a8e0;
            }

            .dark .fb-palette-icon {
                background: #374151;
                border-color: #4b5563;
            }

            .dark .fb-palette-item:hover .fb-palette-icon {
                background: #1a0833;
                border-color: #340167;
            }

            .dark .fb-canvas-header {
                background: #1f2937;
                border-color: #374151;
            }

            .dark .fb-canvas-title h2 {
                color: #f9fafb;
            }

            .dark .fb-canvas {
                background: #111827;
            }

            .dark .fb-canvas-dragover {
                background: #1a0833 !important;
                outline-color: #340167;
            }

            .dark .fb-empty-state {
                color: #6b7280;
            }

            .dark .fb-empty-state h3 {
                color: #9ca3af;
            }

            .dark .fb-empty-icon {
                color: #4b5563;
            }

            .dark .fb-section-label {
                background: linear-gradient(135deg, #1a0833, #220a42);
                color: #c9a8e0;
                border-color: #340167;
            }

            .dark .fb-section-divider {
                background: linear-gradient(135deg, #1a0833, #220a42);
                border-color: #340167;
            }

            .dark .fb-section-divider:hover {
                border-color: #7a3fa0;
                box-shadow: 0 1px 4px rgba(52, 1, 103, 0.25);
            }

            .dark .fb-section-divider-selected {
                border-color: #7a3fa0 !important;
                background: linear-gradient(135deg, #220a42, #2d0d56);
            }

            .dark .fb-section-divider-icon {
                color: #c9a8e0;
                background: rgba(255, 255, 255, 0.08);
            }

            .dark .fb-section-divider-label {
                color: #c9a8e0;
            }

            .dark .fb-section-divider .fb-field-drag-handle {
                color: #340167;
            }

            .dark .fb-field-section-tag {
                color: #c9a8e0;
                background: #1a0833;
                border-color: #280150;
            }

            .dark .fb-prop-readonly {
                background: rgba(255, 255, 255, 0.05);
                border-color: rgba(255, 255, 255, 0.1);
                color: #9ca3af;
            }

            .dark .fb-field-card {
                background: #1f2937;
                border-color: #374151;
            }

            .dark .fb-field-card:hover {
                border-color: #340167;
            }

            .dark .fb-field-selected {
                border-color: #340167 !important;
                background: #190730;
            }

            .dark .fb-field-drag-handle {
                color: #4b5563;
            }

            .dark .fb-field-drag-handle:hover {
                color: #9ca3af;
            }

            .dark .fb-field-type-badge {
                background: #374151;
                border-color: #4b5563;
            }

            .dark .fb-field-selected .fb-field-type-badge {
                background: #1a0833;
                border-color: #340167;
            }

            .dark .fb-field-label {
                color: #f9fafb;
            }

            .dark .fb-field-type-text {
                color: #9ca3af;
            }

            .dark .fb-field-name {
                color: #6b7280;
            }

            .dark .fb-field-action-btn {
                color: #6b7280;
            }

            .dark .fb-field-action-btn:hover {
                color: #c9a8e0;
                background: #1a0833;
            }

            .dark .fb-field-action-delete:hover {
                color: #f87171 !important;
                background: #450a0a !important;
            }

            .dark .fb-drop-above {
                border-top-color: #340167 !important;
            }

            .dark .fb-properties {
                background: #1f2937;
                border-color: #374151;
            }

            .dark .fb-properties-header {
                border-color: #374151;
            }

            .dark .fb-properties-header h3 {
                color: #f9fafb;
            }

            .dark .fb-properties-close {
                color: #6b7280;
            }

            .dark .fb-properties-close:hover {
                color: #d1d5db;
                background: #374151;
            }

            .dark .fb-properties-footer {
                border-color: #374151;
            }

            .dark .fb-properties-empty {
                color: #6b7280;
            }

            .dark .fb-prop-label {
                color: #d1d5db;
            }

            .dark .fb-prop-input {
                background: #111827;
                border-color: #4b5563;
                color: #f9fafb;
            }

            .dark .fb-prop-input:focus {
                border-color: #340167;
            }

            .dark .fb-prop-help {
                color: #6b7280;
            }

            .dark .fb-prop-type-display {
                background: #111827;
                border-color: #4b5563;
                color: #9ca3af;
            }

            .dark .fb-prop-toggle {
                color: #d1d5db;
            }

            .dark .fb-option-remove {
                color: #4b5563;
            }

            .dark .fb-option-remove:hover {
                color: #f87171;
                background: #450a0a;
            }

            .dark .fb-add-option-btn {
                border-color: #4b5563;
                color: #9ca3af;
            }

            .dark .fb-add-option-btn:hover {
                border-color: #340167;
                color: #c9a8e0;
                background: #1a0833;
            }

            .dark .fb-section-combo-clear,
            .dark .fb-section-combo-toggle {
                color: #6b7280;
            }

            .dark .fb-section-combo-clear:hover {
                color: #f87171;
            }

            .dark .fb-section-combo-toggle:hover {
                color: #d1d5db;
            }

            .dark .fb-section-dropdown {
                background: #1f2937;
                border-color: #4b5563;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            }

            .dark .fb-section-option {
                color: #d1d5db;
            }

            .dark .fb-section-option:hover {
                background: #1a0833;
                color: #c9a8e0;
            }

            .dark .fb-section-option-active {
                background: #1a0833;
                color: #c9a8e0;
            }

            .dark .fb-section-new {
                border-color: #374151;
            }

            .dark .fb-section-option-new {
                color: #34d399;
            }

            .dark .fb-section-option-new:hover {
                background: #064e3b;
                color: #6ee7b7;
            }

            .dark .fb-btn-outline {
                background: #1f2937;
                border-color: #4b5563;
                color: #d1d5db;
            }

            .dark .fb-btn-outline:hover {
                border-color: #6b7280;
                background: #374151;
                color: #f9fafb;
            }

            .dark .fb-toast {
                background: #1f2937;
                border-color: #065f46;
                color: #6ee7b7;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            }

            .dark .fb-required-badge {
                color: #f87171;
                background: #450a0a;
                border-color: #7f1d1d;
            }

            /* ═══════ MODAL ═══════ */
            .fb-modal-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 100;
            }

            .fb-modal {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
                width: 480px;
                max-width: 90vw;
                max-height: 80vh;
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            .fb-modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid #e5e7eb;
            }

            .fb-modal-header h3 {
                font-size: 1rem;
                font-weight: 600;
                color: #111827;
                margin: 0;
            }

            .fb-modal-body {
                padding: 20px;
                overflow-y: auto;
            }

            .fb-column-row {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
            }

            .fb-column-row .fb-prop-input {
                flex: 1;
            }

            .dark .fb-modal {
                background: #1f2937;
            }

            .dark .fb-modal-header {
                border-color: #374151;
            }

            .dark .fb-modal-header h3 {
                color: #f9fafb;
            }

            /* ═══════ RICH TEXT EDITOR ═══════ */
            .fb-prop-divider {
                border-top: 1px solid #e5e7eb;
                margin: 8px 0;
            }

            .fb-richtext-wrapper {
                border: 1px solid #d1d5db;
                border-radius: 8px;
                overflow: hidden;
                background: #ffffff;
            }

            .fb-richtext-toolbar {
                display: flex;
                align-items: center;
                gap: 2px;
                padding: 4px 6px;
                background: #f9fafb;
                border-bottom: 1px solid #e5e7eb;
            }

            .fb-rt-btn {
                width: 28px;
                height: 28px;
                display: flex;
                align-items: center;
                justify-content: center;
                border: none;
                background: transparent;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.8125rem;
                color: #374151;
                transition: all 0.1s ease;
            }

            .fb-rt-btn:hover {
                background: #e5e7eb;
                color: #111827;
            }

            .fb-rt-sep {
                width: 1px;
                height: 18px;
                background: #d1d5db;
                margin: 0 4px;
            }

            .fb-richtext-editor {
                min-height: 80px;
                max-height: 200px;
                overflow-y: auto;
                padding: 8px 12px;
                font-size: 0.8125rem;
                line-height: 1.5;
                color: #374151;
                outline: none;
            }

            .fb-richtext-editor:empty:before {
                content: attr(data-placeholder);
                color: #9ca3af;
                pointer-events: none;
            }

            .fb-richtext-editor ul {
                padding-left: 1.5em;
                margin: 4px 0;
                list-style-type: disc;
            }

            .fb-richtext-editor ol {
                padding-left: 1.5em;
                margin: 4px 0;
                list-style-type: decimal;
            }

            .fb-richtext-editor li {
                margin: 2px 0;
                display: list-item;
            }

            .dark .fb-prop-divider {
                border-color: #374151;
            }

            .dark .fb-richtext-wrapper {
                background: #111827;
                border-color: #374151;
            }

            .dark .fb-richtext-toolbar {
                background: #1f2937;
                border-color: #374151;
            }

            .dark .fb-rt-btn {
                color: #d1d5db;
            }

            .dark .fb-rt-btn:hover {
                background: #374151;
                color: #f9fafb;
            }

            .dark .fb-rt-sep {
                background: #4b5563;
            }

            .dark .fb-richtext-editor {
                color: #e5e7eb;
            }

            .dark .fb-richtext-editor:empty:before {
                color: #6b7280;
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('richTextEditor', (columnKey, initialContent) => ({
                    content: initialContent || '',
                    columnKey: columnKey,

                    init() {
                        this.$nextTick(() => {
                            if (this.$refs.editor) {
                                this.$refs.editor.innerHTML = this.content;
                            }
                        });
                    },

                    exec(command) {
                        document.execCommand(command, false, null);
                        this.$nextTick(() => {
                            this.content = this.$refs.editor.innerHTML;
                        });
                        this.$refs.editor.focus();
                    },

                    onInput(e) {
                        this.content = e.target.innerHTML;
                    },

                    save() {
                        this.content = this.$refs.editor.innerHTML;
                        @this.call('updateColumnValue', this.columnKey, this.content);
                    }
                }));
            });
        </script>
    @endpush
</x-filament-panels::page>