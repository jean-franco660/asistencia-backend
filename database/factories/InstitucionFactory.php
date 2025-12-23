<?php

namespace Database\Factories;

use App\Models\Institucion;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstitucionFactory extends Factory
{
    protected $model = Institucion::class;

    public function definition(): array
    {
        return [
            'codigo_modular_ie' => 'CM' . $this->faker->unique()->numberBetween(100000, 999999),
            'nombre' => $this->faker->company . ' - Institución Educativa',
            'distrito' => $this->faker->city,
            'nivel_educativo' => $this->faker->randomElement(['Inicial', 'Primaria', 'Secundaria']),
            'latitud' => $this->faker->latitude(-13, -11),
            'longitud' => $this->faker->longitude(-78, -76),
            'radio' => $this->faker->numberBetween(30, 100),
        ];
    }
}
