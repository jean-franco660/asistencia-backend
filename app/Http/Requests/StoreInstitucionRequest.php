<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstitucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // solo admin puede crear instituciones
        return $this->user()->rol === 'admin';
    }

    public function rules(): array
    {
        return [
            'nombre'     => 'required|string|max:150',
            'direccion'  => 'nullable|string|max:255',
            'latitud'    => 'required|numeric|between:-90,90',
            'longitud'   => 'required|numeric|between:-180,180',
            'radio'      => 'required|numeric|min:10|max:500', // metros
        ];
    }
}
