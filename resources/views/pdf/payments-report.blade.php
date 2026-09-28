@extends('pdf.layouts.report')

@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
    $singleDay = $period['start'] === $period['end'];
@endphp

@section('content')
    <table class="meta">
        <tr>
            <td class="label">Règle de calcul</td>
            <td>Chaque paiement compte le jour où il est reçu. Un reste dû réglé après la vente est un « règlement de dette ». Les factures annulées sont exclues.</td>
        </tr>
        @if ($filtersLabel)
            <tr><td class="label">Filtres appliqués</td><td>{{ $filtersLabel }}</td></tr>
        @endif
    </table>

    <table class="kpis">
        <tr>
            <td class="main" style="width: 34%">
                <div class="k-label">Total encaissé</div>
                <div class="k-value">{{ $money($summary['total']) }}</div>
                <div class="k-sub">{{ $summary['count'] }} paiement(s) · {{ $singleDay ? 'veille' : 'période précédente' }} : {{ $money($summary['previous']) }}</div>
            </td>
            <td>
                <div class="k-label">Payé à la vente</div>
                <div class="k-value">{{ $money($summary['at_sale']) }}</div>
            </td>
            <td class="good">
                <div class="k-label">Règlements de dettes</div>
                <div class="k-value">{{ $money($summary['debt']) }}</div>
                <div class="k-sub">{{ $summary['debt_count'] }} paiement(s), {{ $summary['debt_customers'] }} client(s)</div>
            </td>
        </tr>
    </table>

    <div class="avoid-break">
        <h2>Par moyen de paiement</h2>
        <table class="list" style="width: 70%">
            <thead><tr><th>Moyen</th><th class="num">Paiements</th><th class="num">Part</th><th class="num">Montant</th></tr></thead>
            <tbody>
                @foreach ($byType as $t)
                    <tr>
                        <td>{{ $typeLabels[$t['type']] ?? $t['type'] }}@if ($t['type'] === 'cash')<span class="sub">À retrouver en caisse</span>@endif</td>
                        <td class="num">{{ $t['count'] }}</td>
                        <td class="num">{{ $summary['total'] > 0 ? round($t['total'] / $summary['total'] * 100) : 0 }} %</td>
                        <td class="num">{{ $money($t['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td>Total</td><td class="num">{{ $summary['count'] }}</td><td class="num">100 %</td><td class="num">{{ $money($summary['total']) }}</td></tr></tfoot>
        </table>
    </div>

    @if (!$singleDay && count($byDay) > 1)
        <div class="avoid-break">
            <h2>Récapitulatif par jour</h2>
            <table class="list" style="width: 70%">
                <thead><tr><th>Jour</th><th class="num">Paiements</th><th class="num">Dont règlements</th><th class="num">Encaissé</th></tr></thead>
                <tbody>
                    @foreach ($byDay as $d)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($d['day'])->locale('fr')->isoFormat('ddd D MMM YYYY') }}</td>
                            <td class="num">{{ $d['count'] }}</td>
                            <td class="num">{{ $d['debt'] > 0 ? $money($d['debt']) : '—' }}</td>
                            <td class="num">{{ $money($d['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>Détail des paiements ({{ $total }})</h2>

    @if ($total > count($rows))
        <div class="note">Les {{ count($rows) }} paiements les plus récents sont listés sur {{ $total }} : pour la liste complète, utilisez l'export CSV. Les totaux portent sur tous les paiements.</div>
    @endif

    @if ($total === 0)
        <p class="empty">Aucun paiement reçu sur la période.</p>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th>{{ $singleDay ? 'Heure' : 'Date' }}</th>
                    <th>Client</th>
                    <th>Facture</th>
                    <th>Origine</th>
                    <th>Moyen</th>
                    <th>Encaissé par</th>
                    <th class="num">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $p)
                    <tr>
                        <td>
                            @if ($singleDay)
                                {{ $p['recorded_at'] ? \Carbon\Carbon::parse($p['recorded_at'])->format('H:i') : '—' }}
                            @else
                                {{ \Carbon\Carbon::parse($p['date'])->format('d/m/Y') }}
                                @if ($p['recorded_at'])<span class="sub">{{ \Carbon\Carbon::parse($p['recorded_at'])->format('H:i') }}</span>@endif
                            @endif
                        </td>
                        <td>{{ $p['customer'] }}@if ($p['customer_phone'])<span class="sub">{{ $p['customer_phone'] }}</span>@endif</td>
                        <td>{{ $p['invoice']['number'] }}<span class="sub">{{ $p['sale_number'] }}@if ($showStore && $p['store']) · {{ $p['store']['name'] }}@endif</span></td>
                        <td>
                            @if ($p['origin'] === 'debt')
                                <span class="badge b-ok">Règlement</span><span class="sub">vente du {{ \Carbon\Carbon::parse($p['invoice']['date'])->format('d/m/Y') }}</span>
                            @else
                                <span class="badge b-accent">À la vente</span>
                            @endif
                        </td>
                        <td>{{ $typeLabels[$p['type']] ?? $p['type'] }}</td>
                        <td class="muted">{{ $p['cashier'] ?? '—' }}</td>
                        <td class="num"><strong>{{ $money($p['amount']) }}</strong>@if ($p['invoice']['balance'] > 0)<span class="sub due">reste dû {{ $money($p['invoice']['balance']) }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="6">Total encaissé</td><td class="num">{{ $money($summary['total']) }}</td></tr></tfoot>
        </table>
    @endif

    @if ($singleDay)
        <table class="avoid-break" style="width: 100%; margin-top: 12mm; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; padding-right: 8mm;">
                    <div class="muted" style="font-size: 7.5pt">Espèces comptées en caisse</div>
                    <div style="border-bottom: 1px solid #1a1f35; height: 9mm;"></div>
                </td>
                <td style="width: 25%; padding-right: 8mm;">
                    <div class="muted" style="font-size: 7.5pt">Signature du caissier</div>
                    <div style="border-bottom: 1px solid #1a1f35; height: 9mm;"></div>
                </td>
                <td style="width: 25%;">
                    <div class="muted" style="font-size: 7.5pt">Signature du gérant</div>
                    <div style="border-bottom: 1px solid #1a1f35; height: 9mm;"></div>
                </td>
            </tr>
        </table>
    @endif
@endsection
