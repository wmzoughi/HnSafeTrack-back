<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visiteur extends Model
{
    use HasFactory;

    protected $table = 'visiteur';
    public $timestamps = false;
    
    protected $fillable = [
        'ref',
        'name',
        'prenom',
        'cin',
        'date_debut_visite',
        'date_fin_visite',
        'type_visiteur_id',
        'user_id',
        'round_id',
        'site_id',
        'description',
        'active'
    ];

    protected $casts = [
        'date_debut_visite' => 'datetime',
        'date_fin_visite' => 'datetime',
        'active' => 'boolean'
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function typeVisiteur()
    {
        return $this->belongsTo(TypeVisiteur::class, 'type_visiteur_id');
    }

    public function round()
    {
        return $this->belongsTo(Round::class, 'round_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function photos()
    {
        return $this->hasMany(VisiteurPhoto::class, 'visiteur_id');
    }

    // Accessors & Mutators
    public function getNomCompletAttribute()
    {
        return trim($this->prenom . ' ' . $this->name);
    }

    // Scopes
    public function scopeActifs($query)
    {
        return $query->where('active', true);
    }

    public function scopeVisiteAujourdhui($query)
    {
        return $query->whereDate('date_debut_visite', today());
    }

    public function scopeByRound($query, $roundId)
    {
        return $query->where('round_id', $roundId);
    }

    public function scopeBySite($query, $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    // Validation
    public static function validateDates($dateDebut, $dateFin)
    {
        if ($dateDebut && $dateFin) {
            if ($dateFin < $dateDebut) {
                throw new \Exception("La date de fin ne peut pas être avant la date de début !");
            }
        }
    }

    // Génération de référence - MODIFIÉE
    public static function generateRef()
    {
        $lastVisiteur = self::orderBy('id', 'desc')->first();
        $lastNumber = $lastVisiteur ? intval(substr($lastVisiteur->ref, 3)) : 0;
        $nextNumber = $lastNumber + 1;
        
        return 'VIS' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT); // 5 chiffres au lieu de 3
    }

    // Méthode pour créer un visiteur avec photos
    public static function createWithPhotos(array $visiteurData, array $photos = [])
    {
        \DB::beginTransaction();
        
        try {
            // Créer le visiteur
            $visiteur = self::create($visiteurData);
            
            // Créer les photos
            if (!empty($photos)) {
                foreach ($photos as $photoData) {
                    $visiteur->photos()->create($photoData);
                }
            }
            
            \DB::commit();
            return $visiteur;
            
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($visiteur) {
            if (empty($visiteur->ref)) {
                $visiteur->ref = self::generateRef();
            }
        });
    }
}