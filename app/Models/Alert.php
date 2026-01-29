<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Alert extends Model
{
    protected $table = 'agent_alert';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'titre', 'type_alerte', 'agent_id', 'round_id', 'site_id',
        'severite', 'state', 'description', 'date_creation', 'date_resolution'
    ];

    protected $casts = [
        'date_creation' => 'datetime',
        'date_resolution' => 'datetime',
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

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    // Méthodes d'action
    public function resolve()
    {
        if ($this->state == 'active') {
            $this->update([
                'state' => 'resolved',
                'date_resolution' => Carbon::now()
            ]);
            return true;
        }
        return false;
    }

    public function ignore()
    {
        if ($this->state == 'active') {
            $this->update(['state' => 'ignored']);
            return true;
        }
        return false;
    }

    public function reopen()
    {
        if (in_array($this->state, ['resolved', 'ignored'])) {
            $this->update([
                'state' => 'active',
                'date_resolution' => null
            ]);
            return true;
        }
        return false;
    }

    // Méthodes de vérification statiques
    public static function checkMissingCheckins()
    {
        $maintenant = Carbon::now();
        
        $affectationsSansPointage = Affectation::whereIn('statut_affectation', ['planifie', 'en_cours'])
            ->where('date_debut_affectation', '<=', $maintenant)
            ->get();

        foreach ($affectationsSansPointage as $affectation) {
            $pointageArrivee = Pointage::where('agent_id', $affectation->agent_id)
                ->where('type_pointage', 'arrivee')
                ->where('state', 'confirmed')
                ->where('horodatage_server', '>=', $affectation->date_debut_affectation)
                ->first();

            if (!$pointageArrivee) {
                self::createAlerteIfNotExists(
                    $affectation->agent_id,
                    $affectation->round_id,
                    'non_pointage',
                    "Non-pointage d'arrivée - {$affectation->agent->nom_complet}",
                    "L'agent {$affectation->agent->nom_complet} n'a pas pointé à son arrivée pour le round {$affectation->round->libelle}",
                    'critique'
                );
            }
        }
    }

    public static function checkDelayedRounds()
    {
        $maintenant = Carbon::now();
        
        $roundsEnRetard = Round::where('statut', 'en_cours')->get();

        foreach ($roundsEnRetard as $round) {
            $affectationsEnRetard = Affectation::where('round_id', $round->id)
                ->whereIn('statut_affectation', ['planifie', 'en_cours'])
                ->where('date_fin_affectation', '<', $maintenant)
                ->exists();

            if ($affectationsEnRetard) {
                self::createAlerteIfNotExists(
                    null,
                    $round->id,
                    'rondo_en_retard',
                    "Round en retard - {$round->libelle}",
                    "Le round {$round->libelle} est en retard. La date de fin prévue est dépassée.",
                    'warning'
                );
            }
        }
    }

    private static function createAlerteIfNotExists($agentId, $roundId, $typeAlerte, $titre, $description, $severite)
    {
        $exists = self::where('agent_id', $agentId)
            ->where('round_id', $roundId)
            ->where('type_alerte', $typeAlerte)
            ->where('state', 'active')
            ->exists();

        if (!$exists) {
            $round = Round::find($roundId);
            
            self::create([
                'titre' => $titre,
                'type_alerte' => $typeAlerte,
                'agent_id' => $agentId,
                'round_id' => $roundId,
                'site_id' => $round ? $round->site_id : null,
                'severite' => $severite,
                'description' => $description,
                'date_creation' => Carbon::now(),
                'state' => 'active'
            ]);
        }
    }

    // Autres méthodes de vérification peuvent être ajoutées ici...
}