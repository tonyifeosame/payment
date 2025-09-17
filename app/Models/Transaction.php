<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'admission_no', 'amount', 'status',
        'payment_method', 'email', 'name',
        'category_id', 'subcategory_id', 'meta_data'
    ];

    protected $casts = [
        'meta_data' => 'array', // JSONB in Postgres
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }
}
