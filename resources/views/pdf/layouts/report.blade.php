<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 26mm 12mm 18mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1f35; margin: 0; }

        header.page-head { position: fixed; top: -20mm; left: 0; right: 0; height: 16mm; border-bottom: 2px solid {{ $issuer['color'] }}; }
        header.page-head table { width: 100%; border-collapse: collapse; }
        header.page-head td { vertical-align: middle; padding: 0; }
        .logo { width: 13mm; height: 13mm; }
        .issuer-name { font-size: 12pt; font-weight: bold; }
        .issuer-sub { font-size: 7.5pt; color: #6b7086; }
        .doc-title { text-align: right; }
        .doc-title strong { display: block; font-size: 12pt; color: {{ $issuer['color'] }}; text-transform: uppercase; letter-spacing: 1px; }
        .doc-title span { font-size: 8pt; color: #6b7086; }

        footer.page-foot { position: fixed; bottom: -12mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #e8e9f0; padding-top: 2mm; font-size: 7pt; color: #6b7086; }

        h2 { font-size: 10pt; margin: 5mm 0 2mm; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        .meta td { padding: 1.5mm 2.5mm; background: #f5f6fa; font-size: 8pt; }
        .meta .label { color: #6b7086; width: 22%; }

        .kpis { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin: 0 -2mm 3mm; }
        .kpis td { border: 1px solid #e8e9f0; border-radius: 2mm; padding: 2.5mm 3mm; vertical-align: top; }
        .kpis .k-label { font-size: 7pt; color: #6b7086; text-transform: uppercase; letter-spacing: .3px; }
        .kpis .k-value { font-size: 12pt; font-weight: bold; margin-top: 1mm; }
        .kpis .k-sub { font-size: 7pt; color: #6b7086; margin-top: .5mm; }
        .kpis .main { background: {{ $issuer['color'] }}; border-color: {{ $issuer['color'] }}; color: #fff; }
        .kpis .main .k-label, .kpis .main .k-sub { color: #fff; }
        .kpis .due .k-value { color: #dc2626; }
        .kpis .good .k-value { color: #16a34a; }

        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #1a1f35; color: #fff; font-size: 7pt; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 1.8mm 1.6mm; }
        table.list th.num { text-align: right; }
        table.list td { padding: 1.6mm; border-bottom: 1px solid #e8e9f0; vertical-align: top; }
        table.list tr:nth-child(even) td { background: #fafafc; }
        table.list tfoot td { font-weight: bold; background: #f5f6fa !important; border-top: 1.5px solid #1a1f35; }
        .num { text-align: right; white-space: nowrap; }
        .sub { display: block; font-size: 6.8pt; color: #6b7086; }
        .muted { color: #6b7086; }
        .due { color: #dc2626; }
        .good { color: #16a34a; }
        .badge { display: inline-block; white-space: nowrap; padding: .4mm 1.6mm; border-radius: 3mm; font-size: 6.8pt; font-weight: bold; }
        .b-ok { background: #dcfce7; color: #15803d; }
        .b-warn { background: #fef3c7; color: #b45309; }
        .b-bad { background: #fee2e2; color: #b91c1c; }
        .b-muted { background: #eceef4; color: #6b7086; }
        .b-accent { background: #ebe9fc; color: #4c3fe0; }

        .note { margin: 3mm 0; padding: 2mm 3mm; border-left: 2px solid #d97706; background: #fffbeb; font-size: 7.5pt; color: #92400e; }
        .empty { padding: 8mm 0; text-align: center; color: #6b7086; }
        .avoid-break { page-break-inside: avoid; }
    </style>
</head>
<body>
    <header class="page-head">
        <table>
            <tr>
                @if ($issuer['logo'])
                    <td style="width: 16mm"><img class="logo" src="{{ $issuer['logo'] }}" alt=""></td>
                @endif
                <td>
                    <div class="issuer-name">{{ $issuer['name'] }}</div>
                    <div class="issuer-sub">
                        {{ collect([$issuer['company'], $issuer['address'], $issuer['phones'] ? 'Tél. ' . implode(' / ', $issuer['phones']) : null])->filter()->implode(' · ') }}
                    </div>
                </td>
                <td class="doc-title">
                    <strong>{{ $title }}</strong>
                    <span>{{ $periodLabel }}</span>
                </td>
            </tr>
        </table>
    </header>

    <footer class="page-foot">
        Édité le {{ $generatedAt->isoFormat('D MMMM YYYY [à] HH:mm') }} par {{ $author }} · {{ $issuer['scope'] }}
    </footer>

    <main>
        @yield('content')
    </main>
</body>
</html>
