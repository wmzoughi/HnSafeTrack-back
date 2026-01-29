<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class Ronde extends Model
{
    use HasFactory;

    protected $table = 'ronde';
    public $timestamps = false;
     protected $primaryKey = 'id';
    public $incrementing = true;

    protected $fillable = [
        'name',
        'user_id',
        'round_id',
        'site_id',
        'description',
        'date_debut',
        'date_fin',
        'statut',
        'nom_fichier',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'photos_ids' => 'array',
    ];

    // Constantes pour les statuts
    const STATUT_PLANIFIEE = 'planifiee';
    const STATUT_EN_COURS = 'en_cours';
    const STATUT_TERMINEE = 'terminee';
    const STATUT_ANNULEE = 'annulee';

    protected static function boot()
    {
        parent::boot();

        // Valeur par défaut pour user_id
        static::creating(function ($model) {
            if (empty($model->user_id)) {
                $model->user_id = Auth::id();
            }
        });

        // Validation des dates
        static::saving(function ($model) {
            if ($model->date_debut && $model->date_fin) {
                if ($model->date_fin <= $model->date_debut) {
                    throw new \Exception("La date de fin doit être après la date de début !");
                }
            }
        });

        // Mise à jour automatique du site_id lors de la modification du round_id
        static::updating(function ($model) {
            if ($model->isDirty('round_id') && $model->round_id) {
                $round = AgentTrackingRound::find($model->round_id);
                if ($round && $round->site_id) {
                    $model->site_id = $round->site_id;
                }
            }
        });
    }

    /**
     * Relations
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function round()
    {
        return $this->belongsTo(AgentTrackingRound::class, 'round_id');
    }

    public function site()
    {
        return $this->belongsTo(AgentTrackingSite::class, 'site_id');
    }

    public function photos()
    {
        return $this->hasMany(RondePhoto::class, 'ronde_id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'ronde_id')->orderBy('created_at', 'desc');
    }

    public function messages()
    {
        return $this->hasMany(RondeMessage::class, 'ronde_id')->orderBy('created_at', 'desc');
    }

    /**
     * Accesseurs
     */
    public function getDureeAttribute()
    {
        if ($this->date_debut && $this->date_fin) {
            $start = Carbon::parse($this->date_debut);
            $end = Carbon::parse($this->date_fin);
            
            $hours = $end->diffInHours($start);
            $minutes = $end->diffInMinutes($start);
            
            if ($hours < 1) {
                return $minutes . " min";
            } else {
                $decimalHours = $hours + ($minutes % 60) / 60;
                return number_format($decimalHours, 1) . " h";
            }
        }
        
        return "N/A";
    }

    public function getStatutLabelAttribute()
    {
        $statuts = [
            self::STATUT_PLANIFIEE => 'Planifiée',
            self::STATUT_EN_COURS => 'En cours',
            self::STATUT_TERMINEE => 'Terminée',
            self::STATUT_ANNULEE => 'Annulée'
        ];
        
        return $statuts[$this->statut] ?? $this->statut;
    }

    /**
     * Méthodes d'action
     */
    public function demarrer()
    {
        if ($this->statut === self::STATUT_PLANIFIEE) {
            $this->statut = self::STATUT_EN_COURS;
            $this->save();
            
            // Ajouter un message d'activité
            $this->ajouterMessage(
                "Démarrage de la ronde",
                "Ronde démarrée par " . Auth::user()->name
            );
            
            return true;
        }
        
        return false;
    }

    public function terminer()
    {
        if ($this->statut === self::STATUT_EN_COURS) {
            $this->statut = self::STATUT_TERMINEE;
            $this->save();
            
            $this->ajouterMessage(
                "Fin de la ronde",
                "Ronde terminée par " . Auth::user()->name
            );
            
            return true;
        }
        
        return false;
    }

    public function annuler()
    {
        if (in_array($this->statut, [self::STATUT_PLANIFIEE, self::STATUT_EN_COURS])) {
            $this->statut = self::STATUT_ANNULEE;
            $this->save();
            
            $this->ajouterMessage(
                "Annulation de la ronde",
                "Ronde annulée par " . Auth::user()->name
            );
            
            return true;
        }
        
        return false;
    }

    /**
     * Méthode utilitaire pour ajouter des messages
     */
    private function ajouterMessage($subject, $body)
    {
        return $this->messages()->create([
            'user_id' => Auth::id(),
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    /**
     * Scopes
     */
    public function scopePlanifiee($query)
    {
        return $query->where('statut', self::STATUT_PLANIFIEE);
    }

    public function scopeEnCours($query)
    {
        return $query->where('statut', self::STATUT_EN_COURS);
    }

    public function scopeTerminee($query)
    {
        return $query->where('statut', self::STATUT_TERMINEE);
    }

    public function scopeParUtilisateur($query, $userId = null)
    {
        $userId = $userId ?? Auth::id();
        return $query->where('user_id', $userId);
    }

    public function scopeEntreDates($query, $debut, $fin)
    {
        return $query->whereBetween('date_debut', [$debut, $fin]);
    }
}