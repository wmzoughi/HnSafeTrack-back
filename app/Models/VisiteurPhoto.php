<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisiteurPhoto extends Model
{
    use HasFactory;

    protected $table = 'visiteur_photo';
    public $timestamps = false;

    protected $fillable = [
        'visiteur_id',
        'image',
        'description',
        'date_prise'
    ];

    protected $casts = [
        'date_prise' => 'datetime'
    ];

    // Relations
    public function visiteur()
    {
        return $this->belongsTo(Visiteur::class, 'visiteur_id');
    }

    // Boot method pour générer le nom de fichier
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($photo) {
            
            // Nettoyer l'image base64
            if (!empty($photo->image)) {
                $photo->image = self::cleanBase64($photo->image);
            }
        });
        
        static::updating(function ($photo) {
            // Nettoyer l'image base64 lors de la mise à jour aussi
            if (!empty($photo->image)) {
                $photo->image = self::cleanBase64($photo->image);
            }
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
            throw new \Exception('Base64 invalide');
        }

        return $base64String;
    }

    // Dans setImageAttribute()
    public function setImageAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['image'] = null;
            return;
        }

        // ⭐⭐ STOCKER DIRECTEMENT LE BASE64 RECU
        // Laravel/PHP va gérer l'échappement automatique
        $this->attributes['image'] = $value;
        
        // Log simple pour debug
        \Log::info('Photo stockée', [
            'id' => $this->id ?? 'new',
            'base64_length' => strlen($value),
            'base64_start' => substr($value, 0, 30)
        ]);
    }

    public function getImageAttribute($value)
    {
        // ⭐⭐ RETOURNER DIRECTEMENT
        return $value;
    }
}