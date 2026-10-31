<style>
    .evaluation-rating-guide { position: fixed; top: 0; z-index: 50; overflow: hidden; background: white; box-shadow: 0 3px 8px #0002; pointer-events: none; }
    .evaluation-rating-guide[hidden] { display: none; }
    .evaluation-rating-guide-title { background: #340167; color: white; padding: 10px 16px; font-weight: 600; }
    .evaluation-rating-guide table { table-layout: fixed; margin: 0; background: #f9fafb; }
    .evaluation-rating-guide th { box-sizing: border-box; }
    [data-rating-section] input { scroll-margin-top: var(--rating-guide-height, 160px); }
    .rating-mini-label { display: none; }
    /* Phones: each criterion becomes a card with every rating choice visible, no sideways scrolling. */
    @media (max-width: 640px) {
        .eval-table, .eval-table tbody { display: block; width: 100%; }
        .eval-table thead { display: none; }
        .eval-table tr { display: grid; grid-template-columns: repeat(var(--scale-count, 5), minmax(0, 1fr)); gap: 2px; padding: 12px 10px; border-bottom: 1px solid #e5e7eb; }
        .eval-table td { display: block; border: 0 !important; padding: 0 !important; min-width: 0 !important; width: auto !important; }
        .eval-table td:first-child, .eval-table td.eval-col-uniform { grid-column: 1 / -1; padding-bottom: 8px !important; }
        .eval-table .rating-label { flex-direction: column; align-items: center; gap: 4px; padding: 4px 0; }
        .eval-table .rating-circle { width: 2.6rem; height: 2.6rem; font-size: 1rem; }
        .rating-mini-label { display: block; font-size: 10px; line-height: 1.15; color: #6b7280; text-align: center; overflow-wrap: anywhere; }
    }
    @media print { .evaluation-rating-guide { display: none !important; } }
</style>
<script src="{{ asset('js/app/evaluation-rating-guide.js') }}?v={{ filemtime(public_path('js/app/evaluation-rating-guide.js')) }}" defer></script>
