<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <x-code-editor
        :state-path="$getStatePath()"
        :starter-files="$getStarterFiles()"
        :read-only="$isDisabled()"
        :can-manage-files="$getCanManageFiles() && ! $isDisabled()"
        :can-run="$getCanRun()"
        :placeholder="$getPlaceholder()"
        min-height="10rem"
        wire:key="{{ $getStatePath() }}-{{ md5(json_encode($getStarterFiles())) }}"
    />
</x-dynamic-component>
