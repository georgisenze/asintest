<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Http\Requests\CreerStructureRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * F7 : import CSV.
 *
 * - Le fichier entier est traité dans une transaction ; chaque ligne dans un
 *   SAVEPOINT (DB::transaction imbriquée) : une ligne en erreur est annulée
 *   seule, les autres sont conservées. En simulation, tout est annulé à la fin.
 * - Chaque ligne suit exactement le chemin de l'API : validation de format de
 *   CreerStructureRequest puis StructureService::creer (règles métier et historique).
 * - Ordre : une ligne dont le parent est défini plus bas dans le fichier est
 *   reportée à une passe suivante, tant qu'une passe crée au moins une structure.
 *   On ne trie pas par type, car un tri masquerait l'erreur réelle (ex. une
 *   DIRECTION rattachée à un SERVICE du fichier serait signalée "parent
 *   introuvable" au lieu de "hiérarchie invalide"). Le nombre de passes est
 *   borné par la profondeur de la hiérarchie.
 */
class ImportStructureService
{
    public const MAX_LIGNES = 5000;

    private const COLONNES_OBLIGATOIRES = ['code', 'sigle', 'libelle', 'type'];

    private const COLONNES_AUTORISEES = ['code', 'sigle', 'libelle', 'type', 'code_parent'];

    public function __construct(private readonly StructureService $structures) {}

