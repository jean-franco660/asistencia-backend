<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsuarioApp;
use App\Http\Requests\StoreUsuarioAppRequest;
use App\Http\Requests\UpdateUsuarioAppRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UsuarioAppController extends Controller
{
    use AuthorizesRequests;

    // =========================================================
    // LOGIN DOCENTE
    // =========================================================
    public function login(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string',
            'password' => 'required|string',
        ]);

        $codigo = strtolower($request->codigo);

        $usuario = UsuarioApp::whereRaw('LOWER(codigo) = ?', [$codigo])
            ->with('instituciones:id,nombre,latitud,longitud,radio')
            ->first();

        if (!$usuario || !Hash::check($request->password, $usuario->password)) {
            return response()->json(['error' => 'Credenciales incorrectas'], 422);
        }

        if (!$usuario->activo) {
            return response()->json(['error' => 'Usuario inactivo'], 403);
        }

        $token = $usuario->createToken('app-movil')->plainTextToken;

        return response()->json([
            'token' => $token,
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'codigo' => $usuario->codigo,
                'instituciones' => $usuario->instituciones->map(fn($i) => [
                    'id' => $i->id,
                    'nombre' => $i->nombre,
                    'latitud' => $i->latitud,
                    'longitud' => $i->longitud,
                    'radio' => $i->radio ?? 50,
                ]),
            ],
        ]);
    }

    // =========================================================
    // LISTAR DOCENTES
    // =========================================================
    public function index(Request $request)
    {
        if ($request->user()->rol === 'director') {
            $inst = $request->user()->instituciones->pluck('id');

            $docentes = UsuarioApp::whereHas('instituciones', function ($q) use ($inst) {
                $q->whereIn('institucion_id', $inst);
            })->with('instituciones:id,nombre')->get();
        } else {
            $docentes = UsuarioApp::with('instituciones:id,nombre')->get();
        }

        return response()->json(['data' => $docentes]);
    }

    // =========================================================
    // CREAR DOCENTE (CORREGIDO)
    // =========================================================
    public function store(StoreUsuarioAppRequest $request)
    {
        // Validación de director (si aplica)
        if ($request->user()->rol === 'director') {
            $instDirector = $request->user()->instituciones->pluck('id');

            foreach ($request->institucion_ids as $inst) {
                if (!$instDirector->contains($inst)) {
                    return response()->json(['error' => 'No autorizado'], 403);
                }
            }
        }

        // Crear usuario
        $data = $request->validated();
        $usuario = UsuarioApp::create($data);

        // Guardar instituciones (CORREGIDO)
        if ($request->has('institucion_ids')) {
            $usuario->instituciones()->sync($request->institucion_ids);
        }

        $usuario->load('instituciones:id,nombre');

        return response()->json($usuario, 201);
    }

    // MOSTRAR DOCENTE
    public function show($id)
    {
        $usuario = UsuarioApp::with('instituciones:id,nombre')->find($id);
        if (!$usuario) return response()->json(['message' => 'No encontrado'], 404);

        $this->authorize('view', $usuario);

        return response()->json($usuario);
    }

    // ACTUALIZAR DOCENTE
    public function update(UpdateUsuarioAppRequest $request, $id)
    {
        $usuario = UsuarioApp::find($id);
        if (!$usuario) return response()->json(['message' => 'No encontrado'], 404);

        $this->authorize('update', $usuario);

        // Validación especial para directores
        if ($request->user()->rol === 'director' && $request->has('institucion_ids')) {
            $instDirector = $request->user()->instituciones->pluck('id');

            foreach ($request->institucion_ids as $inst) {
                if (!$instDirector->contains($inst)) {
                    return response()->json(['error' => 'No autorizado'], 403);
                }
            }
        }

        // Actualizar datos del usuario
        $data = $request->validated();

        if (isset($data['password']) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $usuario->update($data);

        // SINCRONIZAR INSTITUCIONES
        if ($request->has('institucion_ids')) {
            $usuario->instituciones()->sync($request->institucion_ids);
        }

        $usuario->load('instituciones:id,nombre');

        return response()->json($usuario);
    }

    // ELIMINAR DOCENTE
    public function destroy(Request $request, $id)
    {
        $usuario = UsuarioApp::find($id);
        if (!$usuario) return response()->json(['message' => 'No encontrado'], 404);

        $this->authorize('delete', $usuario);

        $usuario->delete();

        return response()->json(['message' => 'Eliminado']);
    }
}
