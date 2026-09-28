<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Liste des paiements</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #222; margin: 0; }
        .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 14px; }
        .header h1 { font-size: 16px; margin: 0 0 4px 0; }
        .header .meta { font-size: 10px; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 6px 5px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }
        tbody td { border: 1px solid #ddd; padding: 5px; }
        tbody tr:nth-child(even) td { background: #fafafa; }
        .right { text-align: right; }
        tfoot td {
            border: 1px solid #ccc;
            padding: 7px 5px;
            background: #f0f0f0;
            font-weight: bold;
        }
        .empty { text-align: center; padding: 20px; color: #888; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Liste des paiements de factures</h1>
        <div class="meta">
            @if($store)
                Boutique : {{ $store->name }}<br>
            @endif
            Période : {{ $period }}<br>
            Généré le {{ $generatedAt->format('d/m/Y à H:i') }} — {{ $payments->count() }} paiement(s)
        </div>
    </div>

    @if($payments->isEmpty())
        <div class="empty">Aucun paiement pour les critères sélectionnés.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 14%">Date</th>
                    <th style="width: 16%">Facture</th>
                    <th style="width: 28%">Client</th>
                    <th style="width: 14%">Type</th>
                    <th style="width: 14%">Encaissé par</th>
                    <th style="width: 14%" class="right">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $payment)
                    @php
                        $typeLabels = ['cash' => 'Espèces', 'wave' => 'Wave', 'OM' => 'Orange Money', 'other' => 'Autre'];
                        $invoice = $payment->invoice;
                        $customerName = $invoice?->customer?->name
                            ?? $invoice?->customer_name
                            ?? $invoice?->sale?->customer?->name
                            ?? 'Client Anonyme';
                        $cashier = $payment->user
                            ? trim(($payment->user->first_name ?? '') . ' ' . ($payment->user->last_name ?? ''))
                            : '';
                    @endphp
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($payment->date)->format('d/m/Y') }}</td>
                        <td>{{ $invoice?->invoice_number ?? '—' }}</td>
                        <td>{{ $customerName }}</td>
                        <td>{{ $typeLabels[$payment->payment_type] ?? $payment->payment_type }}</td>
                        <td>{{ $cashier ?: '—' }}</td>
                        <td class="right">{{ number_format($payment->amount, 0, ',', ' ') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5">Total</td>
                    <td class="right">{{ number_format($totalAmount, 0, ',', ' ') }} FCFA</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="footer">Document généré automatiquement</div>
</body>
</html>
