<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'code' => 'FACIAL_BASIC',
                'family' => 'facial',
                'name' => 'Limpieza facial básica',
                'description' => 'Limpieza y exfoliación básica.',
                'duration_minutes' => 45,
                'price_cents' => 3500,
            ],
            [
                'code' => 'FACIAL_PREMIUM',
                'family' => 'facial',
                'name' => 'Tratamiento facial premium',
                'description' => 'Tratamiento intensivo hidratante y rejuvenecedor.',
                'duration_minutes' => 60,
                'price_cents' => 6000,
            ],
            [
                'code' => 'MANICURE',
                'family' => 'manos',
                'name' => 'Manicura clásica',
                'description' => 'Corte, limado y esmaltado básico.',
                'duration_minutes' => 40,
                'price_cents' => 2500,
            ],
        ];

        foreach ($products as $data) {
            Product::updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, ['active' => true])
            );
        }
    }
}
