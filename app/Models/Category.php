<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'school_id'];

    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
