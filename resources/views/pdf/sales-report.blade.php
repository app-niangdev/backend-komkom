@extends('pdf.layouts.report')

@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ' . $currency;
    $statusBadge = ['confirmed' => 'b-ok', 'pending' => 'b-warn', 'cancelled' => 'b-bad'];
    $payBadge = ['paid' => 'b-ok', 'partial' => 'b-warn', 'no_paid' => 'b-bad', 'cancelled' => 'b-muted'];
@endphp

@section('content')
    @if ($filtersLabel)
        <table class="meta"><tr><td class="label">Filtres appliqués</td><td>{{ $filtersLabel }}</td></tr></table>
    @endif

    <h2 style="margin-top: 0">Synthèse de la période{{ $filtersLabel ? ' (toutes ventes, filtres non appliqués)' : '' }}</h2>
    <table class="kpis">
        <tr>
            <td class="main">
                <div class="k-label">Chiffre d'affaires</div>
                <div class="k-value">{{ $money($summary['revenue']) }}</div>
                <div class="k-sub">{{ $summary['count'] }} vente(s) validée(s)</div>
            </td>
            <td>
                <div class="k-label">Panier moyen</div>
                <div class="k-value">{{ $money($summary['average']) }}</div>
            </td>
            <td class="good">
                <div class="k-label">Encaissé sur ces ventes</div>
                <div class="k-value">{{ $money($summary['collected']) }}</div>
                <div class="k-sub">{{ $summary['revenue'] > 0 ? round($summary['collected'] / $summary['revenue'] * 100) : 0 }} % du chiffre d'affaires</div>
            </td>
            <td class="due">
                <div class="k-label">Reste à encaisser</div>
                <div class="k-value">{{ $money($summary['outstanding']) }}</div>
            </td>
            <td>
                <div class="k-label">Annulées</div>
                <div class="k-value">{{ $summary['cancelled_count'] }}</div>
                <div class="k-sub">{{ $money($summary['cancelled_amount']) }}</div>
            </td>
        </tr>
    </table>

    <h2>Détail des ventes ({{ $total }})</h2>

    @if ($total > count($rows))
        <div class="note">Les {{ count($rows) }} ventes les plus récentes sont listées sur {{ $total }} : pour la liste complète, utilisez l'export CSV. Les totaux portent sur toutes les ventes.</div>
    @endif

    @if ($total === 0)
        <p class="empty">Aucune vente ne correspond à ces critères.</p>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th>Vente</th>
                    @if ($showStore)<th>Boutique</th>@endif
                    <th>Client</th>
                    <th>Vendeur</th>
                    <th>Statut</th>
                    <th>Paiement</th>
                    <th class="num">Montant</th>
                    <th class="num">Payé</th>
                    <th class="num">Reste</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $s)
                    <tr>
                        <td>{{ $s['sale_number'] }}<span class="sub">{{ \Carbon\Carbon::parse($s['date'])->format('d/m/Y H:i') }}</span></td>
                        @if ($showStore)<td>{{ $s['store']['name'] ?? '' }}</td>@endif
                        <td>{{ $s['customer'] }}</td>
                        <td class="muted">{{ $s['seller'] ?? '—' }}</td>
                        <td><span class="badge {{ $statusBadge[$s['status']] ?? 'b-muted' }}">{{ $statusLabels[$s['status']] ?? $s['status'] }}</span></td>
                        <td><span class="badge {{ $payBadge[$s['payment_status']] ?? 'b-muted' }}">{{ $paymentLabels[$s['payment_status']] ?? '—' }}</span></td>
                        <td class="num">{{ $money($s['total_amount']) }}</td>
                        <td class="num">{{ $s['status'] === 'cancelled' ? '—' : $money($s['invoice']['amount_paid'] ?? 0) }}</td>
                        <td class="num {{ ($s['invoice']['balance'] ?? 0) > 0 && $s['status'] !== 'cancelled' ? 'due' : '' }}">
                            {{ $s['status'] === 'cancelled' || !($s['invoice']['balance'] ?? 0) ? '—' : $money($s['invoice']['balance']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="{{ $showStore ? 6 : 5 }}">Total des ventes validées listées par les filtres</td>
                    <td class="num">{{ $money($totals['amount']) }}</td>
                    <td class="num">{{ $money($totals['paid']) }}</td>
                    <td class="num">{{ $money($totals['balance']) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    @if (count($byPaymentType) > 0)
        <div class="avoid-break">
            <h2>Paiements reçus sur les ventes de la période, par moyen</h2>
            <table class="list" style="width: 60%">
                <thead><tr><th>Moyen</th><th class="num">Paiements</th><th class="num">Montant</th></tr></thead>
                <tbody>
                    @foreach ($byPaymentType as $p)
                        <tr><td>{{ $typeLabels[$p['type']] ?? $p['type'] }}</td><td class="num">{{ $p['count'] }}</td><td class="num">{{ $money($p['total']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
