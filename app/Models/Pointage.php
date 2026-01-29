<?php
// app/Models/Pointage.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Pointage extends Model
{
    use HasFactory;

    protected $table = 'agent_pointage';
    protected $primaryKey = 'id';
    
    public $timestamps = false;

    // Types de pointage
    const TYPE_ARRIVEE = 'arrivee';
    const TYPE_DEPART = 'depart';

    // Statuts
    const STATUT_DRAFT = 'draft';
    const STATUT_AUTO = 'auto';
    const STATUT_CONFIRMED = 'confirmed';
    const STATUT_CANCELLED = 'cancelled';

    protected $fillable = [
        'agent_id',
        'type_pointage',
        'latitude',
        'longitude',
        'precision',
        'adresse',
        'horodatage_server',
        'horodatage_app',
        'site_id',
        'round_id',
        'affectation_id',
        'state',
        'create_date',
        'write_date',
        'create_uid',
        'write_uid'
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'precision' => 'float',
        'horodatage_server' => 'datetime',
        'horodatage_app' => 'datetime',
        'create_date' => 'datetime',
        'write_date' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($pointage) {
            if (empty($pointage->horodatage_server)) {
                $pointage->horodatage_server = Carbon::now();
            }
            if (empty($pointage->state)) {
                $pointage->state = self::STATUT_AUTO;
            }
            if (empty($pointage->create_date)) {
                $pointage->create_date = Carbon::now();
            }
            if (empty($pointage->write_date)) {
                $pointage->write_date = Carbon::now();
            }
            if (empty($pointage->create_uid)) {
                $pointage->create_uid = 1; // Admin par défaut
            }
            if (empty($pointage->write_uid)) {
                $pointage->write_uid = 1; // Admin par défaut
            }
        });

        static::updating(function ($pointage) {
            $pointage->write_date = Carbon::now();
            if (empty($pointage->write_uid)) {
                $pointage->write_uid = 1; // Admin par défaut
            }
        });

        static::created(function ($pointage) {
            // Mettre à jour le statut de l'agent
            $pointage->updateAgentStatus();
        });
    }

    // Relations
    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function round()
    {
        return $this->belongsTo(Round::class, 'round_id');
    }

    public function affectation()
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    // Méthodes de statut
    public function isArrivee()
    {
        return $this->type_pointage === self::TYPE_ARRIVEE;
    }

    public function isDepart()
    {
        return $this->type_pointage === self::TYPE_DEPART;
    }

    public function isConfirmed()
    {
        return $this->state === self::STATUT_CONFIRMED;
    }

    public function isAuto()
    {
        return $this->state === self::STATUT_AUTO;
    }

    // Actions
    public function actionConfirm()
    {
        $this->state = self::STATUT_CONFIRMED;
        $this->save();
        
        // Mettre à jour le statut de l'agent
        $this->updateAgentStatus();
        
        return $this;
    }

    public function actionCancel()
    {
        $this->state = self::STATUT_CANCELLED;
        $this->save();
        
        // Revenir au statut précédent de l'agent si nécessaire
        $this->revertAgentStatus();
        
        return $this;
    }

    // Mettre à jour le statut de l'agent
    private function updateAgentStatus()
    {
        if (!$this->agent) {
            return;
        }

        if ($this->isConfirmed() || $this->isAuto()) {
            if ($this->isArrivee()) {
                $this->agent->update([
                    'statut' => 'online',
                    'dernier_pointage' => $this->horodatage_server,
                    'current_site_id' => $this->site_id,
                    'current_round_id' => $this->round_id
                ]);
            } elseif ($this->isDepart()) {
                $this->agent->update([
                    'statut' => 'offline',
                    'dernier_pointage' => $this->horodatage_server,
                    'current_site_id' => null,
                    'current_round_id' => null
                ]);
            }
        }
    }

    // Revenir au statut précédent de l'agent
    private function revertAgentStatus()
    {
        if (!$this->agent) {
            return;
        }

        // Trouver le pointage précédent valide
        $previousPointage = self::where('agent_id', $this->agent_id)
            ->where('id', '<', $this->id)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->orderBy('id', 'desc')
            ->first();

        if ($previousPointage && $previousPointage->isConfirmed()) {
            if ($previousPointage->isArrivee()) {
                $this->agent->update([
                    'statut' => 'online',
                    'dernier_pointage' => $previousPointage->horodatage_server,
                    'current_site_id' => $previousPointage->site_id,
                    'current_round_id' => $previousPointage->round_id
                ]);
            } else {
                $this->agent->update([
                    'statut' => 'offline',
                    'dernier_pointage' => $previousPointage->horodatage_server,
                    'current_site_id' => null,
                    'current_round_id' => null
                ]);
            }
        } else {
            // Pas de pointage précédent, mettre offline
            $this->agent->update([
                'statut' => 'offline',
                'dernier_pointage' => null,
                'current_site_id' => null,
                'current_round_id' => null
            ]);
        }
    }

    // Méthodes de traitement automatique
    public static function processGpsForAutoPointage($gpsHistoryId)
    {
        $gpsRecord = AgentGPSHistory::find($gpsHistoryId);
        
        if (!$gpsRecord || !$gpsRecord->agent_id) {
            return null;
        }

        // 1. Chercher une affectation active
        $affectation = self::findActiveAffectation($gpsRecord);
        
        if (!$affectation) {
            return null;
        }

        // Mettre à jour le round_id du GPS si nécessaire
        if (!$gpsRecord->round_id && $affectation->round_id) {
            $gpsRecord->round_id = $affectation->round_id;
            $gpsRecord->save();
        }

        // 2. Vérifier si l'agent est dans la zone
        if ($gpsRecord->is_in_zone) {
            return self::processArrival($affectation, $gpsRecord);
        } else {
            return self::processDepartureIfNeeded($affectation, $gpsRecord);
        }
    }

    private static function findActiveAffectation($gpsRecord)
    {
        $query = Affectation::where('agent_id', $gpsRecord->agent_id)
            ->whereIn('statut_affectation', ['planifie', 'en_cours'])
            ->where('date_debut_affectation', '<=', $gpsRecord->timestamp)
            ->where('date_fin_affectation', '>=', $gpsRecord->timestamp);

        if ($gpsRecord->round_id) {
            $query->where('round_id', $gpsRecord->round_id);
        }

        $affectation = $query->first();

        if ($affectation) {
            return $affectation;
        }

        // Si pas trouvé avec round_id spécifique, chercher n'importe quelle affectation
        if (!$gpsRecord->round_id) {
            $affectation = Affectation::where('agent_id', $gpsRecord->agent_id)
                ->whereIn('statut_affectation', ['planifie', 'en_cours'])
                ->where('date_debut_affectation', '<=', $gpsRecord->timestamp)
                ->where('date_fin_affectation', '>=', $gpsRecord->timestamp)
                ->first();

            return $affectation ?: false;
        }

        return false;
    }

    private static function processArrival($affectation, $gpsRecord)
    {
        // Vérifier si un pointage d'arrivée existe déjà
        $existingArrival = self::where('affectation_id', $affectation->id)
            ->where('type_pointage', self::TYPE_ARRIVEE)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->first();

        if ($existingArrival) {
            return null;
        }

        // Vérifier que la position GPS est dans la période de l'affectation
        if (!($affectation->date_debut_affectation <= $gpsRecord->timestamp && 
              $gpsRecord->timestamp <= $affectation->date_fin_affectation)) {
            return null;
        }

        // Créer le pointage d'arrivée
        $pointageData = [
            'agent_id' => $affectation->agent_id,
            'type_pointage' => self::TYPE_ARRIVEE,
            'affectation_id' => $affectation->id,
            'round_id' => $affectation->round_id,
            'site_id' => $affectation->round->site_id ?? null,
            'latitude' => $gpsRecord->latitude,
            'longitude' => $gpsRecord->longitude,
            'precision' => $gpsRecord->accuracy,
            'horodatage_server' => $gpsRecord->timestamp,
            'horodatage_app' => $gpsRecord->timestamp,
            'state' => self::STATUT_AUTO,
            'adresse' => $affectation->round->address ?? "Pointage automatique"
        ];

        try {
            $pointage = self::create($pointageData);

            // Si affectation était planifiée, la passer en cours
            if ($affectation->statut_affectation == 'planifie') {
                $affectation->statut_affectation = 'en_cours';
                $affectation->save();
            }

            // TODO: Ajouter un message au système de notification

            return $pointage;

        } catch (\Exception $e) {
            \Log::error('Erreur création pointage arrivée: ' . $e->getMessage());
            return null;
        }
    }

    private static function processDepartureIfNeeded($affectation, $gpsRecord)
    {
        // Vérifier qu'un pointage d'arrivée existe
        $arrivalPointage = self::where('affectation_id', $affectation->id)
            ->where('type_pointage', self::TYPE_ARRIVEE)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->first();

        if (!$arrivalPointage) {
            return null;
        }

        // Vérifier qu'il n'y a pas déjà un pointage de départ
        $existingDeparture = self::where('affectation_id', $affectation->id)
            ->where('type_pointage', self::TYPE_DEPART)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->first();

        if ($existingDeparture) {
            return null;
        }

        // Vérifier si l'agent est actuellement hors zone
        if ($gpsRecord->is_in_zone) {
            return null;
        }

        // Vérifier le délai de confirmation (5 minutes)
        $checkTime = $gpsRecord->timestamp;
        $thresholdTime = $checkTime->copy()->subMinutes(5);

        // Vérifier si l'agent était dans la zone pendant les 5 dernières minutes
        $recentInsidePositions = AgentGPSHistory::where('agent_id', $affectation->agent_id)
            ->where('round_id', $affectation->round_id)
            ->where('is_in_zone', true)
            ->where('timestamp', '>=', $thresholdTime)
            ->where('timestamp', '<', $checkTime)
            ->first();

        if (!$recentInsidePositions) {
            return null;
        }

        // Trouver la dernière position dans la zone
        $lastInsidePosition = $recentInsidePositions;
        $departureTime = $lastInsidePosition->timestamp;

        // Créer le pointage de départ
        $pointageData = [
            'agent_id' => $affectation->agent_id,
            'type_pointage' => self::TYPE_DEPART,
            'affectation_id' => $affectation->id,
            'round_id' => $affectation->round_id,
            'site_id' => $affectation->round->site_id ?? null,
            'latitude' => $gpsRecord->latitude,
            'longitude' => $gpsRecord->longitude,
            'precision' => $gpsRecord->accuracy,
            'horodatage_server' => $departureTime,
            'horodatage_app' => $departureTime,
            'state' => self::STATUT_AUTO,
            'adresse' => $affectation->round->address ?? "Départ anticipé"
        ];

        try {
            $pointage = self::create($pointageData);

            // Marquer l'affectation comme terminée
            $affectation->statut_affectation = 'termine';
            $affectation->save();

            // TODO: Ajouter un message au système de notification

            return $pointage;

        } catch (\Exception $e) {
            \Log::error('Erreur création pointage départ: ' . $e->getMessage());
            return null;
        }
    }

    // Cron pour gérer les départs à la date_fin des affectations
    public static function processScheduledDepartures()
    {
        $now = Carbon::now();
        
        // Trouver les affectations qui doivent se terminer
        $affectationsToEnd = Affectation::where('statut_affectation', 'en_cours')
            ->where('date_fin_affectation', '<=', $now)
            ->where('date_fin_affectation', '>=', $now->copy()->subHours(2))
            ->get();

        foreach ($affectationsToEnd as $affectation) {
            self::createScheduledDeparture($affectation);
        }

        return true;
    }

    private static function createScheduledDeparture($affectation)
    {
        // Vérifier si un pointage d'arrivée existe
        $arrivalPointage = self::where('affectation_id', $affectation->id)
            ->where('type_pointage', self::TYPE_ARRIVEE)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->first();

        if (!$arrivalPointage) {
            self::createAbsenceLog($affectation);
            $affectation->statut_affectation = 'annule';
            $affectation->save();
            return;
        }

        // Vérifier si un pointage de départ existe déjà
        $existingDeparture = self::where('affectation_id', $affectation->id)
            ->where('type_pointage', self::TYPE_DEPART)
            ->where('state', '!=', self::STATUT_CANCELLED)
            ->first();

        if ($existingDeparture) {
            return;
        }

        $pointageData = [
            'agent_id' => $affectation->agent_id,
            'type_pointage' => self::TYPE_DEPART,
            'affectation_id' => $affectation->id,
            'round_id' => $affectation->round_id,
            'site_id' => $affectation->round->site_id ?? null,
            'horodatage_server' => $affectation->date_fin_affectation,
            'horodatage_app' => $affectation->date_fin_affectation,
            'state' => self::STATUT_AUTO,
            'adresse' => $affectation->round->address ?? "Fin d'affectation"
        ];

        try {
            $pointage = self::create($pointageData);

            // Marquer l'affectation comme terminée
            $affectation->statut_affectation = 'termine';
            $affectation->save();

            // TODO: Ajouter un message au système de notification

            return $pointage;

        } catch (\Exception $e) {
            \Log::error('Erreur création départ programmé: ' . $e->getMessage());
            return null;
        }
    }

    private static function createAbsenceLog($affectation)
    {
        try {
            // Vérifier si un log d'absence existe déjà
            $existingLog = \DB::table('agent_absence_log')
                ->where('affectation_id', $affectation->id)
                ->first();

            if (!$existingLog) {
                $logData = [
                    'agent_id' => $affectation->agent_id,
                    'affectation_id' => $affectation->id,
                    'round_id' => $affectation->round_id,
                    'date_absence' => $affectation->date_debut_affectation->toDateString(),
                    'type_absence' => 'non_present',
                    'raison' => "Aucun pointage d'arrivée enregistré",
                    'statut' => 'confirme'
                ];

                \DB::table('agent_absence_log')->insert($logData);

                // TODO: Ajouter un message au système de notification
            }

        } catch (\Exception $e) {
            \Log::error('Erreur création log absence: ' . $e->getMessage());
        }
    }

    // Méthode de test
    public static function testAutoPointage()
    {
        try {
            // Créer une position GPS de test
            $agent = Agent::first();
            $round = Round::first();

            if (!$agent || !$round) {
                return ["error" => "Veuillez créer un agent et un round d'abord"];
            }

            // Vérifier les coordonnées du round
            if (!$round->latitude || !$round->longitude) {
                return ["error" => "Le round doit avoir des coordonnées GPS"];
            }

            // Créer une affectation pour aujourd'hui
            $now = Carbon::now();
            $startTime = $now->copy()->setTime(8, 0, 0);
            $endTime = $now->copy()->setTime(18, 0, 0);

            $affectation = Affectation::create([
                'agent_id' => $agent->id,
                'round_id' => $round->id,
                'date_debut_affectation' => $startTime,
                'date_fin_affectation' => $endTime,
                'statut_affectation' => 'en_cours'
            ]);

            // Créer une position GPS dans la zone
            $gpsData = [
                'agent_id' => $agent->id,
                'round_id' => $round->id,
                'latitude' => $round->latitude,
                'longitude' => $round->longitude,
                'accuracy' => 10.0,
                'timestamp' => $now->copy()->setTime(9, 0, 0)
            ];

            $gpsRecord = AgentGPSHistory::create($gpsData);

            // Traiter le pointage
            self::processGpsForAutoPointage($gpsRecord->id);

            // Vérifier le résultat
            $pointages = self::where('affectation_id', $affectation->id)->get();

            return [
                "message" => "Test terminé",
                "affectation" => $affectation->id,
                "gps_position" => "{$gpsRecord->latitude}, {$gpsRecord->longitude}",
                "pointages_crees" => $pointages->count(),
                "pointages" => $pointages->map(function($p) {
                    return [
                        "type" => $p->type_pointage,
                        "heure" => $p->horodatage_server->format('H:i'),
                        "statut" => $p->state
                    ];
                })->toArray()
            ];

        } catch (\Exception $e) {
            return [
                "error" => "Erreur lors du test",
                "message" => $e->getMessage()
            ];
        }
    }

    // Scopes
    public function scopeArrivees($query)
    {
        return $query->where('type_pointage', self::TYPE_ARRIVEE);
    }

    public function scopeDeparts($query)
    {
        return $query->where('type_pointage', self::TYPE_DEPART);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('state', self::STATUT_CONFIRMED);
    }

    public function scopeAuto($query)
    {
        return $query->where('state', self::STATUT_AUTO);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('horodatage_server', Carbon::today());
    }

    public function scopeForAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeForAffectation($query, $affectationId)
    {
        return $query->where('affectation_id', $affectationId);
    }

    // Accesseurs
    public function getTypePointageFormattedAttribute()
    {
        return $this->type_pointage === self::TYPE_ARRIVEE ? 'Arrivée' : 'Départ';
    }

    public function getStateFormattedAttribute()
    {
        $states = [
            self::STATUT_DRAFT => 'Brouillon',
            self::STATUT_AUTO => 'Automatique',
            self::STATUT_CONFIRMED => 'Confirmé',
            self::STATUT_CANCELLED => 'Annulé'
        ];

        return $states[$this->state] ?? $this->state;
    }

    public function getCoordinatesAttribute()
    {
        if ($this->latitude && $this->longitude) {
            return [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude
            ];
        }
        return null;
    }

    // Mutateurs
    public function setHorodatageServerAttribute($value)
    {
        $this->attributes['horodatage_server'] = $value instanceof \DateTime 
            ? $value 
            : Carbon::parse($value);
    }

    public function setHorodatageAppAttribute($value)
    {
        if ($value) {
            $this->attributes['horodatage_app'] = $value instanceof \DateTime 
                ? $value 
                : Carbon::parse($value);
        }
    }

    public function setTypePointageAttribute($value)
    {
        $validTypes = [self::TYPE_ARRIVEE, self::TYPE_DEPART];
        $this->attributes['type_pointage'] = in_array($value, $validTypes) 
            ? $value 
            : self::TYPE_ARRIVEE;
    }

    public function setStateAttribute($value)
    {
        $validStates = [
            self::STATUT_DRAFT, 
            self::STATUT_AUTO, 
            self::STATUT_CONFIRMED, 
            self::STATUT_CANCELLED
        ];
        $this->attributes['state'] = in_array($value, $validStates) 
            ? $value 
            : self::STATUT_AUTO;
    }
}