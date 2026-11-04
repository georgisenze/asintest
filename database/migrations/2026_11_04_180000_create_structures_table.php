<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recherche insensible aux accents (F3). unaccent() n'est pas IMMUTABLE :
        // on l'enveloppe pour pouvoir l'utiliser dans un index fonctionnel.
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);

        Schema::create('structures', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('sigle', 30);
            $table->string('libelle', 255);
            $table->string('type', 30);
            $table->foreignId('parent_id')->nullable()->constrained('structures')->restrictOnDelete();
            $table->string('statut', 10)->default('ACTIVE');
            $table->timestampTz('date_desactivation')->nullable();
            $table->text('motif_desactivation')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['parent_id', 'statut']);
            $table->index('type');
            $table->index(['updated_at', 'id']);
        });

        DB::statement("ALTER TABLE structures ADD CONSTRAINT structures_type_check CHECK (type IN ('MINISTERE','DIRECTION_GENERALE','DIRECTION','SERVICE'))");
        DB::statement("ALTER TABLE structures ADD CONSTRAINT structures_statut_check CHECK (statut IN ('ACTIVE','INACTIVE'))");
        DB::statement('ALTER TABLE structures ADD CONSTRAINT structures_pas_auto_rattachement CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('CREATE INDEX structures_libelle_trgm ON structures USING gin (f_unaccent(lower(libelle)) gin_trgm_ops)');
        DB::statement('CREATE INDEX structures_sigle_trgm ON structures USING gin (f_unaccent(lower(sigle)) gin_trgm_ops)');

        Schema::create('historique_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('structures')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('action', 20);
            $table->jsonb('donnees_avant')->nullable();
            $table->jsonb('donnees_apres');
            $table->jsonb('champs_modifies')->nullable();
            $table->text('motif')->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('effectue_le');

            $table->index(['structure_id', 'effectue_le']);
            $table->index('effectue_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_structures');
        Schema::dropIfExists('structures');
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
    }
};
