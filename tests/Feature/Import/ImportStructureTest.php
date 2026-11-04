<?php

use App\Enums\TypeStructure;
use App\Models\HistoriqueStructure;
use App\Models\Structure;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

function fichierImport(string $contenu, string $nom = 'structures.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nom, $contenu);
}

function lignesParNumero(array $rapport): array
{
    return collect($rapport['lignes'])->keyBy('ligne')->all();
}

beforeEach(function () {
    $this->utilisateur = User::factory()->create();
    Sanctum::actingAs($this->utilisateur);
});

it('importe un fichier nominal et crée l\'historique de chaque ligne', function () {
    $csv = "code;sigle;libelle;type;code_parent\n"
        ."MIN-AGRI;MINADER;Ministère de l'Agriculture;MINISTERE;\n"
        ."DGPA;DGPA;Direction générale de la Production;DIRECTION_GENERALE;MIN-AGRI\n"
        ."DPV;DPV;Direction de la Protection des végétaux;DIRECTION;DGPA\n"
        ."DPV-SPHYTO;SPHYTO;Service phytosanitaire;SERVICE;DPV\n";

    $rapport = $this->post('/api/structures/import', ['fichier' => fichierImport($csv)])
        ->assertOk()
        ->assertJsonPath('simulation', false)
        ->assertJsonPath('total_lignes', 4)
        ->assertJsonPath('creees', 4)
        ->assertJsonPath('en_erreur', 0)
        ->json();

    expect(collect($rapport['lignes'])->pluck('statut')->unique()->all())->toBe(['CREEE']);
    expect(Structure::where('code', 'DPV-SPHYTO')->first()->parent->code)->toBe('DPV');
    expect(HistoriqueStructure::where('action', 'CREATION')->where('utilisateur_id', $this->utilisateur->id)->count())->toBe(4);
});

it('crée une structure dont le parent est défini plus bas dans le fichier', function () {
    $csv = "code;sigle;libelle;type;code_parent\n"
        ."SRV-A;SRVA;Service A;SERVICE;DIR-A\n"
        ."DIR-A;DIRA;Direction A;DIRECTION;MIN-A\n"
        ."MIN-A;MINA;Ministère A;MINISTERE;\n";

    $rapport = $this->post('/api/structures/import', ['fichier' => fichierImport($csv)])
        ->assertOk()->assertJsonPath('creees', 3)->json();

    expect(collect($rapport['lignes'])->pluck('ligne')->all())->toBe([2, 3, 4]);
    expect(Structure::where('code', 'SRV-A')->first()->parent->code)->toBe('DIR-A');
});

it('rapporte chaque erreur sans annuler les lignes valides', function () {
    $existant = Structure::factory()->create(['code' => 'MIN-EXIST', 'sigle' => 'MEX']);
    Structure::factory()->sous($existant, TypeStructure::DIRECTION_GENERALE)->inactive()->create(['code' => 'DG-OLD']);

    $csv = "code;sigle;libelle;type;code_parent\n"
        ."MIN-NEW;MNEW;Ministère nouveau;MINISTERE;\n"                     // 2 ok
        ."x;X;Code invalide;DIRECTION;MIN-NEW\n"                             // 3 format
        ."DG-SANS;DGS;Direction générale sans parent;DIRECTION_GENERALE;\n"  // 4 hiérarchie
        ."DIR-INACT;DIN;Direction sous parent inactif;DIRECTION;DG-OLD\n"    // 5 parent inactif
        ."MIN-NEW;MN2;Doublon dans le fichier;MINISTERE;\n"                  // 6 doublon fichier
        ."MIN-EXIST;ME2;Doublon en base;MINISTERE;\n"                        // 7 doublon base
        ."DIR-FANT;DFA;Parent inexistant;DIRECTION;MIN-INCONNU\n"            // 8 parent introuvable
        ."DIR-COL;DCO;Colonne manquante;DIRECTION\n"                         // 9 colonnes
        ."DIR-ORPH;DOR;Parent en erreur;DIRECTION;DG-SANS\n";                // 10 parent en erreur

    $rapport = $this->post('/api/structures/import', ['fichier' => fichierImport($csv)])
        ->assertOk()->assertJsonPath('creees', 1)->assertJsonPath('en_erreur', 8)->json();

    $lignes = lignesParNumero($rapport);
    expect($lignes[2]['statut'])->toBe('CREEE')
        ->and($lignes[3]['erreur'])->toBe('VALIDATION_ECHOUEE')
        ->and($lignes[4]['erreur'])->toBe('HIERARCHIE_INVALIDE')
        ->and($lignes[5]['erreur'])->toBe('PARENT_INACTIF')
        ->and($lignes[6]['erreur'])->toBe('CODE_EN_DOUBLON_FICHIER')
        ->and($lignes[7]['erreur'])->toBe('CODE_EXISTANT')
        ->and($lignes[8]['erreur'])->toBe('PARENT_INTROUVABLE')
        ->and($lignes[9]['erreur'])->toBe('COLONNES_INCORRECTES')
        ->and($lignes[10]['erreur'])->toBe('PARENT_EN_ERREUR');

    expect(Structure::where('code', 'MIN-NEW')->exists())->toBeTrue();
    expect(Structure::whereIn('code', ['DG-SANS', 'DIR-INACT', 'DIR-ORPH'])->exists())->toBeFalse();
});

