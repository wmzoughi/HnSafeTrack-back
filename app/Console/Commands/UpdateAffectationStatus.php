<?php
// app/Console/Commands/UpdateAffectationStatus.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateAffectationStatus extends Command
{
    protected $signature = 'affectation:update-auto';
    protected $description = 'Déclenche la fonction PostgreSQL pour mettre à jour les statuts';

    public function handle()
    {
        try {
            $this->info('🔄 Exécution de la mise à jour automatique des statuts...');
            
            // Appeler la fonction PostgreSQL
            DB::statement("SELECT update_affectation_status_auto()");
            
            // Compter les affectations par statut
            $stats = DB::table('agent_tracking_affectation')
                ->select('statut_affectation', DB::raw('COUNT(*) as count'))
                ->groupBy('statut_affectation')
                ->get()
                ->pluck('count', 'statut_affectation')
                ->toArray();
            
            $this->info('📊 Statistiques: ' . json_encode($stats));
            
            \Log::info('✅ Mise à jour automatique des statuts exécutée', $stats);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Erreur: ' . $e->getMessage());
            \Log::error('Erreur mise à jour automatique statuts: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}