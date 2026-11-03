<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $status }} · {{ $title }}</title>
    <style>
        :root{color-scheme:light;font-family:Arial,Helvetica,sans-serif;color:#17233b;background:#f4f6fb}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:28px;background:radial-gradient(ellipse at 10% 0%,#e4e8ff,transparent 55%),radial-gradient(ellipse at 100% 100%,#e4f2ff,transparent 50%),#f4f6fb}
        .frame{width:100%;max-width:850px}.brand{display:flex;align-items:center;gap:10px;margin:0 0 20px 4px;font-size:14px;font-weight:700;color:#354462}.brand svg{width:30px;height:30px;color:#4f46e5}
        .card{background:white;border:1px solid #dce3f0;border-radius:22px;box-shadow:0 20px 60px #1c2f5210;overflow:hidden}.accent{height:6px;background:linear-gradient(90deg,#4f46e5,#818cf8,#38bdf8)}
        .content{padding:40px;display:grid;grid-template-columns:128px 1fr;gap:30px;align-items:start}.illustration{position:relative;padding-top:12px}.illustration svg{width:118px;height:144px}.code{display:inline-block;padding:6px 10px;border-radius:30px;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:700;letter-spacing:.03em}
        h1{font-size:28px;line-height:1.2;letter-spacing:-.5px;margin:16px 0 12px}p{font-size:15px;line-height:1.65;color:#56657e;margin:0 0 16px}.reference{padding:12px 14px;border:1px solid #e0e6ef;border-radius:10px;background:#f8faff;margin:20px 0}.reference span{display:block;color:#66758e;font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:5px}.reference code{font:14px ui-monospace,Consolas,monospace;color:#28395a;user-select:all;overflow-wrap:anywhere}
        .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}button,a.button{font:600 14px Arial,Helvetica,sans-serif;padding:11px 16px;border:1px solid #cdd6e5;border-radius:9px;cursor:pointer;text-decoration:none;color:#33425c;background:white;display:inline-flex;align-items:center;justify-content:center;min-height:42px}button.primary,a.primary{color:white;background:#4f46e5;border-color:#4f46e5}button:hover,a.button:hover{filter:brightness(.95)}button:focus-visible,a:focus-visible{outline:3px solid #93c5fd;outline-offset:3px}[hidden]{display:none!important}
        .footer{border-top:1px solid #edf0f6;padding:16px 40px;color:#718096;font-size:12px;line-height:1.6;background:#fcfdff}
        @media(max-width:600px){body{padding:16px}.content{padding:25px;grid-template-columns:1fr;gap:8px}.illustration{display:none}h1{font-size:24px}.footer{padding:16px 25px}.actions>*{flex:1 1 auto}}
    </style>
</head>
<body>
<main class="frame">
    <div class="brand"><svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><rect x="1" y="1" width="30" height="30" rx="9" fill="currentColor"/><path d="M9 10h14M9 16h10M9 22h7" stroke="white" stroke-width="2.5" stroke-linecap="round"/></svg>Faculty Evaluation MIS</div>
    <section class="card" aria-labelledby="error-heading">
        <div class="accent"></div>
        <div class="content">
            <div class="illustration" aria-hidden="true"><svg viewBox="0 0 128 156" fill="none"><circle cx="64" cy="78" r="61" fill="#eef2ff"/><rect x="31" y="20" width="70" height="104" rx="10" fill="white" stroke="#b9c4f5" stroke-width="2"/><path d="M46 43h38M46 55h30M46 67h22" stroke="#c7d2fe" stroke-width="5" stroke-linecap="round"/><circle cx="77" cy="112" r="26" fill="#4f46e5"/><path d="M77 98v16M77 123v1" stroke="white" stroke-width="4" stroke-linecap="round"/><circle cx="17" cy="32" r="5" fill="#a5b4fc"/><circle cx="111" cy="63" r="4" fill="#7dd3fc"/></svg></div>
            <div>
                <span class="code">{{ $status }} · {{ $isReport ? 'REPORT PREVIEW' : 'PAGE UNAVAILABLE' }}</span>
                <h1 id="error-heading">{{ $title }}</h1>
                <p>{{ $message }}</p>
                @if($isReport)<p>You can close this preview and continue from Faculty Results.</p>@endif
                @if($reference)<div class="reference"><span>Error reference</span><code>{{ $reference }}</code></div>@endif
                <div class="actions">
                    @if($canRetry)<button class="primary" type="button" onclick="window.location.reload()">Try again</button>@endif
                    @if($isReport)<button id="close-report-preview" type="button" hidden>Close preview</button>@endif
                    <a class="button {{ $signIn ? 'primary' : '' }}" href="{{ $signIn ? '/login' : '/' }}" target="_top">{{ $signIn ? 'Sign in again' : 'Go to workspace' }}</a>
                </div>
            </div>
        </div>
        <div class="footer">@if($reference)The technical details are kept in the server logs. Share the reference with your campus system administrator if you need help.@else Use your assigned account and campus. Your administrator can help if this page should be available to you.@endif</div>
    </section>
</main>
@if($isReport)
<script>
if (window.parent !== window) {
    const closeButton = document.getElementById('close-report-preview');
    closeButton.hidden = false;
    closeButton.addEventListener('click', function () {
        window.parent.postMessage({type: 'campus-report-close'}, window.location.origin);
    });
}
</script>
@endif
</body>
</html>
