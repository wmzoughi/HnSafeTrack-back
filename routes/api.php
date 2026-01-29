<?php
// routes/api.php - VERSION CORRIGÉE
use App\Http\Controllers\Api\ApiController;
use Illuminate\Support\Facades\Route;

// Version API
Route::prefix('v1')->group(function () {
    
    // ==================== ROUTES PUBLIQUES ====================
    Route::get('/hello', [ApiController::class, 'hello']);
    Route::post('/login', [ApiController::class, 'login']);
    Route::post('/signup', [ApiController::class, 'signupPublic']);
    Route::get('/health', [ApiController::class, 'health']);

     // 🔓 NOUVELLES ROUTES PUBLIQUES POUR LES UTILISATEURS
    Route::get('/users/public', [ApiController::class, 'getUsersPublic']);
    Route::get('/users/find-by-login', [ApiController::class, 'findUserByLogin']);

    Route::get('/debug/users', function() {
        try {
            $users = DB::table('res_users')
                      ->where('active', true)
                      ->select('id', 'login', 'password', 'partner_id')
                      ->get();

            $userDetails = [];
            foreach ($users as $user) {
                $partner = DB::table('res_partner')
                            ->where('id', $user->partner_id)
                            ->select('name', 'email')
                            ->first();
                
                $userDetails[] = [
                    'id' => $user->id,
                    'login' => $user->login,
                    'password' => $user->password, // Pour voir le vrai mot de passe
                    'partner_name' => $partner->name ?? 'N/A',
                    'partner_email' => $partner->email ?? 'N/A'
                ];
            }

            return response()->json([
                'success' => true,
                'users' => $userDetails
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    });

    Route::get('/debug/tables', function() {
        try {
            $tables = DB::select("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND (table_name LIKE '%user%' 
                OR table_name LIKE '%partner%'
                OR table_name LIKE '%agent%'
                OR table_name LIKE '%superviseur%')
                ORDER BY table_name
            ");
            
            return response()->json([
                'success' => true,
                'tables' => $tables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    });
    
    // ==================== ROUTES PROTÉGÉES ====================
    // RETIRER le middleware auth:sanctum - Votre contrôleur gère l'auth
    Route::post('/model/read', [ApiController::class, 'modelRead']);
    Route::post('/model/create', [ApiController::class, 'modelCreate']);
    Route::post('/model/update', [ApiController::class, 'modelUpdate']);
    Route::post('/model/delete', [ApiController::class, 'modelDelete']);
    Route::post('/logout', [ApiController::class, 'logout']);
    Route::get('/stats', [ApiController::class, 'stats']);
    Route::get('/verify-token', [ApiController::class, 'verifyToken']);

    

});