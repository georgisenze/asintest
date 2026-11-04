<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\StructureResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $utilisateur = User::where('email', mb_strtolower($request->string('email')))->first();

        if (! $utilisateur || ! Hash::check($request->string('password'), $utilisateur->password)) {
            // 401 avec un message volontairement identique (pas d'énumération des comptes).
            throw new ApiException(401, 'IDENTIFIANTS_INVALIDES', 'Adresse e-mail ou mot de passe incorrect.');
        }

        $minutes = config('sanctum.expiration');
        $expiration = $minutes ? now('UTC')->addMinutes($minutes) : null;
        $jeton = $utilisateur->createToken($request->input('nom_appareil', 'api'), ['*'], $expiration);

        return response()->json([
            'jeton' => $jeton->plainTextToken,
            'type' => 'Bearer',
            'expire_le' => StructureResource::iso($expiration),
            'utilisateur' => $this->utilisateur($utilisateur),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->utilisateur($request->user()));
    }

    private function utilisateur(User $u): array
    {
        return ['id' => $u->id, 'nom' => $u->name, 'email' => $u->email];
    }
}
