<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Superviseur extends Model
{
    protected $table = 'agent_tracking_superviseur';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    
    public $timestamps = false;

    protected $fillable = [
        'nom', 'prenom', 'nom_complet', 'email', 'login',
        'motDePasse', 'role', 'telephone', 'active',
        'dernier_connexion','odoo_user_id', 'odoo_partner_id'
    ];

    // Relations
    public function agents()
    {
        return $this->hasMany(Agent::class, 'superviseur_id');
    }

    public function rounds()
    {
        return $this->hasMany(Round::class, 'superviseur_id');
    }

    public function sites()
    {
        return $this->belongsToMany(Site::class, 'agent_tracking_superviseur_site', 'superviseur_id', 'site_id');
    }

    // Méthodes utilitaires
    public static function authenticate($login, $password)
    {
        return self::where('login', $login)
            ->where('motDePasse', $password)
            ->where('active', true)
            ->first();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
    
            // GÉNÉRER LE NOM_COMPLET SEULEMENT SI IL N'EST PAS FOURNI
            if (empty($model->nom_complet) && !empty($model->prenom) && !empty($model->nom)) {
                $model->nom_complet = $model->prenom . ' ' . $model->nom;
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty(['nom', 'prenom'])) {
                $model->nom_complet = $model->prenom . ' ' . $model->nom;
            }
        });
    }
    

}