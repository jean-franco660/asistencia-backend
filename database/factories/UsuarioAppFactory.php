<?php

namespace Database\Factories;

use App\Models\UsuarioApp;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioAppFactory extends Factory
{
    protected $model = UsuarioApp::class;

    public function definition(): array
    {
        return [
            // Código modular genérico
            'codigo_modular' => 'CM' . $this->faker->unique()->numberBetween(100000, 999999),

            'apellido_paterno' => strtoupper($this->faker->lastName),
            'apellido_materno' => strtoupper($this->faker->lastName),
            'nombres' => strtoupper($this->faker->firstName),

            'sexo' => $this->faker->randomElement(['M', 'F']),
            'estado' => 'ACTIVO',
            // Cargo genérico por defecto
            'cargo' => 'PERSONAL',
            'password' => 'password',
            'activo' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'activo' => false,
        ]);
    }

    public function withCargo(string $cargo): static
    {
        return $this->state(fn () => [
            'cargo' => strtoupper($cargo),
        ]);
    }
}
