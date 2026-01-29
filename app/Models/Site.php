<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $table = 'agent_tracking_site';
    protected $primaryKey = 'id';
    
    // ⭐ ENLEVER created_at et updated_at de fillable
    protected $fillable = [
        'id', 'nom', 'adresse', 'superviseur_id', 'statut', 'date_creation'
    ];
    
    // ⭐ DÉSACTIVER LES TIMESTAMPS AUTOMATIQUES
    public $timestamps = false;
    
    // ⭐ CASTING DES DONNÉES
    protected $casts = [
        'date_creation' => 'datetime',
        'id' => 'integer',
        'superviseur_id' => 'integer',
    ];
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->date_creation)) {
                $model->date_creation = now();
            }
        });
    }
}