<?php

namespace Database\Factories;

use App\Models\ProjectCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectCategoryFactory extends Factory
{
    protected $model = ProjectCategory::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'description' => fake()->optional()->sentence(), 'is_active' => true];
    }
}
