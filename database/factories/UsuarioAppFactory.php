<?php

namespace Database\Factories;

use App\Models\UsuarioApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UsuarioAppFactory extends Factory
{
    protected $model = UsuarioApp::class;

    public function definition(): array
    {
        return [
            'codigo_modular_docente' => 'DOC' . $this->faker->unique()->numberBetween(100000, 999999),
            'apellido_paterno' => strtoupper($this->faker->lastName),
            'apellido_materno' => strtoupper($this->faker->lastName),
            'nombres' => strtoupper($this->faker->firstName),
            'sexo' => $this->faker->randomElement(['M', 'F']),
            'estado' => 'ACTIVO',
            'cargo' => 'DOCENTE',
            'password' => Hash::make('password'),
            'activo' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn(array $attributes) => [
            'activo' => false,
        ]);
    }

    public function withCargo(string $cargo): static
    {
        return $this->state(fn(array $attributes) => [
            'cargo' => $cargo,
        ]);
    }
}
