<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $table = 'agent_tracking_agent';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    // DÉSACTIVER LES TIMESTAMPS - AJOUTEZ CETTE LIGNE
    public $timestamps = false;

    protected $fillable = [
        'id', 'nom', 'prenom', 'nom_complet', 'email', 'login',
        'motDePasse', 'role', 'telephone', 'statut', 'active',
        'dernier_pointage', 'odoo_user_id', 'odoo_partner_id'
    ];

    

    // Relations
    public function pointages()
    {
        return $this->hasMany(Pointage::class, 'agent_id');
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class, 'agent_id');
    }

    public function gpsHistory()
    {
        return $this->hasMany(AgentGPSHistory::class, 'agent_id');
    }

    // Méthodes utilitaires
    public function getStatutPointageAttribute()
    {
        $dernierPointage = $this->pointages()
            ->where('state', 'confirmed')
            // CORRIGER : utiliser une colonne qui existe dans la table pointages
            ->orderBy('horodatage_server', 'desc')
            ->first();

        if ($dernierPointage) {
            return [
                'statut' => $dernierPointage->type_pointage == 'arrivee' ? 'présent' : 'absent',
                'dernier_pointage' => $dernierPointage->horodatage_server,
                'site' => $dernierPointage->site ? $dernierPointage->site->nom : null
            ];
        }

        return ['statut' => 'absent', 'dernier_pointage' => null, 'site' => null];
    }

    public function getLastPositionAttribute()
    {
        $lastPosition = $this->gpsHistory()
            ->orderBy('timestamp', 'desc')
            ->first();

        if ($lastPosition) {
            return [
                'latitude' => $lastPosition->latitude,
                'longitude' => $lastPosition->longitude,
                'last_gps_update' => $lastPosition->timestamp
            ];
        }

        return [
            'latitude' => 0.0,
            'longitude' => 0.0,
            'last_gps_update' => null
        ];
    }

    public static function getAgentStats()
    {
        return [
            'total' => self::where('active', true)->count(),
            'online' => self::where('statut', 'online')->where('active', true)->count(),
            'absent' => self::where('statut', 'absent')->where('active', true)->count(),
            'presences' => self::where('statut', 'online')->where('active', true)->count(),
            'absences' => self::where('statut', 'absent')->where('active', true)->count()
        ];
    }

    // Méthode pour générer le token depuis le login (si nécessaire)
    public function generateToken()
    {
        // Exemple de génération de token basé sur le login
        return hash('sha256', $this->login . now()->timestamp . uniqid());
    }
}