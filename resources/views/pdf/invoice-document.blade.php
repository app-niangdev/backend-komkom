@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
    $color = $issuer['color'];
    $isReceipt = $kind === 'receipt';
    $cancelled = $invoice->is_cancelled || $invoice->invoice_status === 'cancelled';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $isReceipt ? 'Reçu de paiement' : 'Facture' }} {{ $invoice->invoice_number }}</title>
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
        .cancelled { margin: 0 0 4mm; padding: 2mm; text-align: center; font-weight: bold; color: #b91c1c; border: 1.5px solid #b91c1c; letter-spacing: 2px; }
        table.lines { width: 100%; }
        table.lines th { background: #1a1f35; color: #fff; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .3px; text-align: left; padding: 2mm; }
        table.lines th.num { text-align: right; }
        table.lines td { padding: 2mm; border-bottom: 1px solid #e8e9f0; vertical-align: top; }
        table.lines small { display: block; font-size: 7pt; color: #6b7086; margin-top: .5mm; }
        .num { text-align: right; white-space: nowrap; }
        table.totals { width: 48%; margin: 4mm 0 0 auto; }
        table.totals td { padding: 1.6mm 2mm; }
        table.totals td.num { font-weight: bold; }
        table.totals tr.total td { border-top: 1.5px solid #1a1f35; font-size: 11pt; font-weight: bold; }
        table.totals tr.due td { color: #dc2626; font-weight: bold; }
        table.totals tr.done td { color: #16a34a; font-weight: bold; }
        .amount-box { margin: 2mm 0 5mm; padding: 5mm; text-align: center; border: 1px solid #e8e9f0; border-radius: 2mm; }
        .amount-box strong { display: block; font-size: 20pt; color: {{ $color }}; margin: 1mm 0; }
        .amount-box span { color: #6b7086; }
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
                <h1>{{ $isReceipt ? 'Reçu' : 'Facture' }}</h1>
                <table>
                    <tr><td class="l">{{ $isReceipt ? 'Facture' : 'N°' }}</td><td>{{ $invoice->invoice_number }}</td></tr>
                    <tr><td class="l">Date</td><td>{{ ($isReceipt ? $receipt->created_at : $invoice->created_at)?->format('d/m/Y H:i') }}</td></tr>
                    @if ($sale?->sale_number)<tr><td class="l">Vente</td><td>{{ $sale->sale_number }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <div class="customer">
        <span class="label">{{ $isReceipt ? 'Reçu de' : 'Facturé à' }}</span>
        <strong>{{ $customer }}</strong>
        @if ($customerPhone)<br><span>{{ $customerPhone }}</span>@endif
    </div>

    @if ($cancelled)
        <div class="cancelled">FACTURE ANNULÉE</div>
    @endif

    @if ($isReceipt)
        <div class="amount-box">
            <span class="label">Montant reçu</span>
            <strong>{{ $money($receipt->amount) }}</strong>
            <span>{{ $paymentTypes[$receipt->payment_type] ?? $receipt->payment_type }} · le {{ $receipt->date?->format('d/m/Y') }}</span>
        </div>
        <table class="totals">
            <tr><td>Facture {{ $invoice->invoice_number }}</td><td class="num">{{ $money($invoice->amount_total) }}</td></tr>
            @if ($paidBefore > 0)<tr><td>Déjà payé</td><td class="num">{{ $money($paidBefore) }}</td></tr>@endif
            <tr><td>Payé à ce jour</td><td class="num">{{ $money($paidAfter) }}</td></tr>
            @if ($remaining > 0)
                <tr class="total due"><td>Reste à payer</td><td class="num">{{ $money($remaining) }}</td></tr>
            @else
                <tr class="total done"><td>Facture soldée</td><td class="num">✓</td></tr>
            @endif
        </table>
    @else
        <table class="lines">
            <thead>
                <tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unitaire</th><th class="num">Montant</th></tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    @php $serials = $item->serialNumbers->pluck('serial_number')->filter(); @endphp
                    <tr>
                        <td>
                            {{ $item->product?->name ?? 'Produit supprimé' }}
                            @if ($serials->isNotEmpty())<small>N° série : {{ $serials->implode(', ') }}</small>@endif
                        </td>
                        <td class="num">{{ $qty($item->quantity) }} {{ $item->unit_name ?? $item->product?->base_unit }}</td>
                        <td class="num">{{ $money($item->unit_price) }}</td>
                        <td class="num">{{ $money($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            @if (($sale?->discount ?? 0) > 0)
                <tr><td>Sous-total</td><td class="num">{{ $money($sale->gross_amount) }}</td></tr>
                <tr><td>Remise</td><td class="num">− {{ $money($sale->discount) }}</td></tr>
            @endif
            <tr class="total"><td>Total</td><td class="num">{{ $money($invoice->amount_total) }}</td></tr>
            @unless ($cancelled)
                @foreach ($invoice->paymentReceipts as $p)
                    <tr><td>{{ $paymentTypes[$p->payment_type] ?? $p->payment_type }} · {{ $p->date?->format('d/m/Y') }}</td><td class="num">{{ $money($p->amount) }}</td></tr>
                @endforeach
                @if ($invoice->balance > 0)
                    <tr class="due"><td>Reste à payer</td><td class="num">{{ $money($invoice->balance) }}</td></tr>
                @endif
            @endunless
        </table>
    @endif

    <div class="foot">
        @if ($seller)Vendeur : {{ $seller }} · @endif Merci de votre confiance.
    </div>
</body>
</html>
