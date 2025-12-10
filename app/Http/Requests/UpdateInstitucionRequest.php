<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstitucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // admin puede editar cualquier institución
        // director solo instituciones asignadas
        if ($this->user()->rol === 'admin') {
            return true;
        }

        return $this->user()->instituciones->pluck('id')->contains($this->route('id'));
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|required|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
            'radio' => 'nullable|numeric',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
        ];
    }
}
