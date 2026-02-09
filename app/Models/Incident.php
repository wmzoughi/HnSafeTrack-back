<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'agent_incident';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'name', 'titre', 'description', 'agent_id', 'round_id', 'site_id',
        'latitude', 'longitude', 'address', 'type_incident', 'priorite',
        'statut', 'photo', 'photo_filename', 'commentaires', 'historique',
        'date_incident', 'date_resolution',
        'localisation', 'pieces_jointes', 'commentaires_supplementaires',
    ];

    protected $casts = [
        'date_incident' => 'datetime',
        'date_resolution' => 'datetime',
        'create_date' => 'datetime',
        'write_date' => 'datetime',
        'photo' => 'array', // Si vous stockez les photos en base64
        'round_id' => 'integer',
        'site_id' => 'integer',
        'agent_id' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
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

    // Méthodes d'action compatibles avec Odoo
    public function marquerResolu()
    {
        if (in_array($this->statut, ['nouveau', 'en_cours'])) {
            $this->update([
                'statut' => 'resolu',
                'date_resolution' => now(),
                'write_date' => now() // Mettre à jour write_date comme Odoo
            ]);
            $this->ajouterHistorique("L'incident a été marqué comme résolu.");
            return true;
        }
        return false;
    }

    public function reouvrir()
    {
        if ($this->statut == 'resolu') {
            $this->update([
                'statut' => 'en_cours',
                'date_resolution' => null,
                'write_date' => now()
            ]);
            $this->ajouterHistorique("L'incident a été réouvert.");
            return true;
        }
        return false;
    }

    public function fermer()
    {
        if ($this->statut != 'ferme') {
            $this->update([
                'statut' => 'ferme',
                'write_date' => now()
            ]);
            $this->ajouterHistorique("L'incident a été fermé.");
            return true;
        }
        return false;
    }

    public function ajouterHistorique($message)
    {
        $historiqueActuel = $this->historique ?? "";
        $nouvelleEntree = "\n[" . now() . "] " . $message;
        $this->update([
            'historique' => $historiqueActuel . $nouvelleEntree,
            'write_date' => now()
        ]);
    }

    public function ajouterCommentaire($commentaire, $estInterne = false)
    {
        if ($commentaire) {
            $prefixe = $estInterne ? "[INTERNE] " : "";
            $timestamp = now();
            $commentairesActuels = $this->commentaires ?? "";
            $nouveauCommentaire = "\n[{$timestamp}] {$prefixe}{$commentaire}";
            
            $this->update([
                'commentaires' => $commentairesActuels . $nouveauCommentaire,
                'write_date' => now()
            ]);
            
            return true;
        }
        return false;
    }

    // ⭐⭐ MÉTHODE POUR CRÉATION DEPUIS MOBILE - CORRIGÉE
    public static function createFromMobile($data)
    {
        try {
            // Préparer les valeurs pour correspondre à Odoo
            $incidentValues = [
                'titre' => $data['titre'] ?? 'Incident sans titre',
                'description' => $data['description'] ?? '',
                // ⭐⭐ CORRECTION: Utiliser self:: au lieu de $this->
                'type_incident' => self::mapToOdooType($data['type_incident'] ?? 'autre'),
                'priorite' => self::mapToOdooPriorite($data['priorite'] ?? 'moyenne'),
                'agent_id' => $data['agent_id'],
                // ⭐ IMPORTANT: Ne pas inclure les champs s'ils sont null
                'round_id' => isset($data['round_id']) && $data['round_id'] != 0 ? $data['round_id'] : null,
                'site_id' => isset($data['site_id']) && $data['site_id'] != 0 ? $data['site_id'] : null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'address' => $data['address'] ?? '',
                'localisation' => $data['localisation'] ?? null,
                'photo' => $data['photo'] ?? null,
                'photo_filename' => $data['photo_filename'] ?? 'photo_incident.jpg',
                'pieces_jointes' => $data['pieces_jointes'] ?? null,
                'date_incident' => now(),
                'statut' => 'nouveau',
                'create_date' => now(),
                'write_date' => now(),
            ];

            // Nettoyer les valeurs null pour ne pas les envoyer
            $incidentValues = array_filter($incidentValues, function($value) {
                return $value !== null && $value !== '';
            });

            // Créer l'incident
            $incident = self::create($incidentValues);

            // Ajouter commentaire initial si fourni
            if (isset($data['commentaire_initial'])) {
                $incident->ajouterCommentaireInitial($data['commentaire_initial']);
            }

            // Générer la référence Odoo
            if (empty($incident->name)) {
                $incident->update(['name' => self::generateOdooReference($incident->id)]);
            }

            return [
                'success' => true,
                'incident_id' => $incident->id,
                'reference' => $incident->name,
                'message' => "Incident {$incident->name} créé avec succès"
            ];

        } catch (\Exception $e) {
            \Log::error('Erreur création incident mobile: ' . $e->getMessage(), ['data' => $data]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => "Erreur lors de la création de l'incident"
            ];
        }
    }

    // ⭐⭐ CORRECTION: RENDRE CES MÉTHODES STATIQUES
    private static function mapToOdooType($type)
    {
        $mapping = [
            'anomalie' => 'autre',
            'incident' => 'securite',
            'alerte_securite' => 'securite',
            'probleme_materiel' => 'materiel',
            'autre' => 'autre'
        ];
        
        return $mapping[$type] ?? 'autre';
    }

    // ⭐⭐ CORRECTION: RENDRE CES MÉTHODES STATIQUES
    private static function mapToOdooPriorite($priorite)
    {
        $mapping = [
            'basse' => 'basse',
            'moyenne' => 'moyenne',
            'haute' => 'haute',
            'critique' => 'critique'
        ];
        
        return $mapping[$priorite] ?? 'moyenne';
    }

    private function ajouterCommentaireInitial($commentaire)
    {
        $commentairesActuels = $this->commentaires ?? "";
        $nouveauCommentaire = "\n--- Commentaire initial ---\n{$commentaire}\n";
        $this->update([
            'commentaires' => $commentairesActuels . $nouveauCommentaire,
            'write_date' => now()
        ]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->name)) {
                $model->name = self::generateOdooReference();
            }
            if (empty($model->type_incident)) {
                $model->type_incident = 'autre';
            }
            if (empty($model->statut)) {
                $model->statut = 'nouveau';
            }
            if (empty($model->priorite)) {
                $model->priorite = 'moyenne';
            }
            // ⭐ CHANGER: NE PAS FORCER À 0 - LAISSER NULL SI NULL
            if (empty($model->round_id)) {
                $model->round_id = null; // Laissez null pour éviter l'erreur FK
            }
            if (empty($model->site_id)) {
                $model->site_id = null; // Laissez null pour éviter l'erreur FK
            }
            // Timestamps Odoo
            $model->create_date = now();
            $model->write_date = now();
        });
    }

    
    // Générer référence Odoo style
    private static function generateOdooReference($id = null)
    {
        if ($id) {
            return 'INC' . str_pad($id, 3, '0', STR_PAD_LEFT);
        }
        
        $last = self::orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->name, 3)) + 1 : 1;
        return 'INC' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}