<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'code' => 'PRJ-'.fake()->unique()->numerify('####'),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'category_id' => ProjectCategory::factory(),
            'location' => fake()->optional()->city(),
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addMonths(3)->toDateString(),
            'responsible_user_id' => User::factory(),
            'status' => ProjectStatus::Planned,
            'progress' => 0,
            'created_by' => User::factory(),
        ];
    }
}
