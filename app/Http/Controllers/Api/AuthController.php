<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginWebRequest;
use App\Models\UsuarioWeb;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginWebRequest $request)
    {
        $email = strtolower($request->email);

        $user = UsuarioWeb::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($user->rol === 'supervisor' && $user->estado !== 'autorizado') {
            return response()->json(['message' => 'Tu cuenta aún no ha sido autorizada'], 403);
        }

        // cerrar tokens previos
        $user->tokens()->delete();

        $token = $user->createToken('web-token')->plainTextToken;

        $instituciones = $user->rol === 'supervisor'
            ? $user->instituciones()->select('instituciones.id', 'instituciones.nombre')->get()
            : [];

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'nombre' => $user->nombre,
                'email' => $user->email,
                'rol' => $user->rol,
                'estado' => $user->estado,
                'instituciones' => $instituciones,
            ],
            'token' => $token,
        ]);
    }

    public function logout()
    {
        $token = request()->user()?->currentAccessToken();
        if ($token)
            $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    public function me()
    {
        $user = request()->user();
        $user->loadMissing(['instituciones:id,nombre']);

        return response()->json(['user' => $user]);
    }
}
