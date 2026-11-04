# Référentiel des structures administratives de l'État

API qui sert de référentiel unique des structures administratives (ministères, directions générales, directions, services) aux applications de l'État.

Stack : Laravel 12, PHP 8.3, PostgreSQL 16, Sanctum, Pest, Blade, Docker Compose.

## Installation (Docker)

```bash
cp .env.example .env
docker compose up -d --build        # au démarrage : composer install, génération de la clé, migrations
docker compose exec app php artisan migrate:fresh --seed
```

- API : http://localhost:8000/api
- Écran de recherche : http://localhost:8000/structures
- PostgreSQL depuis la machine hôte : `localhost:5433` (referentiel / referentiel)

### Compte par défaut

| e-mail | mot de passe |
|---|---|
| admin@referentiel.local | password |

Le seeder crée aussi un jeu de démonstration de 18 structures réparties sur 3 ministères, dont une structure désactivée. Il passe par `StructureService`, donc les règles métier s'appliquent et l'historique est rempli.

## Tests

```bash
docker compose exec app php artisan test
```

Les tests tournent sur PostgreSQL (base `referentiel_test`), jamais sur SQLite, pour que `unaccent`, `timestamptz` et les contraintes soient identiques à la production.

## Exemples curl

```bash
# Connexion
TOKEN=$(curl -s -X POST localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@referentiel.local","password":"password"}' | sed -E 's/.*"jeton":"([^"]+)".*/\1/')
H="Authorization: Bearer $TOKEN"

# Création (F1)
curl -s -X POST localhost:8000/api/structures -H "$H" -H "Content-Type: application/json" \
  -d '{"code":"DSIM-SNUT","sigle":"SNUT","libelle":"Service de la Nutrition","type":"SERVICE","code_parent":"DSIM"}'

# Consultation avec parent, filles et chemin (F2)
curl -s localhost:8000/api/structures/DSIM -H "$H"

# Recherche insensible à la casse et aux accents (F3)
curl -s "localhost:8000/api/structures?q=education&type=DIRECTION&par_page=10" -H "$H"

# Modification (F4) et historique
curl -s -X PATCH localhost:8000/api/structures/DSIM-SNUT -H "$H" -H "Content-Type: application/json" \
  -d '{"libelle":"Service de la Nutrition et de l alimentation","version":1}'
curl -s localhost:8000/api/structures/DSIM-SNUT/historique -H "$H"

# Désactivation (F5) : refusée en 409 tant qu'il reste des filles actives
curl -s -X POST localhost:8000/api/structures/DSIM-SNUT/desactivation -H "$H" \
  -H "Content-Type: application/json" -d '{"motif":"Réorganisation"}'

# Synchronisation incrémentale (F6)
curl -s "localhost:8000/api/structures/synchronisation?depuis=2026-01-01T00:00:00Z" -H "$H"

# Import CSV avec rapport (F7), en simulation puis en réel
curl -s -X POST localhost:8000/api/structures/import -H "$H" -F "fichier=@docs/exemples/import-structures.csv" -F "simulation=1"
curl -s -X POST localhost:8000/api/structures/import -H "$H" -F "fichier=@docs/exemples/import-structures.csv"

# Arborescence d'un ministère (F8) et état à une date passée (F9)
curl -s "localhost:8000/api/ministeres/MIN-SANTE/arborescence?inclure_inactives=1" -H "$H"
curl -s "localhost:8000/api/structures/DSIM/etat?date=2026-06-01T00:00:00Z" -H "$H"
```

Le contrat complet se trouve dans [docs/openapi.yaml](docs/openapi.yaml). Les hypothèses fonctionnelles sont dans [docs/SPECIFICATION.md](docs/SPECIFICATION.md).

## Choix techniques

