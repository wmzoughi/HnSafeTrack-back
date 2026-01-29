<?php
// app/Console/Commands/ProcessScheduledDepartures.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Pointage;
use Illuminate\Support\Facades\Log;

class ProcessScheduledDepartures extends Command
{
    protected $signature = 'pointage:process-departures';
    protected $description = 'Traite les départs programmés à la fin des affectations';

    public function handle()
    {
        Log::info('🔄 Début du traitement des départs programmés');
        
        try {
            $result = Pointage::processScheduledDepartures();
            
            if ($result) {
                Log::info('✅ Traitement des départs programmés terminé');
                $this->info('Traitement des départs programmés terminé avec succès');
            } else {
                Log::warning('⚠️ Traitement des départs retourné false');
                $this->warn('Traitement retourné false');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            Log::error('❌ Erreur traitement départs programmés: ' . $e->getMessage());
            $this->error('Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}