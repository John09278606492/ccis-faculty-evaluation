<x-filament-widgets::widget>
    <a href="{{ $url }}" style="display:flex;align-items:center;gap:14px;padding:14px 16px;border:1px solid #fcd34d;border-radius:12px;background:#fffbeb;color:#78350f;text-decoration:none;">
        <span style="flex:0 0 auto;min-width:44px;height:44px;padding:0 8px;display:grid;place-items:center;border-radius:10px;background:#f59e0b;color:#fff;font-size:20px;font-weight:700;">{{ $count }}</span>
        <span style="flex:1;min-width:0;">
            <span style="display:block;font-weight:700;">You have {{ $count }} {{ str('form')->plural($count) }} to sign</span>
            <span style="display:block;font-size:13px;">Tap to see them by subject.</span>
        </span>
        <span aria-hidden="true" style="font-size:20px;">&rsaquo;</span>
    </a>
</x-filament-widgets::widget>
