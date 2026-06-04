<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos requeridos para registrar una nueva institución educativa.
 * Solo los usuarios con rol 'super_admin' o 'administrador' pueden ejecutar esta acción.
 * Garantiza la unicidad del código modular institucional en el sistema.
 */
class StoreInstitucionRequest extends FormRequest
{
    /**
     * Restringe la operación a usuarios con rol administrativo.
     * Los supervisores no tienen permiso para crear instituciones.
     */
    public function authorize(): bool
    {
        return in_array($this->user()->rol, ['super_admin', 'administrador']);
    }

    /**
     * Define las reglas de validación para una nueva institución.
     * El campo 'radio' representa el radio de geovalla en metros para el registro de asistencia.
     * Las coordenadas 'latitud' y 'longitud' son opcionales pero necesarias para la geolocalización.
     */
    public function rules(): array
    {
        return [
            'codigo_modular_ie' => 'required|string|max:20|unique:instituciones,codigo_modular_ie',
            'nombre' => 'required|string|max:255',
            'distrito' => 'required|string|max:100',
            'nivel_educativo' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:150',
            'direccion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
            'radio' => 'nullable|numeric|min:1', // Radio de geovalla en metros; mínimo 1 metro
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048', // Máximo 2 MB
        ];
    }
}