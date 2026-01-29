<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Round extends Model
{
    protected $table = 'agent_tracking_round';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'libelle', 
        'statut', 
        'site_id',        
        'address',
        'latitude', 
        'longitude', 
        'radius_m', 
        'area_m2',
        'geolocation_coords',
        'observations',
        'create_date',
        'write_date',
        'create_uid',
        'write_uid',
        'superviseur_id',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_m' => 'float',
        'area_m2' => 'float',
        'create_date' => 'datetime',
        'write_date' => 'datetime',
        'geolocation_coords' => 'array',
    ];

    // Relation avec le site
    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function superviseur()
    {
        return $this->belongsTo(Superviseur::class, 'superviseur_id');
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class, 'round_id');
    }

    // ⭐⭐ CORRECTION : Méthode validateCoordinates qui manquait
    public function validateCoordinates()
    {
        // Valider latitude
        if ($this->latitude !== null) {
            if (!is_numeric($this->latitude)) {
                throw new \InvalidArgumentException('La latitude doit être un nombre');
            }
            if ($this->latitude < -90 || $this->latitude > 90) {
                throw new \InvalidArgumentException('La latitude doit être entre -90 et 90');
            }
        }
        
        // Valider longitude
        if ($this->longitude !== null) {
            if (!is_numeric($this->longitude)) {
                throw new \InvalidArgumentException('La longitude doit être un nombre');
            }
            if ($this->longitude < -180 || $this->longitude > 180) {
                throw new \InvalidArgumentException('La longitude doit être entre -180 et 180');
            }
        }
        
        // Valider rayon
        if ($this->radius_m !== null) {
            if (!is_numeric($this->radius_m) || $this->radius_m < 0) {
                throw new \InvalidArgumentException('Le rayon doit être un nombre positif');
            }
        }
        
        return true;
    }

    // MUTATOR pour générer automatiquement geolocation_coords
    public function setLatitudeAttribute($value)
    {
        $this->attributes['latitude'] = $value;
        $this->updateGeolocationCoords();
    }

    public function setLongitudeAttribute($value)
    {
        $this->attributes['longitude'] = $value;
        $this->updateGeolocationCoords();
    }

    // MUTATOR pour mettre à jour geolocation_coords si on le définit directement
    public function setGeolocationCoordsAttribute($value)
    {
        if ($value) {
            if (is_array($value)) {
                // Si c'est un tableau, l'encoder en JSON
                $this->attributes['geolocation_coords'] = json_encode($value);
                
                // Extraire latitude et longitude si présentes dans le tableau
                if (isset($value['latitude']) && isset($value['longitude'])) {
                    $this->attributes['latitude'] = $value['latitude'];
                    $this->attributes['longitude'] = $value['longitude'];
                }
            } elseif (is_string($value) && json_decode($value)) {
                // Si c'est déjà un JSON valide
                $this->attributes['geolocation_coords'] = $value;
                
                // Extraire latitude et longitude
                $coords = json_decode($value, true);
                if (isset($coords['latitude']) && isset($coords['longitude'])) {
                    $this->attributes['latitude'] = $coords['latitude'];
                    $this->attributes['longitude'] = $coords['longitude'];
                }
            } else {
                $this->attributes['geolocation_coords'] = $value;
            }
        } else {
            $this->attributes['geolocation_coords'] = null;
        }
    }

    // MUTATOR pour la date de création
    public function setDateCreationAttribute($value)
    {
        if ($value) {
            $this->attributes['create_date'] = Carbon::parse($value);
        }
    }

    // ACCESSOR pour récupérer geolocation_coords sous forme de tableau
    public function getGeolocationCoordsAttribute($value)
    {
        if ($value) {
            if (is_string($value)) {
                return json_decode($value, true);
            }
            return $value;
        }
        
        // Si vide, générer à partir de latitude et longitude
        return $this->generateGeolocationCoords();
    }

    // Méthode pour mettre à jour geolocation_coords
    private function updateGeolocationCoords()
    {
        if (isset($this->attributes['latitude']) && isset($this->attributes['longitude'])) {
            $coords = [
                'latitude' => $this->attributes['latitude'],
                'longitude' => $this->attributes['longitude'],
                'timestamp' => Carbon::now()->toISOString()
            ];
            $this->attributes['geolocation_coords'] = json_encode($coords);
        }
    }

    // Méthode pour générer geolocation_coords
    private function generateGeolocationCoords()
    {
        if ($this->latitude && $this->longitude) {
            return [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'timestamp' => Carbon::now()->toISOString()
            ];
        }
        return null;
    }

    // Calculer l'aire automatiquement
    public function calculateArea()
    {
        if ($this->radius_m) {
            $this->area_m2 = pi() * pow($this->radius_m, 2);
        }
        return $this->area_m2 ?? 0;
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Définir les dates Odoo
            $now = Carbon::now();
            $model->create_date = $now;
            $model->write_date = $now;
            
            // Définir les utilisateurs Odoo (par défaut 1 = admin)
            $model->create_uid = $model->create_uid ?? 1;
            $model->write_uid = $model->write_uid ?? 1;
            
            // Si superviseur_id n'est pas défini, essayer de le trouver
            if (empty($model->superviseur_id)) {
                // Chercher le superviseur du site
                if ($model->site_id) {
                    $site = Site::find($model->site_id);
                    if ($site && $site->superviseur_id) {
                        $model->superviseur_id = $site->superviseur_id;
                    }
                }
            }
            
            // Calculer l'aire si rayon fourni
            if ($model->radius_m) {
                $model->area_m2 = pi() * pow($model->radius_m, 2);
            }
            
            // Générer geolocation_coords si latitude et longitude sont présentes
            if ($model->latitude && $model->longitude) {
                $model->geolocation_coords = [
                    'latitude' => $model->latitude,
                    'longitude' => $model->longitude,
                    'timestamp' => $now->toISOString()
                ];
            }
            
            // Log pour debug
            \Log::info('🔄 CRÉATION RONDE dans boot():');
            \Log::info('   Latitude: ' . ($model->latitude ?? 'NULL'));
            \Log::info('   Longitude: ' . ($model->longitude ?? 'NULL'));
            \Log::info('   Site ID: ' . ($model->site_id ?? 'NULL'));
            \Log::info('   Radius: ' . ($model->radius_m ?? 'NULL'));
        });

        static::created(function ($model) {
            \Log::info('✅ RONDE CRÉÉE avec succès:');
            \Log::info('   ID: ' . $model->id);
            \Log::info('   Latitude: ' . $model->latitude);
            \Log::info('   Longitude: ' . $model->longitude);
            \Log::info('   Site ID: ' . $model->site_id);
        });

        static::updating(function ($model) {
            $model->write_date = Carbon::now();
            
            // Mettre à jour geolocation_coords si latitude ou longitude changent
            if ($model->isDirty(['latitude', 'longitude'])) {
                if ($model->latitude && $model->longitude) {
                    $model->geolocation_coords = [
                        'latitude' => $model->latitude,
                        'longitude' => $model->longitude,
                        'timestamp' => Carbon::now()->toISOString()
                    ];
                }
            }
            
            // Recalculer l'aire si rayon change
            if ($model->isDirty('radius_m') && $model->radius_m) {
                $model->area_m2 = pi() * pow($model->radius_m, 2);
            }
        });
    }
    
    // ⭐⭐ AJOUTEZ CETTE MÉTHODE POUR DEBUG
    public static function logRoundData($data, $message = '')
    {
        \Log::info('📦 ' . $message);
        \Log::info('   Latitude: ' . ($data['latitude'] ?? 'NULL'));
        \Log::info('   Longitude: ' . ($data['longitude'] ?? 'NULL'));
        \Log::info('   Site ID: ' . ($data['site_id'] ?? 'NULL'));
        \Log::info('   Radius: ' . ($data['radius_m'] ?? 'NULL'));
        \Log::info('   Geolocation Coords: ' . (isset($data['geolocation_coords']) ? 'PRÉSENT' : 'ABSENT'));
    }
}