<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <x-code-editor
        :state-path="$getStatePath()"
        :language="$getLanguage()"
        :read-only="$isDisabled()"
        :placeholder="$getPlaceholder()"
        min-height="10rem"
        wire:key="{{ $getStatePath() }}-{{ $getLanguage() }}"
    />
</x-dynamic-component>
