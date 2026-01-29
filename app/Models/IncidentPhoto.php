<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentPhoto extends Model
{
    use HasFactory;

    protected $table = 'agent_incident_photo';
    public $timestamps = false;

    protected $fillable = [
        'incident_id',
        'image',
        'filename',
        'description',
        'date_prise_vue',
        'create_date',
        'write_date',
        'create_uid',
        'write_uid'
    ];

    protected $casts = [
        'date_prise_vue' => 'datetime',
        'create_date' => 'datetime',
        'write_date' => 'datetime'
    ];

    // Relations
    public function incident()
    {
        return $this->belongsTo(Incident::class, 'incident_id');
    }

    // Boot method pour nettoyer et valider les données
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($photo) {
            // Nettoyer l'image base64
            if (!empty($photo->image)) {
                $photo->image = self::cleanBase64($photo->image);
            }
            
            // Générer un nom de fichier si non fourni
            if (empty($photo->filename) && !empty($photo->image)) {
                $photo->filename = 'incident_photo_' . time() . '_' . Str::random(6) . '.jpg';
            }
            
            // Définir les dates Odoo
            $now = now()->toDateTimeString();
            $photo->create_date = $now;
            $photo->write_date = $now;
            
            // Définir les IDs utilisateurs
            $photo->create_uid = $photo->create_uid ?? 1;
            $photo->write_uid = $photo->write_uid ?? 1;
            
            // Date de prise de vue par défaut
            if (empty($photo->date_prise_vue)) {
                $photo->date_prise_vue = $now;
            }
        });
        
        static::updating(function ($photo) {
            // Nettoyer l'image base64 lors de la mise à jour
            if (!empty($photo->image)) {
                $photo->image = self::cleanBase64($photo->image);
            }
            
            // Mettre à jour la date de modification
            $photo->write_date = now()->toDateTimeString();
        });
    }

    // Méthode pour nettoyer le base64
    private static function cleanBase64($base64String)
    {
        if (empty($base64String)) {
            return null;
        }

        // Si c'est un data URL, extraire le base64
        if (str_starts_with($base64String, 'data:image')) {
            $parts = explode(',', $base64String);
            if (count($parts) > 1) {
                $base64String = $parts[1];
            }
        }

        // Nettoyer les caractères non-base64
        $base64String = preg_replace('/[^A-Za-z0-9\+\/\=]/', '', $base64String);

        // Vérifier la validité du base64
        if (base64_decode($base64String, true) === false) {
            throw new \Exception('Base64 invalide pour la photo d\'incident');
        }

        return $base64String;
    }

    // Accesseur pour l'image
    public function setImageAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['image'] = null;
            return;
        }

        // Stocker directement le base64
        $this->attributes['image'] = $value;
        
        // Log pour debug
        \Log::info('Photo d\'incident stockée', [
            'incident_id' => $this->incident_id ?? 'new',
            'base64_length' => strlen($value),
            'filename' => $this->filename ?? 'unknown'
        ]);
    }

    public function getImageAttribute($value)
    {
        return $value;
    }

    // Méthode pour créer une photo depuis mobile
    public static function createFromMobile($data)
    {
        try {
            \Log::info('📷 Création photo incident depuis mobile', [
                'incident_id' => $data['incident_id'] ?? 'N/A',
                'has_image' => isset($data['image']) && !empty($data['image']),
                'image_size' => isset($data['image']) ? strlen($data['image']) : 0
            ]);

            $photoValues = [
                'incident_id' => $data['incident_id'] ?? null,
                'image' => $data['image'] ?? null,
                'description' => $data['description'] ?? '',
                'date_prise_vue' => $data['date_prise_vue'] ?? now(),
                'filename' => $data['filename'] ?? 'incident_photo_' . time() . '.jpg',
                'create_uid' => $data['create_uid'] ?? 1,
                'write_uid' => $data['write_uid'] ?? 1,
            ];

            // Nettoyer les valeurs null
            $photoValues = array_filter($photoValues, function($value) {
                return $value !== null;
            });

            // Créer la photo
            $photo = self::create($photoValues);

            \Log::info('✅ Photo d\'incident créée', [
                'photo_id' => $photo->id,
                'incident_id' => $photo->incident_id,
                'filename' => $photo->filename
            ]);

            return [
                'success' => true,
                'photo_id' => $photo->id,
                'message' => 'Photo d\'incident créée avec succès',
                'data' => $photo
            ];

        } catch (\Exception $e) {
            \Log::error('❌ Erreur création photo incident mobile: ' . $e->getMessage(), ['data' => $data]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Erreur lors de la création de la photo'
            ];
        }
    }

    // Méthode pour obtenir l'URL de l'image
    public function getImageUrl()
    {
        if ($this->image) {
            // Générer une URL pour l'API
            return url('/api/v1/incident-photo/' . $this->id . '/image');
        }
        
        return null;
    }

    // Méthode pour obtenir les informations de base
    public function toApiResponse()
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incident_id,
            'filename' => $this->filename,
            'description' => $this->description,
            'date_prise_vue' => $this->date_prise_vue,
            'image_url' => $this->getImageUrl(),
            'has_image' => !empty($this->image),
            'create_date' => $this->create_date,
            'write_date' => $this->write_date
        ];
    }
}