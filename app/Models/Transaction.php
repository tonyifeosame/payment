<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'reference',
        'category_id',
        'subcategory_id',
        'category_name',
        'subcategory_name',
        'admission_number',
        'email',
        'name',
        'amount',
        'status',
        'payment_method',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
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
