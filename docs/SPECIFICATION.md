# Spécification de travail (hypothèses retenues)

Le sujet détaillé n'ayant pas été fourni, ces hypothèses font foi pour tout le code.
Toute divergence avec le sujet réel devra être corrigée ici en premier.

## Modèle

### structures
| colonne | type | règle |
|---|---|---|
| id | bigint | interne, jamais exposé |
| code | varchar(30) unique | identifiant métier, majuscules, `^[A-Z0-9][A-Z0-9_-]{1,29}$`, immuable (R1) |
| sigle | varchar(30) | obligatoire |
| libelle | varchar(255) | obligatoire |
| type | enum TypeStructure | MINISTERE, DIRECTION_GENERALE, DIRECTION, SERVICE |
| parent_id | FK structures nullable | le rattachement |
| statut | enum StatutStructure | ACTIVE, INACTIVE |
| date_desactivation | timestamptz nullable | |
| motif_desactivation | text nullable | |
| version | int | incrémentée à chaque écriture (verrouillage optimiste facultatif) |
| cree_par, modifie_par | FK users nullable | |
| created_at, updated_at | timestamptz | |

### historique_structures
id, structure_id, code, action (CREATION, MODIFICATION, DESACTIVATION),
donnees_avant jsonb, donnees_apres jsonb (instantané complet), champs_modifies jsonb,
motif, utilisateur_id, effectue_le timestamptz.
L'instantané (`StructureService::instantane`) contient : code, sigle, libelle, type,
statut, code_parent, date_desactivation, motif_desactivation.

## Règles de gestion
- R1 : code unique, format imposé, normalisé en majuscules, non modifiable. Doublon : 409 CODE_EXISTANT.
- R2 : hiérarchie des types (422 HIERARCHIE_INVALIDE) :
  - MINISTERE : aucun parent ;
  - DIRECTION_GENERALE : parent MINISTERE ;
  - DIRECTION : parent MINISTERE ou DIRECTION_GENERALE ;
  - SERVICE : parent DIRECTION_GENERALE ou DIRECTION.
  Parent inexistant : 422 PARENT_INTROUVABLE.
- R3 : le parent doit être actif (409 PARENT_INACTIF).
- R4 : pas de cycle lors d'un changement de rattachement (409 CYCLE_HIERARCHIQUE) ;
  un changement de type doit rester compatible avec le parent et les filles (409 FILLES_INCOMPATIBLES).
- R5 : désactivation interdite si au moins une fille active (409 FILLES_ACTIVES) ;
  déjà inactive : 409 STRUCTURE_DEJA_INACTIVE. Une structure inactive n'est plus modifiable (409 STRUCTURE_INACTIVE).
- R6 : toute écriture produit une ligne d'historique dans la même transaction.
- R7 : sigle unique (insensible à la casse) parmi les structures actives ayant le même parent (409 SIGLE_EN_DOUBLON).
- Verrouillage optimiste : si `version` est fourni en modification et diffère, 409 VERSION_OBSOLETE.

## Endpoints (tous sous /api, JSON, Sanctum sauf login et santé)
| méthode | chemin | fonction |
|---|---|---|
| GET | /sante | santé (public) |
| POST | /auth/login | {email, password, nom_appareil?} -> {jeton, type, expire_le, utilisateur} |
| POST | /auth/logout | révoque le jeton courant (204) |
| GET | /auth/me | utilisateur courant |
| GET | /structures | F3 recherche paginée : q (code/sigle/libelle, insensible casse et accents), type, statut, code_parent, code_ministere, par_page (1..100, défaut 20), page, tri (code, libelle, -updated_at...) |
| POST | /structures | F1 création |
| GET | /structures/synchronisation | F6 : depuis (ISO 8601, obligatoire), curseur/pagination ; renvoie toutes les structures modifiées strictement après `depuis` (inactives incluses) + `horodatage_serveur` à réutiliser |
| POST | /structures/import | F7 import CSV (multipart `fichier`, option `simulation`), rapport ligne par ligne |
| GET | /structures/{code} | F2 détail : parent, filles, chemin (racine -> structure) |
| PATCH | /structures/{code} | F4 modification (sigle, libelle, type, code_parent, version) |
| GET | /structures/{code}/historique | historique paginé, plus récent d'abord |
| POST | /structures/{code}/desactivation | F5 {motif} |
| GET | /structures/{code}/etat?date=ISO | F9 état de la structure à une date passée (reconstruit depuis l'historique) |
| GET | /ministeres/{code}/arborescence | F8 arbre complet ; options `inclure_inactives` (défaut false), `date` (F9 appliqué à l'arbre) |

Web : GET /structures (F10, écran Blade de recherche, appelle le même service de recherche).

## Format d'erreur
`{"erreur": "CODE", "message": "texte français avec le code concerné", "details": {}}`
422 saisie invalide, 409 état des données, 404 introuvable (STRUCTURE_INTROUVABLE), 401 non authentifié.

## Dates
Stockage timestamptz UTC. Sortie ISO 8601 Zulu (`2026-01-31T10:00:00Z`). Entrée : toute date ISO 8601 avec fuseau.

## Compte par défaut
admin@referentiel.local / password (seeder).
