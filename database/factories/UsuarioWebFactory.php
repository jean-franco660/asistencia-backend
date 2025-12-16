<?php

namespace Database\Factories;

use App\Models\UsuarioWeb;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UsuarioWebFactory extends Factory
{
    protected $model = UsuarioWeb::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => Hash::make('password'),
            'rol' => 'supervisor',
            'estado' => 'pendiente',
        ];
    }

    public function administrador(): static
    {
        return $this->state(fn(array $attributes) => [
            'rol' => 'administrador',
            'estado' => 'autorizado',
        ]);
    }

    public function supervisor(): static
    {
        return $this->state(fn(array $attributes) => [
            'rol' => 'supervisor',
            'estado' => 'pendiente',
        ]);
    }

    public function autorizado(): static
    {
        return $this->state(fn(array $attributes) => [
            'estado' => 'autorizado',
        ]);
    }
}
