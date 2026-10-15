<div class="workbook-preview" x-data="{ selected: null, showFormulas: false, search: '' }">
    @if ($preview['error'] ?? null)
        <div class="wb-notice wb-danger" role="alert">{{ $preview['error'] }}</div>
    @else
        @php($plan = $preview['plan'])
        @if ($preview['blocking_reason'])
            <div class="wb-notice wb-danger" role="alert"><strong>Sync is blocked:</strong> {{ $preview['blocking_reason'] }}</div>
        @elseif ($plan)
            <div class="wb-notice wb-success" role="status">
                <strong>{{ $plan['matched'] }} of {{ $plan['roster_count'] }} students ready to sync.</strong>
                You can sync now; the remaining students do not have to finish first.
                {{ $plan['new_column'] ? 'A new column will be inserted at' : 'Scores will be updated in column' }} <strong>{{ $plan['column'] }}</strong>.
                Students without a final score keep their existing cells.
            </div>
        @endif
        @if ($plan)
            <div class="wb-notice wb-info">
                @if ($plan['opened_date'])
                    <strong>Opening date: {{ $plan['opened_date'] }}</strong> · {{ $plan['date_source'] }}.
                    {{ $plan['date_cell'] ? 'Written to '.$plan['date_cell'].' above the title.' : 'Included in the title cell because this template has no separate date row.' }}
                @else
                    No opening date or student start is recorded. Existing date cells will be left unchanged.
                @endif
            </div>
            @if (count($plan['unrecognized']))
                <div class="wb-notice wb-info">{{ count($plan['unrecognized']) }} worksheet ID(s) do not match this course roster and will be left unchanged: {{ implode(', ', array_slice($plan['unrecognized'], 0, 10)) }}{{ count($plan['unrecognized']) > 10 ? '…' : '' }}</div>
            @endif
            <div class="wb-stats">
                <div><strong>{{ $plan['matched'] }}</strong><span>Ready to write</span></div>
                <div><strong>{{ $plan['status_counts']['in_progress'] ?? 0 }}</strong><span>In progress</span></div>
                <div><strong>{{ $plan['status_counts']['submitted'] ?? 0 }}</strong><span>Needs grading</span></div>
                <div><strong>{{ $plan['status_counts']['not_started'] ?? 0 }}</strong><span>No attempt yet</span></div>
                <div><strong>{{ count($plan['scores_missing_from_sheet']) }}</strong><span>Final scores missing a row</span></div>
            </div>
        @endif
        @if ($preview['external_formula_count'])
            <div class="wb-notice wb-info">
                <strong>Linked Excel formulas detected.</strong>
                This worksheet contains {{ $preview['external_formula_count'] }} formula(s) referencing another workbook.
                They are preserved when syncing. Excel needs the linked workbook to calculate them; this does not block writing quiz scores.
            </div>
        @endif
        <div class="wb-toolbar">
            <div><strong>{{ $preview['filename'] }}</strong><span>{{ $preview['sheet'] }} · {{ $preview['proposed'] ? 'Proposed result — not saved yet' : 'Current saved workbook' }}</span></div>
            <label><input type="checkbox" x-model="showFormulas"> Show formula expressions</label>
        </div>
        <p class="wb-caption">Click a cell to inspect it. Green cells are proposed writes. Formula cells show ƒx until Excel recalculates; cached values in the inspector may be outdated. Logos, charts and print layout are not rendered.</p>
        <div class="wb-inspector" aria-live="polite">
            <strong x-text="selected ? selected.address : 'Cell inspector'"></strong>
            <span x-show="!selected">Select a worksheet cell to see its full contents.</span>
            <template x-if="selected"><div>
                <span x-text="selected.formula || selected.display || '(empty cell)'"></span>
                <small x-show="selected.formula && selected.cached !== null">Saved formula cache (may be outdated): <span x-text="selected.cached"></span></small>
                <small x-show="selected.changed">Proposed write · previous value: <span x-text="selected.before === null ? '(new / empty cell)' : selected.before"></span></small>
            </div></template>
        </div>
        <div class="wb-scroll" tabindex="0" role="region" aria-label="Worksheet preview: {{ $preview['sheet'] }}">
            <table class="wb-grid" style="width:{{ 46 + array_sum(array_column($preview['columns'], 'width')) }}px">
                <caption class="sr-only">{{ $preview['sheet'] }} — {{ $preview['proposed'] ? 'proposed scores' : 'saved workbook' }}</caption>
                <colgroup><col style="width:46px">@foreach ($preview['columns'] as $column)<col style="width:{{ $column['width'] }}px">@endforeach</colgroup>
                <thead><tr><th></th>@foreach ($preview['columns'] as $column)<th scope="col">{{ $column['letter'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr><th scope="row">{{ $row['number'] }}</th>
                            @foreach ($row['cells'] as $cell)
                                <td colspan="{{ $cell['colspan'] }}" rowspan="{{ $cell['rowspan'] }}" style="{{ $cell['style'] }}" @class(['wb-changed' => $cell['changed']])>
                                    <button type="button" title="{{ $cell['address'] }}{{ $cell['changed'] ? ' — proposed write' : '' }}" x-on:click="selected = {{ \Illuminate\Support\Js::from($cell) }}" aria-label="Cell {{ $cell['address'] }}: {{ $cell['formula'] ? 'formula' : $cell['display'] }}">
                                        @if ($cell['formula'])
                                            <span x-show="!showFormulas" class="wb-formula">ƒx</span><span x-show="showFormulas" x-cloak>{{ $cell['formula'] }}</span>
                                        @else
                                            <span class="wb-value">{{ $cell['display'] }}</span>
                                        @endif
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="wb-caption">Rows {{ $preview['first_row'] }}–{{ $preview['last_row'] }} of {{ $preview['total_rows'] }} · {{ count($preview['columns']) }} of {{ $preview['total_columns'] }} columns. Use “First preview row” to view later rows in large worksheets.</p>
        @if ($plan)
            <details class="wb-students" open>
                <summary>Student-by-student sync details ({{ $plan['roster_count'] }})</summary>
                <input type="search" x-model="search" placeholder="Find a student or ID…" aria-label="Search sync details" class="wb-search">
                <div class="wb-scroll wb-roster"><table class="wb-grid">
                    <thead><tr><th>Student</th><th>ID</th><th>Final score</th><th>Destination</th><th>What happens</th></tr></thead>
                    <tbody>@foreach ($plan['students'] as $student)
                        <tr x-show="{{ \Illuminate\Support\Js::from(mb_strtolower($student['name'].' '.$student['student_id'])) }}.includes(search.toLowerCase())">
                            <td>{{ $student['name'] }}</td><td>{{ $student['student_id'] ?: 'Missing ID' }}</td><td>{{ $student['score'] ?? '—' }}</td><td>{{ $student['cell'] ?? 'No matching row' }}</td>
                            <td @class(['wb-ready' => $student['ready']])>{{ $student['ready'] ? 'Write score' : 'Leave unchanged' }} · {{ $student['reason'] }}</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            </details>
        @endif
    @endif
    <style>
        /* Keep actions outside the scrolling body and contain sticky worksheet cells. */
        .workbook-preview-modal { max-height:calc(100dvh - 2rem); overflow:hidden; }
        .workbook-preview-modal > .fi-modal-content { min-height:0; overflow-y:auto; overscroll-behavior:contain; isolation:isolate; }
        .workbook-preview-modal > .fi-modal-header,
        .workbook-preview-modal > .fi-modal-footer { flex-shrink:0; position:relative; top:auto; bottom:auto; z-index:3; }
        .workbook-preview { min-width:0; color:inherit; font-size:14px; }
        .wb-notice { padding:12px 16px; border-radius:8px; margin-bottom:12px; line-height:1.6; }
        .wb-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .wb-danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .wb-info { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
        .wb-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(125px,1fr)); gap:8px; margin:12px 0; }
        .wb-stats>div { padding:12px; border:1px solid #cbd5e1; border-radius:8px; }
        .wb-stats strong { display:block; font-size:24px; } .wb-stats span { font-size:12px; }
        .wb-toolbar { display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:center; margin:16px 0 8px; }
        .wb-toolbar span { display:block; font-size:12px; margin-top:3px; } .wb-toolbar label { display:flex; gap:8px; align-items:center; }
        .wb-caption { font-size:12px; line-height:1.6; opacity:.8; margin:8px 0; }
        .wb-inspector { padding:10px 12px; border:1px solid #cbd5e1; border-bottom:0; display:flex; gap:12px; min-height:45px; overflow-wrap:anywhere; }
        .wb-inspector strong { min-width:72px; } .wb-inspector small { display:block; opacity:.75; }
        .wb-scroll { isolation:isolate; overflow:auto; max-height:520px; border:1px solid #94a3b8; border-radius:0 0 8px 8px; background:#fff; color:#111827; }
        .wb-grid { border-collapse:separate; border-spacing:0; table-layout:fixed; min-width:100%; font-size:12px; }
        .wb-grid th { background:#f1f5f9; color:#334155; padding:8px; font-weight:600; }
        .wb-grid thead th { position:sticky; top:0; z-index:2; } .wb-grid tbody th { position:sticky; left:0; z-index:1; }
        .wb-grid td,.wb-grid th { border-right:1px solid #cbd5e1; border-bottom:1px solid #cbd5e1; vertical-align:middle; }
        .wb-grid td { min-width:55px; } .wb-grid td button { display:block; width:100%; min-height:30px; text-align:inherit; padding:5px 8px; white-space:normal; overflow-wrap:anywhere; font:inherit; line-height:1.4; } .wb-value { white-space:pre-wrap; }
        .wb-grid td button:focus-visible { outline:3px solid #6366f1; outline-offset:-3px; } .wb-grid td button:hover { box-shadow:inset 0 0 0 2px #818cf8; }
        .wb-grid td.wb-changed { background:#dcfce7!important; color:#166534!important; box-shadow:inset 0 0 0 2px #22c55e; }
        .wb-formula { color:#64748b; font-style:italic; } .wb-students { margin-top:16px; } .wb-students summary { cursor:pointer; font-weight:600; padding:8px 0; }
        .wb-search { margin:8px 0; max-width:100%; border:1px solid #94a3b8; border-radius:6px; padding:8px; color:#111827; background:white; }
        .wb-roster { max-height:300px; } .wb-roster .wb-grid { min-width:680px; } .wb-roster td { padding:8px; } .wb-ready { color:#166534; background:#f0fdf4; }
    </style>
</div>
