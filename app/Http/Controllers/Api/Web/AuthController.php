<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginWebRequest;
use App\Models\UsuarioWeb;
use Illuminate\Support\Facades\Hash;

/**
 * Gestiona la autenticación de usuarios web mediante Sanctum.
 *
 * Accesible sin autenticación previa para login. Requiere credenciales válidas y,
 * en el caso de supervisores, que la cuenta esté en estado 'autorizado'.
 * Los tokens anteriores se revocan en cada inicio de sesión.
 */
class AuthController extends Controller
{
    /**
     * Autentica un usuario web y retorna el token de acceso.
     *
     * La búsqueda de email es insensible a mayúsculas para evitar duplicados por
     * diferencia de capitalizón. Los supervisores requieren estado 'autorizado';
     * los demás roles acceden sin esa restricción. Revoca todos los tokens previos
     * antes de crear uno nuevo. Los supervisores reciben además su lista de instituciones.
     */
    public function login(LoginWebRequest $request)
    {
        $email = strtolower($request->email);

        $user = UsuarioWeb::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($user->esSupervisor() && $user->estado !== 'autorizado') {
            return response()->json(['message' => 'Tu cuenta aún no ha sido autorizada'], 403);
        }

        // Se revocan todas las sesiones previas para evitar tokens activos simultáneos
        $user->tokens()->delete();

        $token = $user->createToken('web-token')->plainTextToken;

        $instituciones = $user->esSupervisor()
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

    /**
     * Cierra la sesión del usuario autenticado revocando el token actual.
     *
     * Si el token ya no existe, la operación es silenciosa y retorna éxito de todas formas.
     */
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

    /**
     * Retorna los datos del usuario web actualmente autenticado.
     *
     * Carga la relación con instituciones si aún no está cargada, para que el frontend
     * pueda mostrar las instituciones asignadas sin una solicitud adicional.
     */
    public function me()
    {
        $user = request()->user();
        $user->loadMissing(['instituciones:id,nombre']);

        return response()->json(['user' => $user]);
    }
}