- **Architecture :** les contrôleurs restent fins. Les FormRequest valident uniquement le format. Les règles métier sont dans `app/Services` : `HierarchieValidator` pour la hiérarchie, le parent actif et les filles actives, `StructureService` pour les écritures, plus un service par fonction (recherche, synchronisation, import, arborescence, état à une date).
- **Transactions :** chaque création, modification ou désactivation s'exécute dans une transaction qui écrit aussi l'historique (R6). La structure modifiée est verrouillée (`FOR UPDATE`) et son parent aussi (`FOR SHARE`). Ainsi, une désactivation et une création de fille lancées en même temps ne peuvent pas contourner R3 ni R5.
- **Verrouillage optimiste :** le champ `version` est incrémenté à chaque écriture. S'il est envoyé lors d'une modification et ne correspond plus, l'API répond 409 `VERSION_OBSOLETE`.
- **Historique :** chaque ligne stocke un instantané complet avant et après l'opération (jsonb). On peut donc reconstruire l'état de n'importe quelle structure à n'importe quelle date (F9) sans table de versions séparée.
- **Recherche :** `f_unaccent(lower(...))` est une fonction IMMUTABLE qui enveloppe `unaccent`. Avec des index trigrammes GIN, la recherche est insensible à la casse et aux accents et reste rapide.
- **Hiérarchie :** les ancêtres (chemin) et les descendants sont lus avec des CTE récursives, en une seule requête quelle que soit la profondeur.
- **Erreurs :** le format est unique, `{erreur, message, details}`. Les codes HTTP sont 422 (saisie invalide), 409 (action interdite par l'état des données), 404 (introuvable) et 401 (non authentifié). Un middleware `ForceJson` impose le JSON sur `/api` et rejette un corps JSON malformé en 422. Le handler global traduit les erreurs PostgreSQL (format, unicité, clé étrangère) en 422 ou 409, sans jamais exposer le SQL. Une 500 ne peut venir que d'un défaut du code, et son détail est masqué hors mode debug.
- **Dates :** tout est stocké en `timestamptz` UTC. Les sorties sont au format ISO 8601 Zulu (`2026-01-31T10:00:00Z`).
- **Authentification :** jetons Sanctum avec expiration configurable (`SANCTUM_EXPIRATION`, 480 minutes par défaut). La connexion est limitée à 10 tentatives par minute.

## Décisions sur les ambiguïtés

Toutes les hypothèses sont détaillées dans [docs/SPECIFICATION.md](docs/SPECIFICATION.md). Les principales :

- **Types :** il y en a quatre, dans l'ordre MINISTERE > DIRECTION_GENERALE > DIRECTION > SERVICE. Une direction peut dépendre directement d'un ministère, et un service d'une direction générale.
- **Code :** c'est l'identifiant métier. Il est normalisé en majuscules et ne peut plus être modifié. L'identifiant numérique interne n'est jamais exposé.
- **Sigle :** il doit être unique parmi les structures actives d'un même parent (R7). Deux ministères peuvent ainsi avoir chacun une DRH.
- **Structure inactive :** elle n'est plus modifiable. On ne peut rien y rattacher, et on ne peut la désactiver que si elle n'a plus aucune fille active. La réactivation est hors périmètre.
- **Codes 422 et 409 :** un parent inexistant ou un type incompatible donne 422. Un parent inactif, un cycle, des filles actives ou un doublon donnent 409.
- **Synchronisation :** l'API renvoie tout ce qui a changé strictement après `depuis`, structures inactives comprises, pour que les clients puissent propager les désactivations. Le client réutilise ensuite `horodatage_serveur` pour l'appel suivant.
- **Import :** chaque ligne est traitée dans son propre point de sauvegarde (savepoint). Une ligne en erreur n'annule donc pas les autres. Le mode `simulation` valide tout puis annule.
- **Accès :** toutes les routes de l'API demandent un jeton, sauf `/api/sante` et `/api/auth/login`.

## Limites et évolutions

- Il n'y a pas de rôles : tout utilisateur authentifié peut écrire. À prévoir : un rôle lecture seule pour les applications consommatrices, avec des abilities Sanctum.
- Il n'y a pas de réactivation ni de fusion de structures.
- L'import est synchrone et limité en taille. Au-delà, il faudrait passer par une file d'attente avec un rapport consultable.
- La synchronisation fonctionne par interrogation périodique. Une évolution possible : des webhooks ou des événements publiés à chaque écriture.
- Le serveur applicatif est `php artisan serve` avec plusieurs workers, ce qui suffit pour le test. En production, il faudrait php-fpm et nginx, avec un cache de configuration et de routes.
- Le sujet détaillé n'ayant pas été fourni, les règles R1 à R7 et les fonctions F1 à F10 suivent les hypothèses de `docs/SPECIFICATION.md`. Elles sont à confronter au sujet réel.

## Swagger UI

Pour tester l'API depuis le navigateur, ouvrir http://localhost:8000/docs. La page charge le contrat [docs/openapi.yaml](docs/openapi.yaml), également servi sur `/docs/openapi.yaml`.

1. Ouvrir `POST /auth/login`, cliquer sur "Try it out" et envoyer `admin@referentiel.local` / `password`.
2. Copier le champ `jeton` de la réponse.
3. Cliquer sur **Authorize**, coller le jeton, puis appeler n'importe quel endpoint.

Swagger UI est chargé depuis le CDN unpkg : la page demande donc un accès à Internet.
