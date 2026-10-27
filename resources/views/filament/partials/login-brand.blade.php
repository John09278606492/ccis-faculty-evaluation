{{-- Branded sign-in: campus photo with the system's name beside the sign-in card. Plain CSS, so no asset rebuild is needed. --}}
@php
    $brand = config('classroom.login');
    $image = filled($brand['image']) && is_file(public_path($brand['image'])) ? asset($brand['image']) : null;
    $logo = filled($brand['logo']) && is_file(public_path($brand['logo'])) ? asset($brand['logo']) : null;
@endphp
<style>
    .fi-simple-layout { position: relative; isolation: isolate; background: #0b1530; }
    .fi-simple-layout::before {
        content: ''; position: fixed; inset: 0; z-index: -2;
        background: {!! $image ? "url('".e($image)."') center / cover no-repeat" : 'radial-gradient(1200px 600px at 15% 20%, #1e3a8a 0%, transparent 60%), radial-gradient(900px 500px at 85% 90%, #312e81 0%, transparent 60%), #0b1530' !!};
    }
    .fi-simple-layout::after {
        content: ''; position: fixed; inset: 0; z-index: -1;
        background: linear-gradient(100deg, rgba(8, 15, 40, .88) 0%, rgba(8, 15, 40, .62) 48%, rgba(8, 15, 40, .35) 100%);
    }
    .fi-simple-main { border-radius: 18px !important; box-shadow: 0 24px 60px rgba(2, 6, 23, .45) !important; }
    .ccis-brand { position: relative; z-index: 10; color: #fff; text-align: center; padding: 28px 20px 0; }
    .ccis-brand-logo { width: 72px; height: 72px; object-fit: contain; margin: 0 auto 14px; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, .35)); }
    .ccis-brand-campus { font-size: 12px; letter-spacing: .18em; text-transform: uppercase; color: #fcd34d; font-weight: 600; }
    .ccis-brand-title { font-size: 34px; line-height: 1.1; font-weight: 800; letter-spacing: .04em; margin: 8px 0 10px; }
    .ccis-brand-tagline { font-size: 15px; line-height: 1.55; color: #cbd5e1; max-width: 34rem; margin: 0 auto; }
    .ccis-brand-rule { width: 56px; height: 3px; border-radius: 2px; background: #fcd34d; margin: 18px auto 0; }
    .ccis-brand-footer { position: fixed; z-index: 10; bottom: 14px; left: 0; right: 0; text-align: center; font-size: 12px; color: rgba(226, 232, 240, .75); }
    @media (min-width: 1024px) {
        .fi-simple-main-ctn { justify-content: flex-end !important; padding-right: 7vw; }
        .ccis-brand { position: fixed; left: 7vw; top: 50%; transform: translateY(-50%); width: 40vw; max-width: 560px; text-align: left; padding: 0; }
        .ccis-brand-logo { margin: 0 0 18px; width: 88px; height: 88px; }
        .ccis-brand-title { font-size: 52px; }
        .ccis-brand-tagline { margin: 0; font-size: 17px; }
        .ccis-brand-rule { margin: 22px 0 0; }
    }
    @media (max-width: 1023px) {
        .ccis-brand { position: absolute; top: 0; left: 0; right: 0; }
        .fi-simple-main-ctn { padding-top: 235px; }
        .fi-simple-main { margin-top: 0 !important; }
        .ccis-brand-tagline, .ccis-brand-rule, .ccis-brand-footer { display: none; }
    }
</style>
<div class="ccis-brand">
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="ccis-brand-logo">
    @endif
    @if (filled($brand['campus']))
        <div class="ccis-brand-campus">{{ $brand['campus'] }}</div>
    @endif
    <div class="ccis-brand-title">{{ $brand['title'] }}</div>
    @if (filled($brand['tagline']))
        <p class="ccis-brand-tagline">{{ $brand['tagline'] }}</p>
    @endif
    <div class="ccis-brand-rule"></div>
</div>
<div class="ccis-brand-footer">© {{ now()->year }} {{ $brand['campus'] ?: config('app.name') }}</div>