it('n\'écrit rien en mode simulation', function () {
    $csv = "code;sigle;libelle;type;code_parent\nMIN-SIM;MSIM;Ministère simulé;MINISTERE;\nDIR-SIM;DSIM;Direction simulée;DIRECTION;MIN-SIM\n";

    $this->post('/api/structures/import', ['fichier' => fichierImport($csv), 'simulation' => 'true'])
        ->assertOk()->assertJsonPath('simulation', true)->assertJsonPath('creees', 2);

    expect(Structure::count())->toBe(0)->and(HistoriqueStructure::count())->toBe(0);
});

it('gère le BOM UTF-8, le séparateur virgule, les fins de ligne Windows et les lignes vides', function () {
    $csv = "\xEF\xBB\xBFcode,sigle,libelle,type,code_parent\r\n\r\nMIN-BOM,MBOM,\"Ministère, avec virgule\",MINISTERE,\r\n";

    $rapport = $this->post('/api/structures/import', ['fichier' => fichierImport($csv)])
        ->assertOk()->assertJsonPath('separateur', ',')->assertJsonPath('creees', 1)->json();

    expect($rapport['lignes'][0]['ligne'])->toBe(3);
    expect(Structure::where('code', 'MIN-BOM')->value('libelle'))->toBe('Ministère, avec virgule');
});

it('convertit un fichier Windows-1252 en UTF-8', function () {
    $csv = mb_convert_encoding("code;sigle;libelle;type;code_parent\nMIN-ENC;MENC;Ministère de l'Économie;MINISTERE;\n", 'Windows-1252', 'UTF-8');

    $this->post('/api/structures/import', ['fichier' => fichierImport($csv)])->assertOk()->assertJsonPath('creees', 1);

    expect(Structure::where('code', 'MIN-ENC')->value('libelle'))->toBe('Ministère de l\'Économie');
});

it('rejette en 422 un fichier inexploitable', function (string $contenu, string $code) {
    attendreErreur($this->post('/api/structures/import', ['fichier' => fichierImport($contenu)]), 422, $code);
})->with([
    'vide' => ['', 'FICHIER_VIDE'],
    'en-tête seule' => ["code;sigle;libelle;type;code_parent\n", 'AUCUNE_DONNEE'],
    'en-tête absente' => ["MIN-X;MX;Ministère X;MINISTERE;\n", 'EN_TETE_INVALIDE'],
    'colonne inconnue' => ["code;sigle;libelle;type;couleur\nMIN-X;MX;Ministère X;MINISTERE;bleu\n", 'COLONNES_INCONNUES'],
]);

it('rejette un fichier non CSV, absent ou trop volumineux', function () {
    attendreErreur($this->post('/api/structures/import', ['fichier' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')]), 422, 'VALIDATION_ECHOUEE')
        ->assertJsonStructure(['details' => ['fichier']]);
    attendreErreur($this->post('/api/structures/import', []), 422, 'VALIDATION_ECHOUEE');
    attendreErreur($this->post('/api/structures/import', ['fichier' => UploadedFile::fake()->create('gros.csv', 3000, 'text/csv')]), 422, 'VALIDATION_ECHOUEE');
});

it('refuse un fichier de plus de 5000 lignes', function () {
    $csv = "code;sigle;libelle;type;code_parent\n".str_repeat("MIN-X;MX;Ministère X;MINISTERE;\n", 5001);

    attendreErreur($this->post('/api/structures/import', ['fichier' => fichierImport($csv)]), 422, 'TROP_DE_LIGNES');
});

it('exige une authentification', function () {
    app('auth')->forgetGuards();

    attendreErreur(
        $this->withHeader('Authorization', '')->post('/api/structures/import', ['fichier' => fichierImport("code\n")]),
        401, 'NON_AUTHENTIFIE');
});