    /**
     * @return array{simulation:bool, separateur:string, total_lignes:int, creees:int, en_erreur:int, lignes:list<array>}
     */
    public function importer(string $contenu, bool $simulation, ?User $auteur = null): array
    {
        [$separateur, $lignes] = $this->lire($contenu);

        DB::beginTransaction();
        try {
            $resultats = $this->traiter($lignes, $auteur);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $simulation ? DB::rollBack() : DB::commit();

        ksort($resultats);
        $resultats = array_values($resultats);
        $creees = count(array_filter($resultats, fn ($r) => $r['statut'] === 'CREEE'));

        return [
            'simulation' => $simulation,
            'separateur' => $separateur,
            'total_lignes' => count($resultats),
            'creees' => $creees,
            'en_erreur' => count($resultats) - $creees,
            'lignes' => $resultats,
        ];
    }

    /**
     * Décode le fichier : encodage, séparateur, en-tête, lignes.
     *
     * @return array{0:string, 1:list<array{ligne:int, donnees?:array<string,string>, erreur?:array}>}
     */
    private function lire(string $contenu): array
    {
        if (! mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        $contenu = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $contenu);

        if (trim($contenu) === '') {
            throw ApiException::invalide('FICHIER_VIDE', 'Le fichier importé est vide.');
        }

        $premiereLigne = strtok($contenu, "\n");
        $separateur = substr_count($premiereLigne, ';') >= substr_count($premiereLigne, ',') ? ';' : ',';

        $flux = fopen('php://temp', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $entete = $this->lireEntete(fgetcsv($flux, null, $separateur, '"', '') ?: []);

        $lignes = [];
        $numero = 1;
        while (($champs = fgetcsv($flux, null, $separateur, '"', '')) !== false) {
            $numero++;
            $champs = array_map(fn ($c) => trim((string) $c), $champs);

            if (implode('', $champs) === '') {
                continue; // ligne vide ignorée.
            }
            if (count($lignes) >= self::MAX_LIGNES) {
                fclose($flux);
                throw ApiException::invalide('TROP_DE_LIGNES',
                    'Le fichier dépasse la limite de '.self::MAX_LIGNES.' lignes de données. Découpez-le en plusieurs fichiers.',
                    ['limite' => self::MAX_LIGNES]);
            }

            // Séparateur final toléré ("a;b;c;d;e;").
            while (count($champs) > count($entete) && end($champs) === '') {
                array_pop($champs);
            }

            if (count($champs) !== count($entete)) {
                $lignes[] = ['ligne' => $numero, 'erreur' => $this->erreur($numero, $champs[0] ?? null, 'COLONNES_INCORRECTES',
                    "Ligne {$numero} : ".count($champs).' colonne(s) trouvée(s), '.count($entete).' attendue(s) ('.implode($separateur, $entete).').',
                    ['colonnes_trouvees' => count($champs), 'colonnes_attendues' => count($entete)])];

                continue;
            }

            $lignes[] = ['ligne' => $numero, 'donnees' => array_combine($entete, $champs)];
        }
        fclose($flux);

        if ($lignes === []) {
            throw ApiException::invalide('AUCUNE_DONNEE', 'Le fichier ne contient que l\'en-tête, aucune ligne de données.');
        }

        return [$separateur, $lignes];
    }

    /** @return list<string> */
    private function lireEntete(array $brute): array
    {
        $entete = array_map(
            fn ($c) => str_replace(['é', 'è', ' '], ['e', 'e', '_'], mb_strtolower(trim((string) $c))),
            $brute,
        );

        $manquantes = array_values(array_diff(self::COLONNES_OBLIGATOIRES, $entete));
        $inconnues = array_values(array_diff($entete, self::COLONNES_AUTORISEES));
        $details = [
            'colonnes_manquantes' => $manquantes,
            'colonnes_inconnues' => $inconnues,
            'colonnes_attendues' => self::COLONNES_AUTORISEES,
        ];

        if ($manquantes !== []) {
            throw ApiException::invalide('EN_TETE_INVALIDE',
                'La première ligne doit être l\'en-tête code;sigle;libelle;type;code_parent. Colonne(s) obligatoire(s) manquante(s) : '.implode(', ', $manquantes).'.',
                $details);
        }
        if ($inconnues !== []) {
            throw ApiException::invalide('COLONNES_INCONNUES',
                'Colonne(s) inconnue(s) dans l\'en-tête : '.implode(', ', $inconnues).'. Colonnes autorisées : '.implode(', ', self::COLONNES_AUTORISEES).'.',
                $details);
        }
        if (count($entete) !== count(array_unique($entete))) {
            throw ApiException::invalide('EN_TETE_INVALIDE', 'L\'en-tête contient une colonne en double.', $details);
        }

        return $entete;
    }

    /** @return array<int, array> résultats indexés par numéro de ligne */
    private function traiter(array $lignes, ?User $auteur): array
    {
        $formulaire = new CreerStructureRequest;
        $resultats = [];
        $codesFichier = []; // code => numéro de la première ligne qui le porte
        $aTraiter = [];

        foreach ($lignes as $ligne) {
            $n = $ligne['ligne'];
            if (isset($ligne['erreur'])) {
                $resultats[$n] = $ligne['erreur'];

                continue;
            }

            $donnees = $this->normaliser($ligne['donnees']);
            $validateur = Validator::make($donnees, $formulaire->rules(), $formulaire->messages());

            if ($validateur->fails()) {
                $resultats[$n] = $this->erreur($n, $donnees['code'], 'VALIDATION_ECHOUEE',
                    "Ligne {$n} (code {$donnees['code']}) : données invalides (".implode(', ', array_keys($validateur->errors()->toArray())).').',
                    $validateur->errors()->toArray());

                continue;
            }

            $code = $donnees['code'];
            if (isset($codesFichier[$code])) {
                $resultats[$n] = $this->erreur($n, $code, 'CODE_EN_DOUBLON_FICHIER',
                    "Ligne {$n} : le code {$code} figure déjà ligne {$codesFichier[$code]} du fichier.",
                    ['code' => $code, 'premiere_ligne' => $codesFichier[$code]]);

                continue;
            }

            $codesFichier[$code] = $n;
            $aTraiter[] = ['ligne' => $n, 'donnees' => $validateur->validated()];
        }

        // Passes successives : on reporte les lignes dont le parent est une ligne du fichier pas encore traitée.
        while ($aTraiter !== []) {
            $reportees = [];
            $progres = false;

            foreach ($aTraiter as $ligne) {
                $resultat = $this->creerLigne($ligne, $auteur);
                $codeParent = $ligne['donnees']['code_parent'] ?? null;

                if ($resultat['erreur'] === 'PARENT_INTROUVABLE'
                    && isset($codesFichier[$codeParent])
                    && ! isset($resultats[$codesFichier[$codeParent]])) {
                    $reportees[] = $ligne;

                    continue;
                }

                if ($resultat['erreur'] === 'PARENT_INTROUVABLE' && isset($codesFichier[$codeParent])) {
                    $resultat = $this->erreurParentNonCree($ligne, $codesFichier[$codeParent]);
                }

                $resultats[$ligne['ligne']] = $resultat;
                $progres = $progres || $resultat['statut'] === 'CREEE';
            }

            if (! $progres) {
                // Plus aucune création possible : dépendance circulaire entre lignes du fichier.
                foreach ($reportees as $ligne) {
                    $resultats[$ligne['ligne']] = $this->erreurParentNonCree($ligne, $codesFichier[$ligne['donnees']['code_parent']]);
                }
                break;
            }

            $aTraiter = $reportees;
        }

        return $resultats;
    }

    private function creerLigne(array $ligne, ?User $auteur): array
    {
        $n = $ligne['ligne'];
        $code = $ligne['donnees']['code'];

        try {
            DB::transaction(fn () => $this->structures->creer($ligne['donnees'], $auteur));

            return [
                'ligne' => $n, 'code' => $code, 'statut' => 'CREEE', 'erreur' => null,
                'message' => "Structure {$code} créée.", 'details' => new \stdClass,
            ];
        } catch (ApiException $e) {
            return $this->erreur($n, $code, $e->erreur, "Ligne {$n} : ".$e->getMessage(), $e->details);
        } catch (QueryException $e) {
            report($e);

            return $this->erreur($n, $code, 'DONNEE_INVALIDE',
                "Ligne {$n} (code {$code}) : une valeur a été refusée par la base de données.");
        } catch (Throwable $e) {
            report($e);

            return $this->erreur($n, $code, 'ERREUR_TECHNIQUE',
                "Ligne {$n} (code {$code}) : erreur technique, la ligne n'a pas été importée.");
        }
    }

    private function erreurParentNonCree(array $ligne, int $ligneParent): array
    {
        $n = $ligne['ligne'];
        $code = $ligne['donnees']['code'];
        $codeParent = $ligne['donnees']['code_parent'];

        return $this->erreur($n, $code, 'PARENT_EN_ERREUR',
            "Ligne {$n} : la structure parente {$codeParent} de {$code} n'a pas pu être créée (ligne {$ligneParent} du fichier en erreur ou rattachement circulaire).",
            ['code' => $code, 'code_parent' => $codeParent, 'ligne_parent' => $ligneParent]);
    }

    /** Même normalisation que CreerStructureRequest::prepareForValidation. */
    private function normaliser(array $donnees): array
    {
        $codeParent = $donnees['code_parent'] ?? '';

        return [
            'code' => mb_strtoupper($donnees['code']),
            'sigle' => $donnees['sigle'],
            'libelle' => $donnees['libelle'],
            'type' => mb_strtoupper($donnees['type']),
            'code_parent' => $codeParent === '' ? null : mb_strtoupper($codeParent),
        ];
    }

    private function erreur(int $ligne, ?string $code, string $erreur, string $message, array $details = []): array
    {
        return [
            'ligne' => $ligne,
            'code' => $code === '' ? null : $code,
            'statut' => 'ERREUR',
            'erreur' => $erreur,
            'message' => $message,
            'details' => $details === [] ? new \stdClass : $details,
        ];
    }
}
