<?php

namespace App\Helpers;

use Carbon\Carbon;

class TimezoneHelper
{
    /**
     * CORRECTION -1h POUR CASABLANCA (UTC+1)
     * Convertit l'heure locale reçue de Flutter en UTC -1h
     */
    public static function adjustCasablancaToUTC($dateString): string
    {
        try {
            $date = Carbon::parse($dateString);
            
            // ⭐⭐ CRITIQUE : Soustraire 1 heure (Casablanca UTC+1 → UTC)
            $date = $date->subHour(); // -1h
            
            // Forcer en UTC
            $date = $date->setTimezone('UTC');
            
            return $date->toDateTimeString();
            
        } catch (\Exception $e) {
            \Log::error("❌ Erreur ajustement fuseau: " . $e->getMessage());
            return Carbon::now()->setTimezone('UTC')->toDateTimeString();
        }
    }
    
    /**
     * Ajuste plusieurs champs de date dans un tableau
     */
    public static function adjustDates(array &$data, array $dateFields): void
    {
        foreach ($dateFields as $field) {
            if (isset($data[$field]) && !empty(trim($data[$field]))) {
                \Log::info("🕐 Ajustement -1h pour $field: " . $data[$field]);
                $data[$field] = self::adjustCasablancaToUTC($data[$field]);
                \Log::info("   → Résultat: " . $data[$field]);
            }
        }
    }
}