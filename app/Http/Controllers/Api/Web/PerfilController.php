<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PerfilController extends Controller
{
    /**
     * Cambiar contraseña del usuario autenticado
     */
    public function cambiarPassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'La contraseña actual es requerida',
            'new_password.required' => 'La nueva contraseña es requerida',
            'new_password.min' => 'La nueva contraseña debe tener al menos 8 caracteres',
            'new_password.confirmed' => 'Las contraseñas no coinciden',
        ]);

        $user = $request->user();

        // Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta'],
            ]);
        }

        // Actualizar la contraseña (el mutator setPasswordAttribute la hasheará automáticamente)
        $user->password = $request->new_password;
        $user->save();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente'
        ]);
    }

    /**
     * Cambiar email del usuario autenticado
     */
    public function cambiarEmail(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_email' => ['required', 'string', 'email', 'unique:usuarios_web,email'],
        ], [
            'current_password.required' => 'La contraseña es requerida para confirmar el cambio',
            'new_email.required' => 'El nuevo email es requerido',
            'new_email.email' => 'El email debe ser válido',
            'new_email.unique' => 'Este email ya está en uso',
        ]);

        $user = $request->user();

        // Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña es incorrecta'],
            ]);
        }

        // Actualizar el email
        $user->email = $request->new_email;
        $user->save();

        return response()->json([
            'message' => 'Email actualizado correctamente',
            'user' => $user
        ]);
    }
}
