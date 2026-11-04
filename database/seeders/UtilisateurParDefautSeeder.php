<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UtilisateurParDefautSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@referentiel.local'],
            ['name' => 'Administrateur du référentiel', 'password' => 'password'],
        );
    }
}
