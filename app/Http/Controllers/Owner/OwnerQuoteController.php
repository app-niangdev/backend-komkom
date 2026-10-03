<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\OwnerScopeService;
use App\Services\QuoteDocumentService;
use App\Services\QuoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Devis (propriétaire et gérant) : liste, saisie, envoi WhatsApp, PDF, réponse du client et copie.
 * Brouillon et envoyé restent modifiables ; accepté et refusé sont figés (envoi et copie toujours possibles).
 */
class OwnerQuoteController extends Controller
{
    public function __construct(
        protected OwnerScopeService $scope,
        protected QuoteService $quotes,
        protected QuoteDocumentService $documents
    )
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Quote::STATUSES)],
            'search' => 'nullable|string|max:100',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);
        $scope = $this->scope->resolve($request);
        $base = fn () => $this->baseQuery($scope['store_ids'], $filters['search'] ?? null);

        $list = $base()
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['store:id,name', 'customer:id,name,phone', 'user:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['perPage'] ?? 15);

        $counts = $base()
            ->selectRaw('status, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return response()->json([
            'data' => collect($list->items())->map(fn (Quote $q) => $this->summary($q)),
            'meta' => [
                'current_page' => $list->currentPage(),
                'per_page' => $list->perPage(),
                'total' => $list->total(),
                'last_page' => $list->lastPage(),
            ],
            'summary' => collect(Quote::STATUSES)->mapWithKeys(fn ($s) => [$s => [
                'count' => (int) ($counts[$s]->n ?? 0),
                'total' => (float) ($counts[$s]->total ?? 0),
            ]]),
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'excluded_stores' => $scope['excluded'],
        ]);
    }

    public function show(Request $request, $id)
    {
        $quote = $this->find($request, $id)->load(['store', 'customer', 'user:id,first_name,last_name', 'items', 'source:id,quote_number']);

        return response()->json([
            'data' => $this->summary($quote) + [
                'customer_phone' => $quote->customer?->phone,
                'notes' => $quote->notes,
                'gross_amount' => (float) $quote->gross_amount,
                'discount' => (float) $quote->discount,
                'sent_at' => $quote->sent_at?->toIso8601String(),
                'source_number' => $quote->source?->quote_number,
                'items' => $quote->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'unit_of_measure_id' => $item->unit_of_measure_id,
                    'designation' => $item->designation,
                    'unit_name' => $item->unit_name,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ])->values(),
                'whatsapp_available' => $this->documents->whatsappAvailable($quote),
            ],
            'currency' => config('subscriptions.default_currency', 'XOF'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $store = $this->scope->resolve($request)['selected'];
        $quote = $this->quotes->create($store, $request->user(), $validated);

        return response()->json([
            'message' => "Devis {$quote->quote_number} enregistré.",
            'data' => ['id' => $quote->id, 'number' => $quote->quote_number],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $quote = $this->find($request, $id);
        $this->quotes->ensureEditable($quote);
        $quote = $this->quotes->update($quote, $this->validatePayload($request, false));

        return response()->json([
            'message' => "Devis {$quote->quote_number} mis à jour.",
            'data' => ['id' => $quote->id, 'number' => $quote->quote_number],
        ]);
    }

    /** Suppression : seulement avant la réponse du client (le devis accepté ou refusé reste en historique). */
    public function destroy(Request $request, $id)
    {
        $quote = $this->find($request, $id);
        $this->quotes->ensureEditable($quote);
        $quote->delete();

        return response()->json(['message' => "Devis {$quote->quote_number} supprimé."]);
    }

    public function duplicate(Request $request, $id)
    {
        $copy = $this->quotes->duplicate($this->find($request, $id), $request->user());

        return response()->json([
            'message' => "Copie créée en brouillon : devis {$copy->quote_number}.",
            'data' => ['id' => $copy->id, 'number' => $copy->quote_number],
        ], 201);
    }

    /** Devis transmis au client par un autre moyen que WhatsApp (remis en main propre, e-mail...). */
    public function markSent(Request $request, $id)
    {
        $quote = $this->find($request, $id);
        if ($quote->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Ce devis n\'est plus un brouillon.']);
        }
        $this->quotes->markSent($quote);

        return response()->json(['message' => "Devis {$quote->quote_number} marqué comme envoyé."]);
    }

    /** Réponse du client : accepté ou refusé (définitif). */
    public function decide(Request $request, $id)
    {
        $decision = $request->validate(['decision' => 'required|in:accepted,refused'])['decision'];
        $quote = $this->quotes->decide($this->find($request, $id), $decision);

        return response()->json([
            'message' => "Devis {$quote->quote_number} " . ($decision === 'accepted' ? 'accepté.' : 'refusé.'),
        ]);
    }

    /** Envoi du PDF au client sur WhatsApp ; un brouillon passe à « envoyé ». */
    public function whatsapp(Request $request, $id)
    {
        $quote = $this->find($request, $id);

        if (!$this->documents->whatsappAvailable($quote)) {
            throw ValidationException::withMessages([
                'whatsapp' => 'Envoi WhatsApp indisponible : service non activé pour cette boutique ou client sans numéro valide.',
            ]);
        }

        try {
            $this->documents->sendWhatsapp($quote);
        } catch (\Throwable $e) {
            Log::warning('Envoi WhatsApp du devis impossible', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'L\'envoi WhatsApp a échoué. Réessayez dans un instant.'], 502);
        }

        $this->quotes->markSent($quote);

        return response()->json(['message' => 'Devis envoyé au client sur WhatsApp.']);
    }

    public function pdf(Request $request, $id)
    {
        $quote = $this->find($request, $id);

        return response($this->documents->pdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->documents->filename($quote) . '"',
        ]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'store_id' => $creating ? 'required|integer' : 'nullable',
            'customer.mode' => 'required|in:existing,new',
            'customer.id' => 'required_if:customer.mode,existing|nullable|integer',
            'customer.name' => 'required_if:customer.mode,new|nullable|string|min:2|max:100',
            'customer.phone' => ['required_if:customer.mode,new', 'nullable', 'string', 'regex:/^\+?[0-9 ]{9,15}$/'],
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'nullable|integer',
            'items.*.unit_of_measure_id' => 'nullable|integer',
            'items.*.designation' => 'nullable|string|max:255',
            'items.*.unit_name' => 'nullable|string|max:50',
            'items.*.quantity' => 'required|numeric|min:0.001|max:1000000',
            'items.*.unit_price' => 'required|numeric|min:0|max:1000000000',
            'discount' => 'nullable|integer|min:0',
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ], [
            'store_id.required' => 'Choisissez la boutique du devis.',
            'customer.mode.required' => 'Choisissez le client.',
            'customer.id.required_if' => 'Choisissez le client.',
            'customer.name.required_if' => 'Le nom du nouveau client est obligatoire.',
            'customer.phone.required_if' => 'Le téléphone du nouveau client est obligatoire.',
            'customer.phone.regex' => 'Le téléphone doit contenir entre 9 et 15 chiffres.',
            'items.required' => 'Ajoutez au moins une ligne.',
            'items.min' => 'Ajoutez au moins une ligne.',
            'items.*.quantity.min' => 'La quantité doit être supérieure à zéro.',
        ]);
    }

    /** Devis d'une boutique du périmètre, sinon 404. */
    private function find(Request $request, $id): Quote
    {
        $storeIds = $this->scope->resolve($request->merge(['store_id' => null]))['store_ids'];

        return $this->baseQuery($storeIds, null)->findOrFail($id);
    }

    private function baseQuery(array $storeIds, ?string $search): Builder
    {
        return Quote::query()
            ->whereIn('quotes.store_id', $storeIds)
            ->when($search, fn ($q, $s) => $q->where(fn ($sub) => $sub
                ->where('quote_number', 'ILIKE', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', "%{$s}%")->orWhere('phone', 'ILIKE', "%{$s}%"))));
    }

    private function summary(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->quote_number,
            'date' => $quote->created_at?->toIso8601String(),
            'store' => $quote->store ? ['id' => $quote->store->id, 'name' => $quote->store->name] : null,
            'customer' => $quote->customer?->name,
            'customer_id' => $quote->customer_id,
            'author' => $quote->user ? trim($quote->user->first_name . ' ' . $quote->user->last_name) : null,
            'status' => $quote->status,
            'total_amount' => (float) $quote->total_amount,
            'valid_until' => $quote->valid_until?->toDateString(),
            // Indication seulement : la validité dépassée ne change pas le statut
            'validity_passed' => $quote->isEditable() && $quote->valid_until && $quote->valid_until->lt(today()),
            'decided_at' => $quote->decided_at?->toIso8601String(),
            'editable' => $quote->isEditable(),
        ];
    }
}
