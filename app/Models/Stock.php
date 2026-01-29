<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    protected $table = 'agent_tracking_stock';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'categorie', 'description', 'quantite', 'seuil_alerte',
        'unite', 'emplacement', 'etat_stock'
    ];

    protected $casts = [
        'quantite' => 'float',
        'seuil_alerte' => 'float',
        'date_creation' => 'datetime',
        'date_modification' => 'datetime',
    ];

    // Méthodes utilitaires
    public function calculateEtatStock()
    {
        if ($this->quantite <= 0) {
            return 'rupture';
        } elseif ($this->quantite <= $this->seuil_alerte) {
            return 'faible';
        } else {
            return 'normal';
        }
    }

    public function validateQuantite()
    {
        if ($this->quantite < 0) {
            throw new \Exception("La quantité ne peut pas être négative");
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = self::generateId();
            }
            if (empty($model->date_creation)) {
                $model->date_creation = now();
            }
            $model->etat_stock = $model->calculateEtatStock();
        });

        static::updating(function ($model) {
            $model->date_modification = now();
            $model->etat_stock = $model->calculateEtatStock();
            $model->validateQuantite();
        });
    }

    private static function generateId()
    {
        $last = self::orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->id, 2)) + 1 : 1;
        return 'ST' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}