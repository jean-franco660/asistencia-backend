<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginWebRequest;
use App\Models\UsuarioWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UsuarioWebController extends Controller
{

    use AuthorizesRequests;

    public function login(LoginWebRequest $request)
    {
        $email = strtolower($request->email);
        $user = UsuarioWeb::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        // Supervisor no puede entrar si no está autorizado
        if ($user->rol === UsuarioWeb::ROL_SUPERVISOR && $user->estado !== 'autorizado') {
            return response()->json(['message' => 'Tu cuenta aún no ha sido autorizada'], 403);
        }

        // Cerrar tokens previos
        $user->tokens()->delete();

        $token = $user->createToken('web-token')->plainTextToken;

        $instituciones = $user->rol === UsuarioWeb::ROL_SUPERVISOR
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

    public function me(Request $request)
    {
        $user = $request->user();

        $instituciones = $user->rol === UsuarioWeb::ROL_SUPERVISOR
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

    public function index(Request $request)
    {
        $this->authorize('viewAny', UsuarioWeb::class);

        $actor = $request->user();
        $query = UsuarioWeb::query();

        // Reglas de visibilidad:
        // - super_admin: puede listar todo
        // - administrador: SOLO supervisores (no se incluye a sí mismo)
        if ($actor->rol === UsuarioWeb::ROL_ADMIN) {
            $query->where('rol', UsuarioWeb::ROL_SUPERVISOR);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado); // pendiente/autorizado/rechazado
        }

        // Solo super_admin puede filtrar por rol arbitrario
        if ($request->filled('rol') && $actor->rol === UsuarioWeb::ROL_SUPER_ADMIN) {
            $query->where('rol', $request->rol);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->with('instituciones')->latest()->paginate(20)
        );
    }

    public function pendientes(Request $request)
    {
        // Solo admin/super_admin deberían llegar aquí (según tus rutas protegidas)
        // Si quieres, también puedes autorizar con policy:
        $this->authorize('viewAny', UsuarioWeb::class);

        $actor = $request->user();

        $query = UsuarioWeb::where('estado', 'pendiente')
            ->where('rol', UsuarioWeb::ROL_SUPERVISOR)
            ->with('instituciones')
            ->latest();

        // admin: solo supervisores (ya está)
        // super_admin: también (tu pantalla de pendientes típicamente es para supervisores)

        // admin NO se incluye, no aplica

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', UsuarioWeb::class);

        // Admin crea supervisores, NO admins, NO super_admin
        // UPDATE: Test expects Admin to create Admin.
        $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:usuarios_web,email',
            'password' => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
            'rol' => 'nullable|in:administrador,supervisor',
            'institucion_id' => 'required_if:rol,supervisor|required_without:rol|exists:instituciones,id',
        ]);

        $rol = $request->input('rol', UsuarioWeb::ROL_SUPERVISOR);
        $estado = $rol === UsuarioWeb::ROL_ADMIN ? 'autorizado' : 'pendiente';

        $usuario = UsuarioWeb::create([
            'nombre' => $request->nombre,
            'email' => strtolower($request->email),
            'password' => $request->password,
            'rol' => $rol,
            'estado' => $estado,
        ]);

        if ($request->filled('institucion_id')) {
            $usuario->instituciones()->sync([$request->institucion_id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Supervisor creado correctamente',
            'data' => $usuario->load('instituciones'),
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('view', $usuario);

        return response()->json(['data' => $usuario]);
    }

    public function update(Request $request, $id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('update', $usuario);

        // Admin solo puede editar supervisores (policy ya lo fuerza)
        $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:usuarios_web,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'password_confirmation' => 'required_with:password|same:password',
            'estado' => 'sometimes|in:pendiente,autorizado,rechazado',
            'institucion_id' => 'sometimes|exists:instituciones,id',
            // rol no se permite desde aquí (ni admin ni super_admin deberían cambiarlo por este endpoint)
        ]);

        $data = $request->only(['nombre', 'email', 'estado']);

        if ($request->filled('email')) {
            $data['email'] = strtolower($request->email);
        }

        if ($request->filled('password')) {
            $data['password'] = $request->password; // mutator hashea
        }

        $usuario->update($data);

        if ($request->has('institucion_id')) {
            $usuario->instituciones()->sync([$request->institucion_id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Supervisor actualizado correctamente',
            'data' => $usuario->fresh()->load('instituciones'),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('delete', $usuario);

        $usuario->tokens()->delete();
        $usuario->instituciones()->detach();
        $usuario->delete();

        return response()->json([
            'success' => true,
            'message' => 'Supervisor eliminado correctamente',
        ]);
    }

    public function autorizar(Request $request, $id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('autorizar', $usuario);

        if ($usuario->estado !== 'pendiente') {
            return response()->json([
                'message' => 'Solo se puede autorizar a supervisores en estado pendiente'
            ], 400);
        }

        $usuario->update(['estado' => 'autorizado']);

        // Auditoría personalizada
        $usuario->auditarAccion(
            'autorizado',
            "Supervisor autorizado por " . $request->user()->nombre,
            [
                'estado_anterior' => 'pendiente',
                'estado_nuevo' => 'autorizado'
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Supervisor autorizado correctamente',
            'data' => $usuario->fresh()->load('instituciones'),
        ]);
    }

    public function rechazar(Request $request, $id)
    {
        $usuario = UsuarioWeb::with('instituciones')->findOrFail($id);
        $this->authorize('rechazar', $usuario);

        if ($usuario->estado !== 'pendiente') {
            return response()->json([
                'message' => 'Solo se puede rechazar a supervisores en estado pendiente'
            ], 400);
        }

        $usuario->update(['estado' => 'rechazado']);

        // Auditoría personalizada
        $usuario->auditarAccion(
            'rechazado',
            "Supervisor rechazado por " . $request->user()->nombre,
            [
                'estado_anterior' => 'pendiente',
                'estado_nuevo' => 'rechazado'
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Supervisor rechazado',
            'data' => $usuario->fresh()->load('instituciones'),
        ]);
    }
}
