<style>
*{box-sizing:border-box}body{margin:0;background:#edf1f5;color:#17212d;font:14px Arial,sans-serif;line-height:1.5}.toolbar{position:sticky;top:0;background:#13283e;color:white;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:2}.toolbar a{color:white}button{border:0;border-radius:6px;background:#315acb;color:white;padding:11px 18px;cursor:pointer;font-weight:600}.sheet{max-width:950px;margin:28px auto;background:white;padding:44px 48px;box-shadow:0 4px 18px #14263c15}h1{font-size:21px;text-align:center;line-height:1.4;margin:8px 0 28px}h2{font-size:15px;margin:24px 0 12px}h3{font-size:14px}table{width:100%;border-collapse:collapse;margin:12px 0 20px}th,td{border:1px solid #b8c1cb;padding:10px;text-align:left}th{background:#eef2f7}td.numeric{text-align:center}.meta{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px}.muted{color:#536273;font-size:12px}label{display:block;font-weight:600;margin:12px 0 5px}textarea,input{width:100%;font:inherit;color:inherit;border:1px solid #bac4cf;border-radius:4px;padding:9px;background:white}textarea{min-height:100px;resize:vertical}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:24px}.signature{border-bottom:1px solid #333;height:42px}.notice{background:#eaf5ec;padding:12px}.errors{background:#fff0ee;padding:12px}.section{break-inside:avoid}.print-text{display:none;white-space:pre-wrap}.annex{font-size:12px;text-align:right}
@page{size:A4 portrait;margin:12mm 14mm}
@media print{
    html,body{width:auto!important;height:auto!important;overflow:visible!important;background:white!important;color:#000!important}
    body{font-size:10pt;line-height:1.22}
    .toolbar,.no-print{display:none!important}
    .sheet{margin:0!important;padding:0!important;width:100%!important;max-width:none!important;box-shadow:none!important}
    h1{font-size:13pt;line-height:1.2;margin:2mm 0 5mm;break-after:avoid-page}
    h2{font-size:10.5pt;margin:4mm 0 2mm;break-after:avoid-page;page-break-after:avoid}
    h3{font-size:10pt;margin:3mm 0 1.5mm;break-after:avoid-page}
    p{margin:1.5mm 0;orphans:3;widows:3}
    table{margin:1.5mm 0 3mm;font-size:9pt;table-layout:auto;break-inside:auto}
    thead{display:table-header-group;break-inside:avoid;break-after:avoid-page}
    tr{break-inside:avoid;page-break-inside:avoid}
    th,td{padding:1.4mm 1.8mm;border-color:#333;overflow-wrap:anywhere;vertical-align:middle}
    th{background:#f4f4f4!important;color:#000}
    .meta{display:block;line-height:1.3}
    .meta>div{margin:1mm 0}
    label{font-size:10pt;margin:1.5mm 0 .5mm}
    input,textarea,select{display:none!important}
    .print-text{display:block;min-height:0;white-space:pre-wrap;margin:0 0 1.5mm}
    .muted{font-size:8pt;line-height:1.2;color:#444;margin:1.5mm 0 3mm}
    .annex{font-size:8pt;margin:0;text-align:right}
    .section{break-inside:auto}
    .signatures{display:block!important;margin-top:4mm;break-inside:auto}
    .signatures>div{break-inside:avoid;page-break-inside:avoid;margin:4mm 0 0;max-width:115mm}
    .signature{height:8mm;max-width:75mm}
    .summary{break-before:page}
    .keep-together{break-inside:avoid;page-break-inside:avoid}
}
</style>
@include('reports.print-page-margins')
