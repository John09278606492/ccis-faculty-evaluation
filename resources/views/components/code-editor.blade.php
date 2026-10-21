{{--
    VS Code-style code workspace (see resources/js/components/code-editor.js).
    Pass `state-path` to bind it to a Livewire property holding {"files": [...]} JSON,
    or `value` to show fixed files. `starter-files` (files array or project JSON)
    fills the workspace while the bound value is still empty.
--}}
@props([
    'statePath' => null,
    'value' => null,
    'starterFiles' => [],
    'readOnly' => false,
    'canManageFiles' => false,
    'canRun' => false,
    'autocomplete' => false,
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
        statePath: @js($statePath),
        state: {{ $statePath ? "\$wire.\$entangle('{$statePath}')" : \Illuminate\Support\Js::from($encode($value)) }},
        initialValue: @js($encode($starterFiles)),
        readOnly: @js((bool) $readOnly),
        canManageFiles: @js((bool) $canManageFiles),
        canRun: @js((bool) $canRun),
        autocomplete: @js((bool) $autocomplete),
        runnerBaseUrl: @js(asset('code-runner')),
        commitDebounce: @js($commitDebounce),
        placeholder: @js($placeholder),
        maxHeight: @js($maxHeight),
    })"
    x-bind:class="{ 'cq-maximized': maximized }"
    x-bind:style="{ height: workspaceHeight, minHeight: @js($minHeight) }"
    x-on:keydown="shortcut($event)"
    x-on:visibilitychange.window="document.visibilityState === 'hidden' && saveOnLeave()"
    x-on:beforeunload.window="saveOnLeave()"
    x-on:pagehide.window="saveOnLeave()"
    wire:ignore
