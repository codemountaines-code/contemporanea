<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'family',
        'name',
        'description',
        'duration_minutes',
        'price_cents',
        'active',
    ];
}
