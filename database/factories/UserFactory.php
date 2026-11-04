<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discord_id' => fake()->unique()->numerify('######'),
            'username' => fake()->userName(),
            'avatar_url' => fake()->imageUrl(64, 64),
            'remember_token' => Str::random(10),
        ];
    }
}
