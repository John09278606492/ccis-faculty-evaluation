<div class="toolbar" style="gap:12px;flex-wrap:wrap">
    <div><strong>{{ $annex === 'C' ? 'Faculty Evaluation Report' : 'Development Acknowledgement' }}</strong></div>
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>
<p class="no-print" style="margin:0;padding:6px 24px;background:white;font-size:12px;color:#536273">Print: A4 · Portrait · 100% scale. Browser headers and footers are hidden automatically in current Chrome and Edge.</p>
<script>
window.addEventListener('beforeprint', function () {
    document.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(function (control) {
        const output = control.nextElementSibling;
        if (!output || !output.classList.contains('print-text')) return;
        if (control.tagName === 'SELECT') {
            if (control.value) output.textContent = control.options[control.selectedIndex].text;
        } else {
            output.textContent = control.value.trim() || '________________________';
        }
    });
});
</script>
<div class="no-print" style="padding:14px 24px;background:white;border-bottom:1px solid #d1d5db;display:flex;gap:16px;flex-wrap:wrap">
    <div><label for="report-format" style="margin:0 0 4px;font-size:12px">Report</label><select id="report-format" onchange="window.location.href=this.value" style="padding:8px;max-width:100%">
        <option value="{{ route('evaluation.report', ['offering' => $offering, 'annex' => 'C', ...request()->only(['department', 'form', 'cycle'])]) }}" @selected($annex === 'C')>Annex C — Faculty Evaluation Report</option>
        <option value="{{ route('evaluation.report', ['offering' => $offering, 'annex' => 'D', ...request()->only(['department', 'form', 'cycle'])]) }}" @selected($annex === 'D')>Annex D — Development Acknowledgement</option>
    </select></div>
    <div><label for="report-period" style="margin:0 0 4px;font-size:12px">Reporting Period</label><select id="report-period" onchange="window.location.href=this.value" style="padding:8px;max-width:100%">@foreach($periods as $period)<option value="{{ route('evaluation.report', ['offering' => $period, 'annex' => $annex, ...request()->only(['department', 'form'])]) }}" @selected($period->semester_id === $offering->semester_id)>{{ $period->semester->name }} / {{ $period->semester->schoolYear->name }}</option>@endforeach</select></div>
</div>
