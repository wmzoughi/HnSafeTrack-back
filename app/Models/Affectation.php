<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Affectation extends Model
{
    protected $table = 'agent_tracking_affectation';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'round_id',
        'agent_id',
        'affectation_count',
        'date_debut_affectation',
        'date_fin_affectation',
        'statut_affectation',
        'observations',
        'display_name',
    ];

    protected $casts = [
        'date_debut_affectation' => 'datetime',
        'date_fin_affectation'   => 'datetime',
    ];

    /* =======================
     |  RELATIONS
     ======================= */
    public function round()
    {
        return $this->belongsTo(Round::class, 'round_id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    /* =======================
     |  LOGIQUE STATUT (CENTRALE)
     ======================= */

    /**
     * Met à jour automatiquement le statut_affectation
     * selon la date et l'heure actuelles.
     */
    public function refreshStatut(): void
    {
        // Si annulé → ne jamais modifier
        if ($this->statut_affectation === 'annule') {
            return;
        }

        $now = Carbon::now();

        if ($now->lt($this->date_debut_affectation)) {
            $newStatut = 'planifie';
        } elseif ($now->between(
            $this->date_debut_affectation,
            $this->date_fin_affectation
        )) {
            $newStatut = 'en_cours';
        } else {
            $newStatut = 'termine';
        }

        if ($this->statut_affectation !== $newStatut) {
            $this->statut_affectation = $newStatut;
            $this->saveQuietly();
        }
    }

    /* =======================
     |  ACTIONS MANUELLES
     ======================= */

    public function demarrer(): bool
    {
        if (
            $this->statut_affectation === 'planifie' &&
            Carbon::now()->between(
                $this->date_debut_affectation,
                $this->date_fin_affectation
            )
        ) {
            $this->update(['statut_affectation' => 'en_cours']);
            return true;
        }

        return false;
    }

    public function terminer(): bool
    {
        if (
            $this->statut_affectation === 'en_cours' &&
            Carbon::now()->gt($this->date_fin_affectation)
        ) {
            $this->update(['statut_affectation' => 'termine']);
            return true;
        }

        return false;
    }

    public function annuler(): bool
    {
        if (in_array($this->statut_affectation, ['planifie', 'en_cours'])) {
            $this->update(['statut_affectation' => 'annule']);
            return true;
        }

        return false;
    }

    public function planifier(): bool
    {
        if ($this->statut_affectation === 'annule') {
            $this->update(['statut_affectation' => 'planifie']);
            return true;
        }

        return false;
    }

    /* =======================
     |  VALIDATIONS
     ======================= */

    public static function validateDates($dateDebut, $dateFin): void
    {
        if ($dateFin && $dateDebut > $dateFin) {
            throw new \Exception(
                "La date de début d'affectation ne peut pas être après la date de fin"
            );
        }
    }

    public static function checkAgentDisponibilite(
        $agentId,
        $dateDebut,
        $dateFin,
        $excludeId = null
    ): bool {
        $query = self::where('agent_id', $agentId)
            ->whereIn('statut_affectation', ['planifie', 'en_cours'])
            ->where(function ($q) use ($dateDebut, $dateFin) {
                $q->whereBetween('date_debut_affectation', [$dateDebut, $dateFin])
                  ->orWhereBetween('date_fin_affectation', [$dateDebut, $dateFin]);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /* =======================
     |  BOOT
     ======================= */

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if (
                empty($model->display_name) &&
                $model->round &&
                $model->agent
            ) {
                $dateStr = $model->date_debut_affectation
                    ? $model->date_debut_affectation->format('d/m/Y H:i')
                    : '';

                $model->display_name =
                    "{$model->agent->nom_complet} - {$model->round->libelle} - {$dateStr}";
            }
        });
    }
}