>
    <div class="cq-body">
        {{-- Explorer --}}
        <aside class="cq-sidebar" x-show="sidebarOpen" x-cloak aria-label="Explorer">
            <div class="cq-sidebar-title">
                <span>Explorer</span>
                <div class="cq-sidebar-actions" x-show="canManageFiles">
                    <button type="button" title="New File…" aria-label="New file" x-on:click="startCreate('file')">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 1.1 13 4.6V8h-1V5H9V2H4v12h4v1H3V1h6.5zM10 2.4V4h1.6L10 2.4zM13 10h2v1h-2v2h-1v-2h-2v-1h2V8h1v2z" fill="currentColor"/></svg>
                    </button>
                    <button type="button" title="New Folder…" aria-label="New folder" x-on:click="startCreate('folder')">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M14 4H8.7L7.4 2.7 7 2.5H2l-.5.5v10l.5.5h6v-1H2V6h12v2h1V4.5l-.5-.5h-.5zM14 5H7.7L6.4 3.6 6 3.5H2.5v1H6.3l1 1H14v-.5zm-1 6h2v1h-2v2h-1v-2h-2v-1h2V9h1v2z" fill="currentColor"/></svg>
                    </button>
                    <button type="button" title="Collapse Folders" aria-label="Collapse folders" x-on:click="allFolders.forEach((folder) => collapsed[folder] = true)">
                        <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M9 9H4v1h5V9zM9 4.5 9.5 4h5l.5.5v10l-.5.5h-10l-.5-.5v-5H5V14h9V5H9.5L9 4.5zM2.5 1h5l.5.5v5l-.5.5h-5L2 6.5v-5l.5-.5zM3 6h4V2H3v4z" fill="currentColor"/></svg>
                    </button>
                </div>
            </div>
            <div class="cq-section-title">Workspace</div>
            <ul class="cq-tree" role="tree" x-on:keydown.f2="$event.target.tagName !== 'INPUT' && selected && ($event.preventDefault(), startRename(selected))" x-on:keydown.delete="$event.target.tagName !== 'INPUT' && selected && ($event.preventDefault(), remove(selected))">
                <template x-for="row in explorerRows" :key="row.key">
                    <li role="treeitem" :aria-expanded="row.type === 'folder' ? String(row.open) : null"
                        :style="{ paddingLeft: (8 + row.depth * 12) + 'px' }"
                        :class="{ 'cq-row-selected': row.path && selected === row.path, 'cq-row-active': row.type === 'file' && activeName === row.path }">
                        <template x-if="row.type === 'new'">
                            <div class="cq-row-edit">
                                <span class="cq-row-icon" x-text="row.kind === 'folder' ? '▸' : '·'"></span>
                                <input type="text" class="cq-name-input" :placeholder="row.kind === 'folder' ? 'folder name' : 'file.html'"
                                    :aria-label="row.kind === 'folder' ? 'New folder name' : 'New file name'"
                                    x-init="$nextTick(() => $el.focus())"
                                    x-on:keydown.enter.prevent.stop="confirmCreate($el.value)"
                                    x-on:keydown.escape.prevent.stop="creating = null; fileError = ''"
                                    x-on:blur="$el.value.trim() ? confirmCreate($el.value) : (creating = null)">
                            </div>
                        </template>
                        <template x-if="row.type !== 'new' && renaming === row.path">
                            <div class="cq-row-edit">
                                <input type="text" class="cq-name-input" :value="row.name" aria-label="New name"
                                    x-init="$nextTick(() => { $el.focus(); $el.setSelectionRange(0, row.type === 'file' && row.name.includes('.') ? row.name.lastIndexOf('.') : row.name.length) })"
                                    x-on:keydown.enter.prevent.stop="confirmRename(row.path, $el.value)"
                                    x-on:keydown.escape.prevent.stop="renaming = null"
                                    x-on:blur="confirmRename(row.path, $el.value)">
                            </div>
                        </template>
                        <template x-if="row.type !== 'new' && renaming !== row.path">
                            <div class="cq-row">
                                <button type="button" class="cq-row-main" x-on:click="clickRow(row)" x-on:dblclick="startRename(row.path)" :title="row.path">
                                    <template x-if="row.type === 'folder'">
                                        <span class="cq-chevron" :class="{ 'cq-chevron-open': row.open }" aria-hidden="true">›</span>
                                    </template>
                                    <template x-if="row.type === 'file'">
                                        <span class="cq-file-icon" :style="{ color: icon(row.path)[1] }" x-text="icon(row.path)[0]" aria-hidden="true"></span>
                                    </template>
                                    <span class="cq-row-name" x-text="row.name"></span>
                                </button>
                                <span class="cq-row-actions" x-show="canManageFiles">
                                    <button type="button" title="Rename (F2)" :aria-label="'Rename ' + row.path" x-on:click="startRename(row.path)">✎</button>
                                    <button type="button" title="Delete" :aria-label="'Delete ' + row.path" x-on:click="remove(row.path)">✕</button>
                                </span>
                            </div>
                        </template>
                    </li>
                </template>
            </ul>
            <p class="cq-file-error" x-show="fileError" x-text="fileError" role="alert"></p>
        </aside>

        <section class="cq-main">
            {{-- Open editors --}}
            <div class="cq-tabbar">
                <button type="button" class="cq-icon-button" title="Toggle Explorer (Ctrl+B)" aria-label="Toggle Explorer" x-on:click="sidebarOpen = ! sidebarOpen">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M14.5 2h-13l-.5.5v11l.5.5h13l.5-.5v-11l-.5-.5zM14 13H6V3h8v10zM5 3v10H2V3h3z" fill="currentColor"/></svg>
                </button>
                <div class="cq-tabs" role="tablist" aria-label="Open files">
                    <template x-for="path in openTabs" :key="path">
                        <div class="cq-tab" :class="{ 'cq-tab-active': activeName === path }" x-on:mousedown.middle.prevent="closeTab(path)">
                            <button type="button" role="tab" :aria-selected="activeName === path" x-on:click="openFile(path)" :title="path">
                                <span class="cq-file-icon" :style="{ color: icon(path)[1] }" x-text="icon(path)[0]" aria-hidden="true"></span>
                                <span x-text="path.split('/').pop()"></span>
                            </button>
                            <button type="button" class="cq-tab-close" :aria-label="'Close ' + path" title="Close" x-on:click.stop="closeTab(path)">×</button>
                        </div>
                    </template>
                </div>
                <div class="cq-tabbar-actions">
                    <button type="button" class="cq-toggle" x-show="canRun && runner !== 'sql'"
                        x-on:click="toggleLivePreview()"
                        x-bind:class="{ 'cq-toggle-on': livePreview }"
                        x-bind:aria-pressed="livePreview ? 'true' : 'false'"
                        title="Update the preview as you type">
                        <span>Live</span>
                    </button>
                    <button type="button" class="cq-toggle" x-show="canRun && run.hasPreview"
                        x-on:click="toggleSideBySide()"
                        x-bind:class="{ 'cq-toggle-on': sideBySide }"
                        x-bind:aria-pressed="sideBySide ? 'true' : 'false'"
                        title="Show the preview next to the code">
                        <span>Split</span>
                    </button>
                    <button type="button" class="cq-run" x-show="canRun" x-on:click="runCode()" x-bind:disabled="run.running" title="Run (Ctrl+Enter)">
                        <span x-text="run.running ? 'Running…' : '▶ Run'"></span>
                    </button>
                    <button type="button" class="cq-icon-button" x-on:click="maximized = ! maximized; $nextTick(() => $el.focus())" :title="maximized ? 'Restore' : 'Maximize workspace'" :aria-label="maximized ? 'Restore workspace size' : 'Maximize workspace'">
                        <svg x-show="! maximized" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 3v10h10V3H3zm9 9H4V4h8v8z" fill="currentColor"/></svg>
                        <svg x-show="maximized" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 5v9h9V5H3zm8 8H4V6h7v7z M5 5h1V4h7v7h-1v1h2V3H5v2z" fill="currentColor"/></svg>
                    </button>
                </div>
            </div>
            <div class="cq-breadcrumbs" x-show="activeName" aria-label="File path">
                <template x-for="(crumb, index) in breadcrumbs" :key="index">
                    <span><span x-show="index > 0" class="cq-crumb-sep">›</span><span x-text="crumb"></span></span>
                </template>
            </div>

            <div class="cq-editor-area" :class="{ 'cq-editor-area-split': sideBySide }">
                <div class="cq-editor-pane">
                    <div x-ref="editor" class="cq-cm" x-show="activeName"></div>
                    <div class="cq-empty" x-show="! activeName" x-cloak>
                        <p>No file is open.</p>
                        <p>Open one from the Explorer, or press <kbd>Ctrl</kbd>+<kbd>P</kbd>.</p>
                    </div>
                </div>

                {{-- The preview, next to the code when it is split --}}
                <div class="cq-split-preview" x-ref="splitHost" x-show="sideBySide" x-cloak>
                    <ol class="cq-console cq-console-split" x-show="run.console.length" aria-label="Console output">
                        <template x-for="(line, lineIndex) in run.console" :key="lineIndex">
                            <li :class="'cq-console-' + line.level" x-text="line.text"></li>
                        </template>
                    </ol>
                </div>
            </div>

            {{-- Output panel --}}
            <div class="cq-panel" x-show="run.visible && ! sideBySide" x-cloak>
                <div class="cq-panel-bar">
                    <span class="cq-panel-title">Output</span>
                    <select x-show="pages.length > 1" aria-label="Page to open" x-on:change="runCode($event.target.value)">
                        <template x-for="page in pages" :key="page">
                            <option :value="page" x-text="page" :selected="page === run.page"></option>
                        </template>
                    </select>
                    <span class="cq-status" x-text="run.status" role="status"></span>
                    <button type="button" x-on:click="run.visible = false" aria-label="Close output" title="Close panel">×</button>
                </div>
                <div class="cq-panel-body" x-ref="panelHost">
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
        </section>
    </div>

    {{-- Status bar --}}
    <footer class="cq-statusbar" :class="{ 'cq-statusbar-readonly': readOnly }">
        <span class="cq-status-item" title="Tab indents · Esc then Tab leaves the editor · Ctrl+P open file · Ctrl+B toggle Explorer · Ctrl+S save · Ctrl+Enter run">
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M14.5 3H7.7L6.4 1.6 6 1.5H1.5l-.5.5v11l.5.5h13l.5-.5V3.5l-.5-.5zM14 13H2V6h12v7zm0-8H2V2.5h3.8l1.3 1.4.4.1H14V5z" fill="currentColor"/></svg>
            <span x-text="files.length + (files.length === 1 ? ' file' : ' files')"></span>
        </span>
        <span class="cq-status-item" x-show="readOnly">Read-only</span>
        <span class="cq-status-item" x-show="! readOnly && saveState === 'saving'">Saving…</span>
        <span class="cq-status-item" x-show="! readOnly && saveState === 'unsaved'">● Unsaved</span>
        <span class="cq-status-item" x-show="! readOnly && saveState === 'saved'">✓ Saved</span>
        <span class="cq-status-item cq-status-error" x-show="! readOnly && saveState === 'rejected'" title="See the message below the editor">⚠ Not saved</span>
        <button type="button" class="cq-status-item cq-status-button" x-show="canReset" x-on:click="resetToStarter()" title="Replace your files with the starter files">↺ Reset</button>
        <span class="cq-status-spacer"></span>
        <span class="cq-status-item" x-show="activeName" x-text="'Ln ' + cursor.line + ', Col ' + cursor.column + (cursor.selected ? ' (' + cursor.selected + ' selected)' : '')"></span>
        <span class="cq-status-item cq-status-wide" x-show="activeName">Spaces: 4</span>
        <span class="cq-status-item cq-status-wide" x-show="activeName">UTF-8</span>
        <span class="cq-status-item" x-show="activeName" x-text="activeLanguage"></span>
    </footer>

    {{-- Quick open (Ctrl+P) --}}
    <div class="cq-quick-open" x-show="quickOpen.open" x-cloak x-on:click.self="quickOpen.open = false" x-on:keydown.escape.stop="quickOpen.open = false">
        <div class="cq-quick-open-box" role="dialog" aria-label="Go to file">
            <input type="text" x-ref="quickOpenInput" x-model="quickOpen.query" placeholder="Search files by name"
                aria-label="Search files by name" x-on:input="quickOpen.index = 0" x-on:keydown="quickOpenKey($event)">
            <ul>
                <template x-for="(path, index) in quickOpenResults" :key="path">
                    <li :class="{ 'cq-quick-active': index === quickOpen.index }" x-on:mouseenter="quickOpen.index = index"
                        x-on:click="quickOpen.open = false; openFile(path)">
                        <span class="cq-file-icon" :style="{ color: icon(path)[1] }" x-text="icon(path)[0]" aria-hidden="true"></span>
                        <span x-text="path.split('/').pop()"></span>
                        <span class="cq-quick-path" x-text="path"></span>
                    </li>
                </template>
                <li class="cq-quick-empty" x-show="! quickOpenResults.length">No matching files</li>
            </ul>
        </div>
    </div>
</div>
