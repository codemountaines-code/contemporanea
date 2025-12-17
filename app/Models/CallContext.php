<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallContext extends Model
{
    protected $fillable = [
        'call_sid',
        'step',
        'family',
        'product_id',
        'requested_date',
        'requested_time',
        'customer_phone',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
