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
     * Obtener datos del usuario autenticado
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $instituciones = $user->rol === 'director'
            ? $user->instituciones()->select('instituciones.id', 'instituciones.nombre')->get()
            : [];

        return response()->json([
            'id' => $user->id,
            'nombre' => $user->nombre,
            'email' => $user->email,
            'rol' => $user->rol,
            'estado' => $user->estado,
            'instituciones' => $instituciones,
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

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $usuarios = $query->with('instituciones')->latest()->get();

        // 🔥 FIX: Agregar wrapper 'data'
        return response()->json(['data' => $usuarios]);
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

        return response()->json(['data' => $usuarios]);
    }

    /**
     * Crear nuevo usuario web
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:usuarios_web,email',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
            'rol' => 'required|in:admin,director',
            // 🔥 FIX: Validar institucion_id si es director
            'institucion_id' => 'required_if:rol,director|exists:instituciones,id',
        ]);

        $usuario = UsuarioWeb::create([
            'nombre' => $request->nombre,
            'email' => strtolower($request->email),
            'password' => $request->password,
            'rol' => $request->rol,
            'estado' => $request->rol === 'admin' ? 'autorizado' : ($request->estado ?? 'pendiente'),
        ]);

        // 🔥 FIX: Asignar institución si es director
        if ($request->rol === 'director' && $request->institucion_id) {
            $usuario->instituciones()->sync([$request->institucion_id]);
        }

        // Recargar con relaciones
        $usuario->load('instituciones');

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente',
            'data' => $usuario,
        ], 201);
    }

    /**
     * Mostrar un usuario específico
     */
    public function show($id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);

        return response()->json(['data' => $usuario]);
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
            'password' => 'sometimes|string|min:8',
            'password_confirmation' => 'required_with:password|same:password',
            'rol' => 'sometimes|in:admin,director',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
            // 🔥 FIX: Validar institucion_id
            'institucion_id' => 'sometimes|exists:instituciones,id',
        ]);

        $data = $request->only(['nombre', 'email', 'rol', 'estado']);

        if ($request->filled('email')) {
            $data['email'] = strtolower($request->email);
        }

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        // Si cambia a admin, autorizar automáticamente
        if (isset($data['rol']) && $data['rol'] === 'admin') {
            $data['estado'] = 'autorizado';
        }

        $usuario->update($data);

        // 🔥 FIX: Actualizar institución si se proporciona
        if ($request->has('institucion_id')) {
            $usuario->instituciones()->sync([$request->institucion_id]);
        }

        // Recargar con relaciones
        $usuario->load('instituciones');

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente',
            'data' => $usuario,
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

        // Desasociar instituciones
        $usuario->instituciones()->detach();

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
        $usuario->load('instituciones');

        return response()->json([
            'success' => true,
            'message' => 'Director autorizado correctamente',
            'data' => $usuario,
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
        $usuario->load('instituciones');

        return response()->json([
            'success' => true,
            'message' => 'Director rechazado',
            'data' => $usuario,
        ]);
    }
}