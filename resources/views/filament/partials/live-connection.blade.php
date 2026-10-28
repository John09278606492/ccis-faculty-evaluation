@if (auth()->check() && filament()->getTenant())
    <script type="application/json" id="classroom-live-config">{!! json_encode([
        'transport' => config('classroom.live_transport', 'reverb'),
        'key' => config('broadcasting.connections.reverb.key'),
        'host' => config('classroom.websocket_host'),
        'port' => config('classroom.websocket_port'),
        'scheme' => config('classroom.websocket_scheme'),
        'channel' => app()->environment().'.campus.'.filament()->getTenant()->id,
        'panelChannels' => array_merge(auth()->user()->isStudent()
            ? [app()->environment().'.panel']
            : [app()->environment().'.panel', app()->environment().'.panel.staff'],
            ['App.Models.User.'.auth()->id()]),
        'staff' => ! auth()->user()->isStudent(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <div id="classroom-live-status" role="status" style="position:fixed;right:12px;bottom:8px;z-index:30;padding:4px 8px;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:11px;pointer-events:none">Connecting live updates…</div>
    @vite('resources/js/live-updates.js')
@endif
