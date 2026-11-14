@php
    use App\Support\SignatureStrokes;

    $logo = fn (string $key) => is_file(public_path($letterhead[$key] ?? '')) ? asset($letterhead[$key]) : null;
    $pageCount = count($pages);
    $receiptCount = collect($pages)->flatten(1)->pluck('form.id')->unique()->count();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Learner's Acknowledgement Receipt</title>
    <style>
        body { font-family: Arial, sans-serif; background: #eef1f4; color: #111827; margin: 0; padding: 24px 12px 32px; }
        /* Floating glass bar: stays in view while the instructor scrolls a long stack of receipts. */
        .toolbar { position: sticky; top: 12px; z-index: 50; isolation: isolate; width: 8.27in; max-width: 100%; box-sizing: border-box; margin: 0 auto 18px; display: flex; align-items: center; gap: 12px; padding: 8px 8px 8px 18px; border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .55);
            background: linear-gradient(135deg, rgba(255, 255, 255, .26) 0%, rgba(255, 255, 255, .06) 45%, rgba(255, 255, 255, .18) 100%);
            /* Safari keeps this line; Chrome and Edge use the next one, which adds the lens bend. */
            -webkit-backdrop-filter: blur(10px) saturate(190%) brightness(1.06); backdrop-filter: blur(10px) saturate(190%) brightness(1.06);
            backdrop-filter: url(#sinag-liquid-glass) blur(1.5px) saturate(180%) brightness(1.05);
            box-shadow: inset 1.5px 1.5px 0 rgba(255, 255, 255, .85), inset -1px -1px 0 rgba(255, 255, 255, .45), inset 0 0 18px rgba(255, 255, 255, .35), 0 12px 32px rgba(15, 23, 42, .18), 0 2px 6px rgba(15, 23, 42, .08); }
        /* The curved highlights at the glass's edges give it its liquid look. */
        .toolbar::before { content: ""; position: absolute; inset: 0; z-index: -1; border-radius: inherit; pointer-events: none; mix-blend-mode: soft-light;
            background: radial-gradient(120% 140% at 8% -20%, rgba(255, 255, 255, .75) 0%, rgba(255, 255, 255, 0) 42%), radial-gradient(90% 120% at 100% 130%, rgba(255, 255, 255, .45) 0%, rgba(255, 255, 255, 0) 45%); }
        .toolbar-back { position: relative; flex: 0 0 auto; display: inline-flex; align-items: center; gap: 4px; min-height: 42px; margin-left: -10px; padding: 0 14px 0 10px; border-radius: 999px; color: #1f4f7a; font: bold 13px Arial, sans-serif; text-decoration: none;
            background: rgba(255, 255, 255, .55); border: 1px solid rgba(255, 255, 255, .8); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .9), 0 2px 8px rgba(15, 23, 42, .08); transition: background .18s ease, transform .18s ease; }
        .toolbar-back:hover { background: rgba(255, 255, 255, .85); }
        .toolbar-back:active { transform: scale(.97); }
        .toolbar-back:focus-visible { outline: 3px solid rgba(46, 107, 160, .45); outline-offset: 2px; }
        .toolbar-back svg { width: 18px; height: 18px; }
        /* Inside the in-system preview the panel's own menu is already there. */
        .in-frame .toolbar-back { display: none; }
        .toolbar-info { position: relative; flex: 1 1 auto; min-width: 0; line-height: 1.2; }
        .toolbar-info strong { display: block; font-size: 13px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .toolbar-info span { display: block; font-size: 12px; color: #475569; }
        .toolbar button { position: relative; flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px; min-height: 42px; padding: 0 22px; border: 1px solid rgba(255, 255, 255, .35); border-radius: 999px; color: #fff; font: bold 14px Arial, sans-serif; cursor: pointer;
            background: linear-gradient(180deg, rgba(46, 107, 160, .96) 0%, rgba(31, 79, 122, .96) 55%, rgba(24, 64, 101, .98) 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .45), inset 0 -2px 6px rgba(0, 0, 0, .18), 0 6px 16px rgba(31, 79, 122, .35);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease; }
        .toolbar button:hover { transform: translateY(-1px); filter: brightness(1.08); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .5), inset 0 -2px 6px rgba(0, 0, 0, .18), 0 10px 22px rgba(31, 79, 122, .42); }
        .toolbar button:active { transform: scale(.97); }
        .toolbar button:focus-visible { outline: 3px solid rgba(46, 107, 160, .45); outline-offset: 2px; }
        .toolbar button svg { width: 17px; height: 17px; }
        /* Browsers without blur, or users who turned transparency off, get a solid bar instead. */
        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) { .toolbar { background: rgba(255, 255, 255, .94); } }
        @media (prefers-reduced-transparency: reduce) { .toolbar { background: #fff; -webkit-backdrop-filter: none; backdrop-filter: none; } }
        @media (prefers-reduced-motion: reduce) { .toolbar button { transition: none; } }
        @media (max-width: 480px) { .toolbar { top: 8px; padding-left: 14px; gap: 8px; } .toolbar button { padding: 0 16px; } .toolbar-back span { display: none; } .toolbar-back { padding: 0 10px; } }
        .page { position: relative; width: 8.27in; min-height: 11.69in; background: #fff; margin: 0 auto 24px; padding: .5in; box-sizing: border-box; }
        .header-top { display: flex; align-items: center; justify-content: space-between; gap: .2in; }
        .school-brand { display: flex; align-items: center; min-width: 0; }
        .bisu-logo { flex: 0 0 auto; width: .66in; height: .66in; object-fit: contain; margin-right: .14in; }
        .header-certifications { flex: 0 0 auto; display: flex; align-items: center; justify-content: flex-end; gap: .08in; width: 2.28in; }
        .bagong-logo { width: .82in; height: .72in; object-fit: contain; }
        .tuv-logo { width: 1.38in; height: .68in; object-fit: contain; }
        .school-header { margin-bottom: .08in; }
        .header-text { line-height: 1.1; text-align: left; }
        .school-header p { margin: 0; font-size: 9pt; }
        .school-header h2 { margin: 1px 0; font-size: 12.5pt; font-weight: bold; }
        .header-title { text-align: center; margin-top: .2in; }
        .header-title h1 { margin: 0; font-size: 13pt; font-weight: bold; }
        .receipt-fragment + .receipt-fragment { margin-top: .12in; }
        .college-info { width: 4.3in; margin: .09in auto .11in; border-collapse: collapse; }
        .college-info td { border: none; padding: 1px 5px; text-align: center; font-size: 10pt; line-height: 1.1; }
        .document-info-table { width: 100%; border-collapse: collapse; margin: .03in 0 .08in; }
        .document-info-table td { width: 50%; border: none; padding: 0; font-size: 10pt; line-height: 1.15; font-weight: bold; }
        .document-info-table td:last-child { text-align: right; }
        .document-value { text-decoration: underline; }
        .receipt-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .receipt-table th, .receipt-table td { width: 33.333%; border: 1px solid #000; padding: 3px 6px; font-size: 9pt; line-height: 1.1; height: .16in; vertical-align: middle; overflow: visible; }
        .receipt-table th { text-align: center; font-weight: bold; }
        .receipt-table td:nth-child(2), .receipt-table td:nth-child(3) { text-align: center; }
        .signature-cell { position: relative; overflow: visible; }
        .signature-image { display: block; position: absolute; left: 50%; top: 50%; width: 2.18in; height: .58in; transform: translate(-50%, -50%); pointer-events: none; z-index: 10; }
        .instructor-section { margin-top: .36in; width: 2.65in; text-align: center; }
        .instructor-section p { margin: 0; }
        .instructor-name { border-bottom: 1px solid #000; font-size: 10pt; font-weight: bold; text-transform: uppercase; }
        .instructor-designation { margin-top: 3px; font-size: 10pt; }
        .document-footer { position: absolute; left: .5in; right: .5in; bottom: .24in; text-align: left; font-size: 8.5pt; white-space: pre; }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar, .phpdebugbar { display: none !important; }
            .page { zoom: 1 !important; width: 100%; min-height: 10.69in; margin: 0; padding: 0; break-after: page; }
            .page:last-child { break-after: auto; }
            .document-footer { left: 0; right: 0; bottom: 0; }
            @page { size: A4; margin: .5in; }
        }
    </style>
</head>
<body>
    <script>if (window.self !== window.top) document.body.classList.add('in-frame');</script>
    @include('filament.partials.liquid-glass-filter')
    <div class="toolbar">
        @isset($backUrl)
            <a class="toolbar-back" href="{{ $backUrl }}" aria-label="Back to receipts">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                <span>Back to receipts</span>
            </a>
        @endisset
        <div class="toolbar-info">
            <strong>{{ $pages[0][0]['form']->document_title ?? "Learner's Acknowledgement Receipt" }}</strong>
            <span>{{ $receiptCount }} {{ str('receipt')->plural($receiptCount) }} · {{ $pageCount }} {{ str('page')->plural($pageCount) }}</span>
        </div>
        <button type="button" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print
        </button>
    </div>

    @foreach ($pages as $index => $fragments)
        <section class="page">
            <header class="school-header">
                <div class="header-top">
                    <div class="school-brand">
                        @if ($logo('logo'))<img class="bisu-logo" src="{{ $logo('logo') }}" alt="">@endif
                        <div class="header-text">
                            <p>{{ $letterhead['republic'] }}</p>
                            <h2>{{ $letterhead['school'] }}</h2>
                            <p>{{ $letterhead['address'] }}</p>
                            <p>{{ $letterhead['office'] }}</p>
                            <p><em>{{ $letterhead['core_values'] }}</em></p>
                        </div>
                    </div>
                    <div class="header-certifications">
                        @if ($logo('bagong_pilipinas_logo'))<img class="bagong-logo" src="{{ $logo('bagong_pilipinas_logo') }}" alt="">@endif
                        @if ($logo('certification_logo'))<img class="tuv-logo" src="{{ $logo('certification_logo') }}" alt="">@endif
                    </div>
                </div>
                <div class="header-title"><h1>{{ $fragments[0]['form']->document_title ?? '' }}</h1></div>
            </header>

            @foreach ($fragments as $fragment)
                @php($form = $fragment['form'])
                <div class="receipt-fragment">
                    <table class="college-info">
                        <tbody>
                            <tr><td>{{ $form->college_name }}</td></tr>
                            <tr><td>{{ $form->department_name }}</td></tr>
                            <tr><td>{{ $form->term_label }}</td></tr>
                        </tbody>
                    </table>
                    <table class="document-info-table">
                        <tbody>
                            <tr>
                                <td><strong>Document Received:</strong> <span class="document-value">{{ $form->document_received }}</span></td>
                                <td><strong>Year &amp; Section:</strong> <span class="document-value">{{ $form->year_section }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                    <table class="receipt-table">
                        <thead><tr><th>Name of Student</th><th>Date Received</th><th>Signature</th></tr></thead>
                        <tbody>
                            @foreach ($fragment['students'] as $student)
                                <tr>
                                    <td>{{ $student->student_name }}</td>
                                    <td>{{ $form->date_label }}</td>
                                    <td class="signature-cell">{{ SignatureStrokes::svg($student->signature_path, $student->student_name.' signature') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($fragment['final'])
                        <section class="instructor-section">
                            <p class="instructor-name">{{ $form->instructor_name }}</p>
                            <p class="instructor-designation">{{ $form->instructor_designation }}</p>
                        </section>
                    @endif
                </div>
            @endforeach

            <footer class="document-footer">{{ $footer }}  |  Page {{ $index + 1 }} of {{ $pageCount }}</footer>
        </section>
    @endforeach

    {{-- On a phone, shows the whole A4 page without sideways scrolling. Printing always uses full size. --}}
    <script>
        (function () {
            var pages = document.querySelectorAll('.page');
            function fit() {
                var scale = Math.min(1, (document.documentElement.clientWidth - 24) / (8.27 * 96));
                pages.forEach(function (page) { page.style.zoom = scale; });
            }
            fit();
            window.addEventListener('resize', fit);
        })();
    </script>
</body>
</html>
