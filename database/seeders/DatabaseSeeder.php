<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name'     => 'Analis WebGIS',
            'email'    => 'analis@example.com',
            'password' => bcrypt('password123'),
            'role'     => 'analyst',
        ]);

        Project::create([
            'user_id'     => $user->id,
            'name'        => 'Proyek Pemetaan Bali',
            'description' => 'Proyek pengujian area of interest WebGIS',
            'status'      => 'active',
        ]);

        $this->call([
            GeosocialLayerSeeder::class,
        ]);
    }
}