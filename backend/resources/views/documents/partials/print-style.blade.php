{{-- The look shared by the picking list and the delivery note (dompdf: tables, no flex, no grid). $paper is 'a4' or 'a5'. --}}
@php $small = ($paper ?? 'a4') === 'a5'; @endphp
<style>
    @page { margin: {{ $small ? '9mm 9mm 12mm' : '12mm 12mm 15mm' }}; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #14161c; font-size: {{ $small ? '9.5px' : '10.5px' }}; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .break { page-break-before: always; }
    .keep { page-break-inside: avoid; }
    .l1 { font-weight: bold; }
    .l2 { color: #7a6a4d; font-size: {{ $small ? '8.5px' : '9.5px' }}; font-weight: normal; }
    .muted { color: #5f6575; }
    .r { text-align: right; }
    .c { text-align: center; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .rtl { text-align: right; }
    .head td { border: 0; padding: 0; vertical-align: middle; }
    .brand { font-size: {{ $small ? '8px' : '9.5px' }}; letter-spacing: {{ $small ? '.6px' : '1.4px' }}; text-transform: uppercase; color: #8a7a5f; white-space: nowrap; }
    .title { font-size: {{ $small ? '15px' : '19px' }}; font-weight: bold; letter-spacing: .3px; margin-top: 2px; }
    .rule { border-top: 3px solid #14161c; border-bottom: 1px solid #b08d57; height: 2px; margin: 7px 0 9px; }
    .number { font-size: {{ $small ? '19px' : '24px' }}; font-weight: bold; letter-spacing: .5px; }
    .bar img { width: {{ $small ? '46mm' : '58mm' }}; height: {{ $small ? '10mm' : '12mm' }}; }
    .bar .code { font-size: 8.5px; letter-spacing: 1.5px; margin-top: 1px; }
    .tag { font-size: 8px; letter-spacing: 1px; text-transform: uppercase; color: #8a7a5f; margin-bottom: 2px; }
    .meta td { padding: 0 8px 7px 0; width: 50%; }
    .meta .v { font-size: {{ $small ? '10.5px' : '11.5px' }}; font-weight: bold; }
    .badges { margin: 2px 0 8px; }
    .badge { display: inline-block; border: 1.4px solid #14161c; padding: 2px 8px; margin: 0 5px 4px 0; font-size: {{ $small ? '8.5px' : '9.5px' }}; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; }
    .badge.warn { background: #14161c; color: #fff; }
    .badge .l1, .badge .l2 { color: inherit; font-size: inherit; }
    .badge.amber { border-color: #9a6b13; color: #7a5410; }
    .stamp { display: inline-block; border: 2px solid #8f2f2f; color: #8f2f2f; padding: 2px 9px; font-size: 10px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; }
    .stamp .l1, .stamp .l2 { color: #8f2f2f; font-size: 10px; }
    .box { border: 1px solid #5f6575; background: #fff; padding: 8px 10px; margin: 0 0 8px; }
    .box .tag { margin-bottom: 3px; }
    .lines { margin-top: 4px; }
    .lines th { text-align: left; font-size: 8px; letter-spacing: .8px; text-transform: uppercase; color: #5f6575; border-bottom: 1.5px solid #14161c; padding: 5px 5px; vertical-align: bottom; }
    .lines td { padding: {{ $small ? '5px 5px' : '7px 5px' }}; border-bottom: 1px solid #d9d2c3; }
    .tick { width: 18px; height: 18px; border: 1.4px solid #14161c; }
    .qty { font-size: {{ $small ? '13px' : '16px' }}; font-weight: bold; text-align: right; white-space: nowrap; }
    .bin { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; font-size: {{ $small ? '11.5px' : '13.5px' }}; white-space: nowrap; }
    .name { font-weight: bold; font-size: {{ $small ? '10.5px' : '11.5px' }}; }
    .small { font-size: {{ $small ? '8.5px' : '9.5px' }}; }
    .sig td { padding: 0 10px 0 0; vertical-align: bottom; }
    .sig .line { border-bottom: 1px solid #14161c; height: {{ $small ? '26px' : '34px' }}; }
    .foot { font-size: 8px; color: #5f6575; border-top: 1px solid #d9d2c3; padding-top: 5px; margin-top: 12px; }
</style>
