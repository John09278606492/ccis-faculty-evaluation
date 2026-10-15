<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Instructor-side editor: suggestions on, since it is not an exam. --}}
    <x-code-editor
        :state-path="$getStatePath()"
        :starter-files="$getStarterFiles()"
        :read-only="$isDisabled()"
        :can-manage-files="$getCanManageFiles() && ! $isDisabled()"
        :can-run="$getCanRun()"
        autocomplete
        :placeholder="$getPlaceholder()"
        min-height="10rem"
        max-height="24rem"
        wire:key="{{ $getStatePath() }}-{{ md5(json_encode($getStarterFiles())) }}"
    />
</x-dynamic-component>
