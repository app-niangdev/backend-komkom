@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
    $color = $issuer['color'];
    $accepted = $quote->status === 'accepted';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Devis {{ $quote->quote_number }}</title>
    <style>
        @page { margin: 14mm 14mm 16mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1f35; margin: 0; }
        table { border-collapse: collapse; }
        .head { width: 100%; border-bottom: 2px solid {{ $color }}; padding-bottom: 4mm; }
        .head td { vertical-align: top; padding: 0; }
        .logo { width: 18mm; height: 18mm; }
        .store { font-size: 13pt; font-weight: bold; }
        .issuer span, .issuer em { display: block; font-size: 8pt; color: #6b7086; margin-top: .6mm; }
        .title { text-align: right; }
        .title h1 { margin: 0 0 2mm; font-size: 17pt; color: {{ $color }}; text-transform: uppercase; letter-spacing: 1px; }
        .title table { margin-left: auto; font-size: 8.5pt; }
        .title td { padding: .4mm 0 .4mm 3mm; }
        .title td.l { color: #6b7086; text-align: right; }
        .customer { margin: 6mm 0 5mm; padding: 3mm 4mm; background: #f5f6fa; border-left: 3px solid {{ $color }}; }
        .label { display: block; font-size: 7pt; color: #6b7086; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 1mm; }
        .customer strong { font-size: 10.5pt; }
        .accepted { margin: 0 0 4mm; padding: 2mm; text-align: center; font-weight: bold; color: #15803d; border: 1.5px solid #15803d; letter-spacing: 1px; }
        table.lines { width: 100%; }
        table.lines th { background: #1a1f35; color: #fff; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 2mm; }
        table.lines th.num { text-align: right; }
        table.lines td { padding: 2mm; border-bottom: 1px solid #e8e9f0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        table.totals { width: 48%; margin: 4mm 0 0 auto; }
        table.totals td { padding: 1.6mm 2mm; }
        table.totals td.num { font-weight: bold; }
        table.totals tr.total td { border-top: 1.5px solid #1a1f35; font-size: 11pt; font-weight: bold; }
        .notes { margin-top: 6mm; padding: 3mm 4mm; border: 1px solid #e8e9f0; }
        .notes p { margin: 0; white-space: pre-line; }
        .disclaimer { margin-top: 6mm; font-size: 8pt; color: #6b7086; font-style: italic; }
        .foot { margin-top: 10mm; padding-top: 3mm; border-top: 1px solid #e8e9f0; text-align: center; font-size: 8pt; color: #6b7086; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            @if ($issuer['logo'])
                <td style="width: 22mm"><img class="logo" src="{{ $issuer['logo'] }}" alt=""></td>
            @endif
            <td class="issuer">
                <div class="store">{{ $issuer['name'] }}</div>
                @if ($issuer['company'] && $issuer['company'] !== $issuer['name'])<span>{{ $issuer['company'] }}</span>@endif
                @if ($issuer['slogan'])<em>{{ $issuer['slogan'] }}</em>@endif
                @if ($issuer['address'])<span>{{ $issuer['address'] }}</span>@endif
                @if ($issuer['phones'])<span>Tél. {{ implode(' / ', $issuer['phones']) }}</span>@endif
                @if ($issuer['email'])<span>{{ $issuer['email'] }}</span>@endif
            </td>
            <td class="title">
                <h1>Devis</h1>
                <table>
                    <tr><td class="l">N°</td><td>{{ $quote->quote_number }}</td></tr>
                    <tr><td class="l">Date</td><td>{{ $quote->created_at?->format('d/m/Y') }}</td></tr>
                    @if ($quote->valid_until)<tr><td class="l">Valable jusqu'au</td><td>{{ $quote->valid_until->format('d/m/Y') }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <div class="customer">
        <span class="label">Devis établi pour</span>
        <strong>{{ $quote->customer?->name ?? 'Client' }}</strong>
        @if ($quote->customer?->phone)<br><span>{{ $quote->customer->phone }}</span>@endif
    </div>

    @if ($accepted && $quote->decided_at)
        <div class="accepted">DEVIS ACCEPTÉ LE {{ $quote->decided_at->format('d/m/Y') }}</div>
    @endif

    <table class="lines">
        <thead>
            <tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unitaire</th><th class="num">Montant</th></tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td>{{ $item->designation }}</td>
                    <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @if ($quote->discount > 0)
            <tr><td>Sous-total</td><td class="num">{{ $money($quote->gross_amount) }}</td></tr>
            <tr><td>Remise</td><td class="num">− {{ $money($quote->discount) }}</td></tr>
        @endif
        <tr class="total"><td>Total</td><td class="num">{{ $money($quote->total_amount) }}</td></tr>
    </table>

    @if ($quote->notes)
        <div class="notes">
            <span class="label">Conditions et remarques</span>
            <p>{{ $quote->notes }}</p>
        </div>
    @endif

    <p class="disclaimer">Ce document est un devis : il ne constitue pas une facture.</p>

    <div class="foot">
        @if ($author)Établi par : {{ $author }} · @endif Merci de votre confiance.
    </div>
</body>
</html>
