<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentGPSHistory extends Model
{
    protected $table = 'agent_gps_history';
    protected $primaryKey = 'id';
    
    public $timestamps = false;

    protected $fillable = [
        'agent_id', 
        'round_id', 
        'affectation_id',
        'latitude', 
        'longitude', 
        'accuracy', 
        'altitude',
        'timestamp',
        'source',
        'is_in_zone',
        'distance_from_zone'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
        'is_in_zone' => 'boolean',
        'distance_from_zone' => 'float',
        'accuracy' => 'float',
        'altitude' => 'float',
    ];

    // Relations
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function round()
    {
        return $this->belongsTo(Round::class, 'round_id');
    }

    public function affectation()
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    // ⭐ SURCHARGE DE CREATE POUR CALCULER AUTOMATIQUEMENT
    protected static function boot()
    {
        parent::boot();

        static::created(function ($position) {
            Log::info('🔄 Calcul automatique pour position ID: ' . $position->id);
            $position->calculateZoneStatus();
        });
    }

    // ⭐ MÉTHODE POUR CALCULER LE STATUT DE ZONE
    public function calculateZoneStatus()
    {
        try {
            if (!$this->round_id || !$this->latitude || !$this->longitude) {
                $this->update([
                    'is_in_zone' => false,
                    'distance_from_zone' => 0
                ]);
                Log::warning('⚠️ Données insuffisantes pour calcul zone');
                return;
            }
            
            // Récupérer le round
            $round = DB::table('agent_tracking_round')
                ->where('id', $this->round_id)
                ->select('latitude', 'longitude', 'radius_m')
                ->first();
            
            if (!$round) {
                Log::warning('❌ Round ' . $this->round_id . ' non trouvé');
                $this->update([
                    'is_in_zone' => false,
                    'distance_from_zone' => 0
                ]);
                return;
            }
            
            if (!$round->latitude || !$round->longitude) {
                Log::warning('⚠️ Round ' . $this->round_id . ' sans coordonnées');
                $this->update([
                    'is_in_zone' => false,
                    'distance_from_zone' => 0
                ]);
                return;
            }
            
            // Calculer la distance (identique à Odoo)
            $distance = $this->calculateHaversineDistance(
                $this->latitude,
                $this->longitude,
                $round->latitude,
                $round->longitude
            );
            
            // Déterminer si dans la zone
            $isInZone = false;
            if ($round->radius_m && $distance <= $round->radius_m) {
                $isInZone = true;
            }
            
            // Mettre à jour
            $this->update([
                'is_in_zone' => $isInZone,
                'distance_from_zone' => $distance
            ]);
            
            Log::info('✅ Zone calculée pour position ' . $this->id, [
                'distance' => round($distance, 2),
                'is_in_zone' => $isInZone,
                'round_radius' => $round->radius_m,
                'condition' => $distance . ' <= ' . $round->radius_m . ' = ' . ($isInZone ? 'VRAI' : 'FAUX')
            ]);
            
            // ⭐⭐ AJOUTER CETTE LIGNE POUR DÉCLENCHER LE POINTAGE AUTOMATIQUE
            Pointage::processGpsForAutoPointage($this->id);


            
        } catch (\Exception $e) {
            Log::error('❌ Erreur calcul zone: ' . $e->getMessage());
        }
    }
    
    // ⭐ MÉTHODE DE CALCUL HAVERSINE
    private function calculateHaversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return 0;
        }
        
        // Conversion degrés → radians
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);
        
        // Différence des coordonnées
        $dLat = $lat2Rad - $lat1Rad;
        $dLon = $lon2Rad - $lon1Rad;
        
        // Formule haversine
        $a = sin($dLat/2) * sin($dLat/2) + 
             cos($lat1Rad) * cos($lat2Rad) * 
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        // Rayon de la Terre en mètres
        $R = 6371000;
        
        return $R * $c;
    }
}