# Workflow d'Approvisionnement

## Vue d'ensemble

Le système d'approvisionnement a été optimisé pour gérer un flux de validation en deux étapes :

1. **Création de l'approvisionnement** (statut : `pending`)
2. **Validation de l'approvisionnement** (statut : `received`)

## Modifications apportées

### 1. Fonction `store()` - Création d'approvisionnement

**Changements :**
- Les stocks ne sont **plus** mis à jour automatiquement lors de la création
- Les approvisionnements sont créés avec le statut `pending` par défaut
- Les numéros de série sont enregistrés mais le stock n'est pas incrémenté
- Aucune entrée n'est créée dans la table `stocks`

**Endpoint :** `POST /api/procurement/add`

**Comportement :**
- Crée un approvisionnement avec statut `pending`
- Enregistre les lignes d'approvisionnement
- Enregistre les numéros de série (si applicables)
- **N'augmente PAS** les quantités en stock

### 2. Nouvelle fonction `validateSupply()` - Validation d'approvisionnement

**Endpoint :** `POST /api/procurement/validate/{id}`

**Fonctionnalité :**
- Valide un approvisionnement en attente (`pending`)
- Augmente les quantités de stock pour tous les produits
- Crée les entrées dans la table `stocks` avec `movement_type = 'in'`
- Change le statut de l'approvisionnement à `received`

**Validations :**
- L'approvisionnement doit exister et appartenir au magasin de l'utilisateur
- Seuls les approvisionnements avec statut `pending` peuvent être validés

**Réponse en cas de succès :**
```json
{
  "status": true,
  "message": "Approvisionnement validé avec succès. Les stocks ont été mis à jour.",
  "data": { ... }
}
```

### 3. Nouvelle fonction `cancelSupply()` - Annulation d'approvisionnement

**Endpoint :** `POST /api/procurement/cancel/{id}`

**Fonctionnalité :**
- Annule un approvisionnement en attente
- Supprime les numéros de série associés
- Change le statut à `cancelled`
- **N'affecte pas** les stocks (puisqu'ils n'ont jamais été modifiés)

**Validations :**
- L'approvisionnement doit exister et appartenir au magasin de l'utilisateur
- Seuls les approvisionnements avec statut `pending` peuvent être annulés

## Statuts des approvisionnements

| Statut | Description |
|--------|-------------|
| `pending` | Approvisionnement créé mais non validé (stock non modifié) |
| `received` | Approvisionnement validé (stock mis à jour) |
| `cancelled` | Approvisionnement annulé (stock non affecté) |

## Flux de travail recommandé

```
1. Créer l'approvisionnement
   POST /api/procurement/add
   ↓
   Statut: pending
   Stock: non modifié

2a. Valider l'approvisionnement          2b. Annuler l'approvisionnement
    POST /api/procurement/validate/{id}      POST /api/procurement/cancel/{id}
    ↓                                         ↓
    Statut: received                         Statut: cancelled
    Stock: mis à jour                         Stock: non modifié
```

## Exemples d'utilisation

### Créer un approvisionnement
```bash
POST /api/procurement/add
{
  "supplier_id": 1,
  "line_items": [
    {
      "product_id": 10,
      "quantity": 50,
      "purchase_price": 15.00,
      "serial_numbers": ["SN001", "SN002", ...] // si requis
    }
  ]
}
```

### Valider un approvisionnement
```bash
POST /api/procurement/validate/123
```

### Annuler un approvisionnement
```bash
POST /api/procurement/cancel/123
```

## Sécurité

- Toutes les opérations utilisent des transactions SQL (`DB::beginTransaction()`)
- Les erreurs provoquent un rollback automatique
- Validation du magasin de l'utilisateur (multi-tenant)
- Vérification du statut avant modification
