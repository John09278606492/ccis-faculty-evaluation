<div x-data="{}" x-on:message.window="
    if ($event.origin === window.location.origin &amp;&amp; $event.source === $refs.reportPreview.contentWindow &amp;&amp; $event.data?.type === 'campus-report-close') {
        const modal = $el.closest('[data-fi-modal-id]');
        if (modal) $dispatch('close-modal', {id: modal.dataset.fiModalId});
    }
">
    <p class="mb-3 text-sm text-gray-500">{{ $hint ?? 'Choose Annex C for the faculty report or Annex D for the development acknowledgement. Use Print / Save PDF inside the preview.' }}</p>
    <iframe data-live-report x-ref="reportPreview" @isset($urlExpression) x-bind:src="{{ $urlExpression }}" @else src="{{ $url }}" @endisset title="{{ $title ?? 'Report print preview' }}" style="width:100%;height:75vh;min-height:480px;border:1px solid #d1d5db;border-radius:8px;background:white"></iframe>
</div>
