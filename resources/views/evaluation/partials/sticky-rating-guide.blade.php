<style>
    .evaluation-rating-guide { position: fixed; top: 0; z-index: 50; overflow: hidden; background: white; box-shadow: 0 3px 8px #0002; pointer-events: none; }
    .evaluation-rating-guide[hidden] { display: none; }
    .evaluation-rating-guide-title { background: #340167; color: white; padding: 10px 16px; font-weight: 600; }
    .evaluation-rating-guide table { table-layout: fixed; margin: 0; background: #f9fafb; }
    .evaluation-rating-guide th { box-sizing: border-box; }
    [data-rating-section] input { scroll-margin-top: var(--rating-guide-height, 160px); }
    @media print { .evaluation-rating-guide { display: none !important; } }
</style>
<script src="{{ asset('js/app/evaluation-rating-guide.js') }}?v={{ filemtime(public_path('js/app/evaluation-rating-guide.js')) }}" defer></script>
