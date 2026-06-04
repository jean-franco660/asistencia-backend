<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los datos para actualizar una institución educativa existente.
 * Todos los campos son opcionales ('sometimes'), por lo que solo se validan
 * si están presentes en la petición. La unicidad del código modular excluye
 * el registro que se está actualizando para evitar falsos conflictos.
 */
class UpdateInstitucionRequest extends FormRequest
{
    /**
     * Determina si el usuario tiene autorización para actualizar la institución.
     * Los roles 'super_admin' y 'administrador' pueden editar cualquier institución.
     * El rol 'supervisor' solo puede editar las instituciones a las que está asignado.
     */
    public function authorize(): bool
    {
        if (in_array($this->user()->rol, ['super_admin', 'administrador'])) {
            return true;
        }

        return $this->user()->instituciones->pluck('id')->contains($this->route('id'));
    }

    /**
     * Retorna las reglas de validación para la actualización de la institución.
     * La unicidad del 'codigo_modular_ie' se valida ignorando el registro actual
     * para permitir que se envíe el mismo código sin generar un conflicto de unicidad.
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'codigo_modular_ie' => 'sometimes|required|string|max:20|unique:instituciones,codigo_modular_ie,' . $id,
            'nombre' => 'sometimes|required|string|max:255',
            'distrito' => 'sometimes|required|string|max:100',
            'nivel_educativo' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:150',
            'direccion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
            'radio' => 'nullable|numeric|min:1',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ];
    }
}