<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'price'];

    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}


