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
            'nombre'     => 'sometimes|string|max:150',
            'direccion'  => 'sometimes|string|max:255',
            'latitud'    => 'sometimes|numeric|between:-90,90',
            'longitud'   => 'sometimes|numeric|between:-180,180',
            'radio'      => 'sometimes|numeric|min:10|max:500',
        ];
    }
}
