<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TypeVisiteur extends Model
{

    protected $table = 'type_visiteur';
    public $timestamps = false; 
    
    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function visiteurs()
    {
        return $this->hasMany(Visiteur::class, 'type_visiteur_id');
    }

    // Boot method for tracking
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}