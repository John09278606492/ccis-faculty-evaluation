{{--
    Code editor with file tabs and a Run panel (see resources/js/components/code-editor.js).
    Pass `state-path` to bind it to a Livewire property holding {"files": [...]} JSON,
    or `value` to show fixed files. `starter-files` fills the editor while the bound
    value is still empty.
--}}
@props([
    'statePath' => null,
    'value' => null,
    'starterFiles' => [],
    'readOnly' => false,
    'canManageFiles' => false,
    'canRun' => false,
    'commitDebounce' => null,
    'placeholder' => '',
    'minHeight' => '12rem',
    'maxHeight' => '32rem',
])

@php
    $encode = fn ($files) => is_array($files) ? \App\Support\CodeProject::encode($files) : $files;
@endphp

<div
    {{ $attributes->class('cq-editor') }}
    {{-- prettier-ignore-start --}}x-load="visible || event (ax-modal-opened)"
    {{-- prettier-ignore-end --}}
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('code-editor') }}"
    x-data="codeEditorComponent({
        state: {{ $statePath ? "\$wire.\$entangle('{$statePath}')" : \Illuminate\Support\Js::from($encode($value)) }},
        initialValue: @js($encode($starterFiles)),
        readOnly: @js((bool) $readOnly),
        canManageFiles: @js((bool) $canManageFiles),
        canRun: @js((bool) $canRun),
        runnerBaseUrl: @js(asset('code-runner')),
        commitDebounce: @js($commitDebounce),
        placeholder: @js($placeholder),
        minHeight: @js($minHeight),
        maxHeight: @js($maxHeight),
    })"
    wire:ignore
>
    <div class="cq-toolbar">
        <div class="cq-tabs" role="tablist" aria-label="Files">
            <template x-for="(file, index) in files" :key="index">
                <div class="cq-tab" :class="{ 'cq-tab-active': active === index }">
                    <template x-if="renaming !== index">
                        <button type="button" role="tab" :aria-selected="active === index" x-text="file.name"
                            x-on:click="openFile(index)"
                            x-on:dblclick="if (canManageFiles) renaming = index"
                            :title="canManageFiles ? 'Double-click to rename' : file.name"></button>
                    </template>
                    <template x-if="renaming === index">
                        <input type="text" class="cq-name-input" :value="file.name" aria-label="File name"
                            x-init="$nextTick(() => $el.select())"
                            x-on:keydown.enter.prevent="renameFile(index, $el.value)"
                            x-on:keydown.escape.prevent="renaming = null"
                            x-on:blur="renameFile(index, $el.value)">
                    </template>
                    <button type="button" class="cq-tab-remove" x-show="canManageFiles && files.length > 1"
                        x-on:click="removeFile(index)" :aria-label="'Delete ' + file.name" title="Delete file">&times;</button>
                </div>
            </template>
            <template x-if="canManageFiles">
                <div class="cq-tab cq-tab-new">
                    <template x-if="newFileName === null">
                        <button type="button" x-on:click="newFileName = ''" title="Add a file">+ File</button>
                    </template>
                    <template x-if="newFileName !== null">
                        <input type="text" class="cq-name-input" placeholder="style.css" aria-label="New file name"
                            x-model="newFileName" x-init="$nextTick(() => $el.focus())"
                            x-on:keydown.enter.prevent="addFile()"
                            x-on:keydown.escape.prevent="newFileName = null; fileError = ''">
                    </template>
                </div>
            </template>
        </div>
        @unless ($readOnly)
            <span class="cq-hint">Tab indents · Esc then Tab leaves the editor</span>
        @endunless
        <button type="button" class="cq-run" x-show="canRun" x-on:click="runCode()" x-bind:disabled="run.running">
            <span x-text="run.running ? 'Running…' : '▶ Run'"></span>
        </button>
    </div>
    <p class="cq-file-error" x-show="fileError" x-text="fileError" role="alert"></p>

    <div x-ref="editor"></div>

    <div class="cq-output" x-show="run.visible" x-cloak>
        <div class="cq-output-bar">
            <strong>Output</strong>
            <select x-show="pages.length > 1" aria-label="Page to open" x-on:change="runCode($event.target.value)">
                <template x-for="page in pages" :key="page">
                    <option :value="page" x-text="page" :selected="page === run.page"></option>
                </template>
            </select>
            <span class="cq-status" x-text="run.status" role="status"></span>
            <button type="button" x-on:click="run.visible = false" aria-label="Close output">&times;</button>
        </div>
        <iframe x-ref="preview" x-show="run.hasPreview" class="cq-preview" title="Program output"
            sandbox="allow-scripts allow-forms allow-modals"></iframe>
        <template x-for="(table, tableIndex) in run.tables" :key="tableIndex">
            <div class="cq-table-wrap">
                <table class="cq-table">
                    <thead><tr><template x-for="column in table.columns"><th x-text="column"></th></template></tr></thead>
                    <tbody>
                        <template x-for="(row, rowIndex) in table.values.slice(0, 200)" :key="rowIndex">
                            <tr><template x-for="cell in row"><td x-text="cell === null ? 'NULL' : cell"></td></template></tr>
                        </template>
                    </tbody>
                </table>
                <p class="cq-status" x-show="table.values.length > 200" x-text="'Showing 200 of ' + table.values.length + ' rows.'"></p>
            </div>
        </template>
        <ol class="cq-console" x-show="run.console.length" aria-label="Console output">
            <template x-for="(line, lineIndex) in run.console" :key="lineIndex">
                <li :class="'cq-console-' + line.level" x-text="line.text"></li>
            </template>
        </ol>
    </div>
</div>
