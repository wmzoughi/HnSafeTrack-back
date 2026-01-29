<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Affectation;

class RefreshAffectationStatut extends Command
{
    protected $signature = 'affectations:refresh-statut';
    protected $description = 'Met à jour automatiquement le statut des affectations selon le temps';

    public function handle()
    {
        Affectation::whereIn('statut_affectation', ['planifie', 'en_cours'])
            ->chunk(200, function ($affectations) {
                foreach ($affectations as $affectation) {
                    $affectation->refreshStatut();
                }
            });

        $this->info('Statuts des affectations mis à jour avec succès.');

        return Command::SUCCESS;
    }
}
