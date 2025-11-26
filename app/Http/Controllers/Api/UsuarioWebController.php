<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginWebRequest;
use App\Models\UsuarioWeb;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;

class UsuarioWebController extends Controller
{
    /**
     * Login para usuarios web (admin/director)
     */
    public function login(LoginWebRequest $request)
    {
        $email = strtolower($request->email);

        $user = UsuarioWeb::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if ($user->rol === 'director' && $user->estado !== 'autorizado') {
            return response()->json(['message' => 'Tu cuenta aún no ha sido autorizada'], 403);
        }

        // Cerrar tokens previos
        $user->tokens()->delete();

        $token = $user->createToken('web-token')->plainTextToken;

        $instituciones = $user->rol === 'director'
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
     * Listar usuarios web
     */
    public function index(Request $request)
    {
        $query = UsuarioWeb::query();

        if ($request->has('rol')) {
            $query->where('rol', $request->rol);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $usuarios = $query->with('instituciones')->latest()->get();

        return response()->json($usuarios);
    }

    /**
     * Listar usuarios pendientes de autorización
     */
    public function pendientes()
    {
        $usuarios = UsuarioWeb::where('estado', 'pendiente')
            ->where('rol', 'director')
            ->with('instituciones')
            ->latest()
            ->get();

        return response()->json($usuarios);
    }

    /**
     * Crear nuevo usuario web
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:usuarios_web,email',
            'password' => 'required|string|min:6',
            'rol' => 'required|in:admin,director',
        ]);

        $usuario = UsuarioWeb::create([
            'nombre' => $request->nombre,
            'email' => strtolower($request->email),
            'password' => $request->password, // Se hashea automáticamente en el modelo
            'rol' => $request->rol,
            'estado' => $request->rol === 'admin' ? 'autorizado' : 'pendiente',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente',
            'usuario' => $usuario,
        ], 201);
    }

    /**
     * Mostrar un usuario específico
     */
    public function show($id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);

        return response()->json($usuario);
    }

    /**
     * Actualizar usuario web
     */
    public function update(Request $request, $id)
    {
        $usuario = UsuarioWeb::findOrFail($id);

        $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:usuarios_web,email,' . $id,
            'password' => 'sometimes|string|min:6',
            'rol' => 'sometimes|in:admin,director',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
        ]);

        $data = $request->only(['nombre', 'email', 'rol', 'estado']);

        if ($request->filled('email')) {
            $data['email'] = strtolower($request->email);
        }

        if ($request->filled('password')) {
            $data['password'] = $request->password; // Se hashea automáticamente
        }

        // Si cambia a admin, autorizar automáticamente
        if (isset($data['rol']) && $data['rol'] === 'admin') {
            $data['estado'] = 'autorizado';
        }

        $usuario->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente',
            'usuario' => $usuario->fresh(),
        ]);
    }

    /**
     * Eliminar usuario web (soft delete)
     */
    public function destroy($id)
    {
        $usuario = UsuarioWeb::findOrFail($id);
        
        // Eliminar tokens antes de borrar
        $usuario->tokens()->delete();
        
        $usuario->delete();

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado correctamente',
        ]);
    }

    /**
     * Autorizar un director pendiente
     */
    public function autorizar($id)
    {
        $usuario = UsuarioWeb::findOrFail($id);

        if ($usuario->rol !== 'director') {
            return response()->json([
                'message' => 'Solo se pueden autorizar directores',
            ], 400);
        }

        $usuario->update(['estado' => 'autorizado']);

        return response()->json([
            'success' => true,
            'message' => 'Director autorizado correctamente',
            'usuario' => $usuario,
        ]);
    }

    /**
     * Rechazar un director pendiente
     */
    public function rechazar($id)
    {
        $usuario = UsuarioWeb::findOrFail($id);

        if ($usuario->rol !== 'director') {
            return response()->json([
                'message' => 'Solo se pueden rechazar directores',
            ], 400);
        }

        $usuario->update(['estado' => 'rechazado']);

        return response()->json([
            'success' => true,
            'message' => 'Director rechazado',
            'usuario' => $usuario,
        ]);
    }
}