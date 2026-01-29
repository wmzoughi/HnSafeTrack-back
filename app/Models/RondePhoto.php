<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RondePhoto extends Model
{
    use HasFactory;

    protected $table = 'ronde_photo';
    public $timestamps = false;
    protected $primaryKey = 'id';
    public $incrementing = true;

    protected $fillable = [
        'ronde_id',
        'image',        // Stockage binary pour Odoo
        'filename',
        'description',
        'create_date',
        'write_date',
    ];

    protected $casts = [
        'create_date' => 'datetime',
        'write_date' => 'datetime',
    ];

    /**
     * Relation avec le modèle Ronde.
     */
    public function ronde()
    {
        return $this->belongsTo(Ronde::class, 'ronde_id');
    }

    /**
     * Accesseur pour l'image - CONVERTIR EN BASE64 POUR FLUTTER
     */
    public function getImageAttribute($value)
    {
        // Si vide, retourner null
        if (empty($value)) {
            return null;
        }

        // Si c'est déjà du base64 (stocké comme string), le retourner
        if (is_string($value)) {
            // Vérifier si c'est du base64 valide
            if (base64_decode($value, true) !== false) {
                return $value;
            }
            
            // Si ce n'est pas du base64, c'est peut-être du binary
            // Essayons de l'encoder en base64
            return base64_encode($value);
        }
        
        // Pour tout autre type, encoder en base64
        return base64_encode((string)$value);
    }

    /**
     * Mutateur pour l'image - STOCKER EN BINARY POUR ODOO
     */
    public function setImageAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['image'] = null;
            return;
        }

        // Si c'est du base64, décoder et stocker en binary
        if (is_string($value)) {
            // ⭐ CORRECTION CRITIQUE: Vérifier et nettoyer le base64
            $value = trim($value);
            
            // Vérifier si c'est un data URL (commence par "data:image")
            if (strpos($value, 'data:image') === 0) {
                // Extraire le base64
                $parts = explode(',', $value);
                if (count($parts) > 1) {
                    $value = $parts[1];
                }
            }
            
            // ⭐ NOUVEAU: Supprimer tous les caractères non-base64
            // Base64 valide contient seulement: A-Z, a-z, 0-9, +, /, =
            $value = preg_replace('/[^A-Za-z0-9\+\/\=]/', '', $value);
            
            // Ajouter le padding si nécessaire (longueur multiple de 4)
            $padding = strlen($value) % 4;
            if ($padding > 0) {
                $value .= str_repeat('=', 4 - $padding);
            }
            
            try {
                // Décoder le base64 en binary
                $decoded = base64_decode($value, true);
                
                if ($decoded === false) {
                    // Log l'erreur mais stocke quand même pour debug
                    \Log::error('Base64 decoding failed for RondePhoto');
                    $this->attributes['image'] = null;
                    return;
                }
                
                // ⭐ CRITICAL FIX: Convertir le binary en base64 pour éviter les problèmes UTF-8
                // Stocker ENCORE EN BASE64 dans la base de données pour éviter les problèmes d'encodage
                $this->attributes['image'] = $value; // Stocker le base64 propre
                
            } catch (\Exception $e) {
                // En cas d'erreur, stocker null et log
                \Log::error('Error processing image for RondePhoto: ' . $e->getMessage());
                $this->attributes['image'] = null;
            }
        } else {
            // Si c'est déjà binary, encoder en base64 pour éviter les problèmes UTF-8
            try {
                $this->attributes['image'] = base64_encode($value);
            } catch (\Exception $e) {
                $this->attributes['image'] = null;
            }
        }
    }

    /**
     * Accesseur spécial pour Odoo - retourner le binary directement
     */
    public function getImageForOdooAttribute()
    {
        return $this->attributes['image'] ?? null;
    }

    /**
     * Boot method pour définir des comportements par défaut.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->create_date)) {
                $model->create_date = now();
            }
            if (empty($model->write_date)) {
                $model->write_date = now();
            }
        });

        static::updating(function ($model) {
            $model->write_date = now();
        });
    }
}