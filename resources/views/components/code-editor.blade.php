{{--
    CodeMirror editor loaded from this server (see resources/js/components/code-editor.js).
    Pass `state-path` to bind it to a Livewire property, or `value` to show fixed code.
--}}
@props([
    'statePath' => null,
    'value' => null,
    'initialValue' => '',
    'language' => 'plaintext',
    'readOnly' => false,
    'commitDebounce' => null,
    'placeholder' => '',
    'minHeight' => '12rem',
    'maxHeight' => '32rem',
])

<div {{ $attributes->class('cq-code-editor overflow-hidden rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-900') }}>
    <div class="flex items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">
        <span class="font-mono font-semibold">{{ \App\Models\QuizQuestion::CODE_LANGUAGES[$language] ?? $language }}</span>
        @unless ($readOnly)
            <span>Tab indents · Esc then Tab leaves the editor</span>
        @endunless
    </div>
    <div
        {{-- prettier-ignore-start --}}x-load="visible || event (ax-modal-opened)"
        {{-- prettier-ignore-end --}}
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-editor') }}"
        x-data="codeEditorComponent({
            state: {{ $statePath ? "\$wire.\$entangle('{$statePath}')" : \Illuminate\Support\Js::from($value) }},
            initialValue: @js($initialValue),
            language: @js($language),
            readOnly: @js((bool) $readOnly),
            commitDebounce: @js($commitDebounce),
            placeholder: @js($placeholder),
            minHeight: @js($minHeight),
            maxHeight: @js($maxHeight),
        })"
        wire:ignore
    >
        <div x-ref="editor"></div>
    </div>
</div>
