<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Agent;
use App\Models\Superviseur;
use App\Models\Site;
use App\Models\Stock;
use App\Models\Affectation;
use App\Models\Round;
use App\Models\Incident;
use App\Models\Alert;
use App\Models\Pointage;
use App\Models\Ronde;
use App\Models\RondePhoto;
use App\Models\AgentGPSHistory;
use App\Models\Visiteur;
use App\Models\VisiteurPhoto;
use App\Models\TypeVisiteur;
use App\Services\OdooService;
use App\Helpers\TimezoneHelper;
use App\Models\IncidentPhoto;

class ApiController extends Controller
{
    private $tokenExpiration = 24 * 60 * 60; // 24 heures

    /**
     * Authentifier l'utilisateur par token
     */
    private function authenticateToken(Request $request)
    {
        $token = $request->bearerToken();
        
        if (!$token) {
         
            return null;
        }



        // Vérifier le token dans le cache Laravel
        $cachedToken = Cache::get("api_token:{$token}");
        
        if (!$cachedToken) {
          
            return null;
        }

        // Vérifier si le token n'est pas expiré
        if (isset($cachedToken['expires_at'])) {
            $expiresAt = Carbon::parse($cachedToken['expires_at']);
            if (Carbon::now()->gt($expiresAt)) {
                \Log::warning('Token expiré', [
                    'expires_at' => $cachedToken['expires_at'],
                    'now' => Carbon::now()->toISOString()
                ]);
                Cache::forget("api_token:{$token}");
                return null;
            }
        }

        // Récupérer l'utilisateur depuis la base de données
        $user = \DB::table('res_users')
                ->where('id', $cachedToken['user_id'])
                ->where('active', true)
                ->select('id', 'login', 'partner_id')
                ->first();

        if (!$user) {
            Cache::forget("api_token:{$token}");
            return null;
        }

        // Récupérer les informations du partenaire
        $partner = \DB::table('res_partner')
                    ->where('id', $user->partner_id)
                    ->select('id', 'name', 'email')
                    ->first();

        if (!$partner) {
            return null;
        }

        
        return (object)[
            'id' => $user->id,
            'login' => $user->login,
            'name' => $partner->name,
            'nom_complet' => $partner->name, // Ajout de nom_complet
            'email' => $partner->email,
            'partner_id' => $partner->id,
            'type' => $cachedToken['type'] ?? 'user'
        ];
    }

    /**
     * Endpoint de test
     */
    public function hello(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Hello from Laravel! The API is running.',
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }


    /**
     * Inscription publique d'un utilisateur
     */
  
    public function signupPublic(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {

            // ================= VALIDATION =================
            $request->validate([
                'nom' => 'required|string|max:100',
                'prenom' => 'required|string|max:100',
                'email' => 'required|email|max:255',
                'login' => 'required|string|max:50',
                'motDePasse' => 'required|string|min:6',
                'role' => 'required|in:agent,superviseur',
                'telephone' => 'nullable|string|max:20',
            ]);

            $data = $request->all();
            $role = $data['role'];
            $login = $data['login'];
            $email = $data['email'];
            $motDePasse = $data['motDePasse']; // Récupérer le mot de passe
            
            // ================= TABLE SELON RÔLE =================
            $pgTable = $role === 'agent'
                ? 'agent_tracking_agent'
                : 'agent_tracking_superviseur';

            // ================= VÉRIFICATION DOUBLONS =================
            
            // 1. Vérifier dans PostgreSQL (table agent/superviseur)
            $existsInPg = DB::table($pgTable)
                ->where('login', $login)
                ->orWhere('email', $email)
                ->exists();

            if ($existsInPg) {
                return response()->json([
                    'success' => false,
                    'error' => 'Login ou email déjà utilisé dans PostgreSQL'
                ], 400);
            }

            // 2. Vérifier dans Odoo (res_users) - login seulement
            $existsInResUsers = DB::table('res_users')
                ->where('login', $login)
                ->exists();

            if ($existsInResUsers) {
                return response()->json([
                    'success' => false,
                    'error' => 'Login déjà utilisé dans Odoo'
                ], 400);
            }

            // 3. Vérifier dans Odoo (res_partner) - email seulement
            $existsInResPartner = DB::table('res_partner')
                ->where('email', $email)
                ->exists();

            if ($existsInResPartner) {
                return response()->json([
                    'success' => false,
                    'error' => 'Email déjà utilisé dans Odoo'
                ], 400);
            }

            // ================= PRÉPARATION DES DONNÉES =================
            $now = now()->toDateTimeString();
            
            // ✅ CORRIGÉ : MOT DE PASSE EN CLAIR POUR LES DEUX BASES
            // Odoo gère son propre cryptage, donc on envoie en clair
            // PostgreSQL (votre table) peut stocker en clair ou avec bcrypt selon votre besoin
            
            $odooPassword = $motDePasse; // En clair pour Odoo
            $pgPassword = $motDePasse; // En clair pour PostgreSQL
            
            // Si vous voulez quand même hasher pour PostgreSQL, utilisez :
            // $pgPassword = bcrypt($motDePasse);
            // Mais alors votre app Flutter devra s'authentifier différemment

            // ================= CRÉATION DANS ODOO (res_partner + res_users) =================
            
            // 1. Créer dans res_partner - CHAMPS ESSENTIELS SEULEMENT
            $partnerData = [
                'name' => trim($data['prenom'] . ' ' . $data['nom']),
                'email' => $email,
                'phone' => $data['telephone'] ?? null,
                'active' => true,
                'create_date' => $now,
                'write_date' => $now,
                'create_uid' => 1,
                'write_uid' => 1,
                'company_id' => 1,
                'type' => 'contact',
                'customer_rank' => 0,
                'supplier_rank' => 0,
                'employee' => ($role === 'agent'),
                // Champs de base seulement
                'color' => 0,
                'tz' => 'Europe/Paris',
                'lang' => 'fr_FR',
                'website' => null,
                'comment' => 'Créé via API Laravel',
                'street' => null,
                'city' => null,
                'country_id' => null,
                'zip' => null,
                'function' => ($role === 'agent') ? 'Agent de sécurité' : 'Superviseur',
                'parent_id' => null,
                'is_company' => false,
                'display_name' => trim($data['prenom'] . ' ' . $data['nom']),
                'ref' => null,
                'vat' => null,
                'is_public' => false,
                'partner_share' => false,
                'debit_limit' => 0,
            ];

            // Fonction pour vérifier si une colonne existe
            $tableColumns = DB::getSchemaBuilder()->getColumnListing('res_partner');
            
            // Filtrer les données pour n'inclure que les colonnes qui existent
            $filteredPartnerData = [];
            foreach ($partnerData as $key => $value) {
                if (in_array($key, $tableColumns)) {
                    $filteredPartnerData[$key] = $value;
                } else {
                    Log::warning("⚠️ Colonne '$key' n'existe pas dans res_partner - ignorée");
                }
            }

            $partnerId = DB::table('res_partner')->insertGetId($filteredPartnerData);
            Log::info('✅ Partenaire créé dans res_partner - ID: ' . $partnerId);


            // 2. Créer dans res_users - CHAMPS ESSENTIELS SEULEMENT AVEC TOKEN
            $userData = [
                'login' => $login,
                'password' => $odooPassword, // En clair - Odoo le crypte lui-même
                'partner_id' => $partnerId,
                'active' => true,
                'create_date' => $now,
                'write_date' => $now,
                'create_uid' => 1,
                'write_uid' => 1,
                'company_id' => 1,
                'share' => false,
                'notification_type' => 'email',
                'odoobot_state' => 'not_initialized',
                'action_id' => false,
                // Ajout du token obligatoire
                'token' => Str::random(60), // Générer un token aléatoire
                // Autres champs obligatoires
                'sel_groups_1_9_10' => ($role === 'superviseur') ? 10 : 9,
                'tz' => 'Europe/Paris',
                'lang' => 'fr_FR',
                'karma' => 0,
                'odoobot_failed' => false,
            ];

            // Vérifier les colonnes de res_users
            $userColumns = DB::getSchemaBuilder()->getColumnListing('res_users');
            Log::info('📊 Colonnes res_users disponibles: ' . json_encode($userColumns));
            
            // Liste des champs potentiellement requis pour Odoo
            $potentialRequiredFields = [
                'sel_groups_1_9_10',
                'sidebar_type',
                'sidebar_visible',
                'resource_calendar_id',
                'target_sales_won',
                'target_sales_done',
                'target_sales_invoiced',
                'rank_id',
                'next_rank_id',
                'livechat_username',
                'sale_team_id',
                'signature',
                'web_tour_done',
            ];
            
            // Ajouter des valeurs par défaut pour les champs potentiels
            foreach ($potentialRequiredFields as $field) {
                if (in_array($field, $userColumns) && !array_key_exists($field, $userData)) {
                    switch ($field) {
                        case 'sel_groups_1_9_10':
                            $userData[$field] = ($role === 'superviseur') ? 10 : 9;
                            break;
                        case 'sidebar_type':
                            $userData[$field] = 'invisible';
                            break;
                        case 'sidebar_visible':
                            $userData[$field] = false;
                            break;
                        case 'target_sales_won':
                        case 'target_sales_done':
                        case 'target_sales_invoiced':
                        case 'karma':
                            $userData[$field] = 0;
                            break;
                        case 'web_tour_done':
                            $userData[$field] = false;
                            break;
                        default:
                            $userData[$field] = null;
                    }
                }
            }
            
            // Filtrer les données pour n'inclure que les colonnes qui existent
            $filteredUserData = [];
            foreach ($userData as $key => $value) {
                if (in_array($key, $userColumns)) {
                    $filteredUserData[$key] = $value;
                } else {
                    Log::warning("⚠️ Colonne '$key' n'existe pas dans res_users - ignorée");
                }
            }

            // Vérification supplémentaire pour les colonnes NOT NULL
            Log::info('📋 Données à insérer dans res_users: ' . json_encode($filteredUserData, JSON_PRETTY_PRINT));
            
            $odooUserId = DB::table('res_users')->insertGetId($filteredUserData);
            Log::info('✅ Utilisateur créé dans res_users - ID: ' . $odooUserId);

            // ================= CRÉATION DANS POSTGRESQL (table agent/superviseur) =================
            Log::info('🔄 Création dans table PostgreSQL: ' . $pgTable);
            
            $pgData = [
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'nom_complet' => trim($data['prenom'] . ' ' . $data['nom']),
                'email' => $email,
                'login' => $login,
                'motDePasse' => $pgPassword, // ✅ CORRIGÉ : en clair (ou bcrypt selon votre choix)
                'role' => $role,
                'telephone' => $data['telephone'] ?? null,
                'statut' => 'absent',
                'active' => true,
                'create_date' => $now,
                'write_date' => $now,
                'create_uid' => 1,
                'write_uid' => 1,
                'odoo_user_id' => $odooUserId,
                'odoo_partner_id' => $partnerId,
            ];

            // Champs spécifiques selon rôle
            if ($role === 'superviseur') {
                $pgData['niveau_acces'] = 'standard';
                $pgData['date_nomination'] = $now;
            }
            
            if ($role === 'agent') {
                $pgData['matricule'] = 'AG' . date('Ymd') . rand(100, 999);
                $pgData['date_embauche'] = $now;
            }

            // Vérifier les colonnes de la table spécifique
            $pgColumns = DB::getSchemaBuilder()->getColumnListing($pgTable);
            
            // Filtrer les données pour n'inclure que les colonnes qui existent
            $filteredPgData = [];
            foreach ($pgData as $key => $value) {
                if (in_array($key, $pgColumns)) {
                    $filteredPgData[$key] = $value;
                } else {
                    Log::warning("⚠️ Colonne '$key' n'existe pas dans $pgTable - ignorée");
                }
            }

            $pgUserId = DB::table($pgTable)->insertGetId($filteredPgData);
            Log::info('✅ Utilisateur créé dans ' . $pgTable . ' - ID: ' . $pgUserId);

            DB::commit();
            Log::info('✅ Transaction commitée avec succès!');

            // ================= GÉNÉRER TOKEN =================
            $token = $this->generateTokenFromLogin($login);
            $expiration = Carbon::now()->addSeconds($this->tokenExpiration);

            // Stocker dans le cache
            Cache::put("api_token:$token", [
                'user_id' => $pgUserId,
                'odoo_user_id' => $odooUserId,
                'odoo_partner_id' => $partnerId,
                'login' => $login,
                'role' => $role,
                'expires_at' => $expiration->toISOString()
            ], $this->tokenExpiration);

            // ================= RÉPONSE =================
            return response()->json([
                'success' => true,
                'message' => 'Compte créé avec succès dans PostgreSQL et Odoo',
                'pg_user_id' => $pgUserId,
                'pg_table' => $pgTable,
                'odoo_user_id' => $odooUserId,
                'odoo_partner_id' => $partnerId,
                'login' => $login,
                'role' => $role,
                'token' => $token,
                'expiration' => $expiration->toISOString(),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => 'Validation échouée',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ ERREUR signupPublicComplete: ' . $e->getMessage());
            Log::error('📋 Stack trace: ' . $e->getTraceAsString());
            
            // Erreur plus détaillée pour le debug
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'SQLSTATE') !== false) {
                $errorMessage = 'Erreur base de données: ' . $errorMessage;
            }
            
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la création du compte',
                'message' => $errorMessage,
                'debug' => env('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Authentification utilisateur
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required_without:email|string',
            'email' => 'required_without:login|string',
            'password' => 'required|string'
        ]);

        try {
            $login = $request->input('login') ?? $request->input('email');
            

            // 1. Authentifier via l'API Odoo
            $authResult = $this->authenticateViaOdooApi($login, $request->password);
            
            if ($authResult && isset($authResult['uid'])) {
                // 2. Récupérer l'utilisateur depuis la base
                $user = \DB::table('res_users')
                            ->where('id', $authResult['uid'])
                            ->where('active', true)
                            ->select('id', 'login', 'partner_id')
                            ->first();

                if ($user) {
                    // 3. Récupérer le contact
                    $partner = \DB::table('res_partner')
                                ->where('id', $user->partner_id)
                                ->where('active', true)
                                ->select('id', 'name', 'email')
                                ->first();

                    if ($partner) {
                        // 4. Déterminer le type d'utilisateur
                        $userType = $this->determineOdooUserType($partner->id, $user->login);
                        
                        // 5. Générer ET STOCKER le token dans le cache
                        $token = $this->generateTokenFromLogin($login);
                        $expiration = Carbon::now()->addSeconds($this->tokenExpiration);
                        
                        // ⬅️ STOCKER DANS LE CACHE
                        Cache::put("api_token:{$token}", [
                            'user_id' => $user->id,
                            'type' => $userType['type'],
                            'login' => $user->login,
                            'expires_at' => $expiration->toISOString(),
                            'created_at' => Carbon::now()->toISOString()
                        ], $this->tokenExpiration);
                        
                        \Log::info('Token stocké dans le cache', [
                            'token' => $token,
                            'user_id' => $user->id,
                            'type' => $userType['type'],
                            'expires_at' => $expiration
                        ]);

                        return response()->json([
                            'success' => true,
                            'user_id' => $user->id,
                            'partner_id' => $partner->id,
                            'nom_complet' => $partner->name,
                            'login' => $user->login,
                            'email' => $partner->email,
                            'role' => $userType['role'],
                            'type' => $userType['type'],
                            'agent_id' => $userType['agent_id'],
                            'token' => $token, 
                            'expiration' => $expiration->toISOString()
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => false, 
                'error' => 'Identifiants incorrects'
            ], 401);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur de connexion: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Authentifier via l'API Odoo
     */
    private function authenticateViaOdooApi($login, $password)
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 10,
                'verify' => false,
            ]);

            // Utiliser DB_DATABASE directement
            $database = env('DB_DATABASE', 'hnsafe');

            $response = $client->post(config('services.odoo.url') . '/web/session/authenticate', [
                'json' => [
                    'jsonrpc' => '2.0',
                    'params' => [
                        'db' => $database, // 'hnsafe' depuis DB_DATABASE
                        'login' => $login,
                        'password' => $password,
                    ],
                    'id' => 1
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            
            
            return $data['result'] ?? null;

        } catch (\Exception $e) {
            return null;
        }
    }
    private function determineOdooUserType($partnerId, $userLogin)
    {
        // 1. Chercher l'agent par LOGIN
        $agent = \DB::table('agent_tracking_agent')
                    ->where('login', $userLogin)
                    ->where('active', true)
                    ->first();
        
        if ($agent) {
            return [
                'type' => 'agent',
                'role' => $agent->role ?? 'agent',
                'agent_id' => $agent->id // ⭐ AJOUTER CE CHAMP
            ];
        }
        
        // 2. Chercher le superviseur par LOGIN
        $superviseur = \DB::table('agent_tracking_superviseur')
                        ->where('login', $userLogin)
                        ->where('active', true)
                        ->first();
        
        if ($superviseur) {
            return [
                'type' => 'superviseur',
                'role' => 'superviseur',
                'agent_id' => $superviseur->id // ⭐ Pour les superviseurs aussi
            ];
        }
        
        return [
            'type' => 'admin',
            'role' => 'admin',
            'agent_id' => null
        ];
    }


    /**
     * Générer un token à partir du login
     */
    private function generateTokenFromLogin($login)
    {
        $timestamp = Carbon::now()->timestamp;
        $random = Str::random(10);
        
        // Créer un hash basé sur le login, timestamp et une valeur aléatoire
        return hash('sha256', $login . $timestamp . $random . config('app.key'));
    }

    // 🔓 NOUVELLE MÉTHODE: Lire les utilisateurs sans token
    /**
     * Endpoint public pour lire les utilisateurs (sans authentification)
     */
    public function getUsersPublic(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'limit' => 'sometimes|integer|min:1|max:1000',
                'offset' => 'sometimes|integer|min:0',
                'active' => 'sometimes|boolean'
            ]);

            $limit = $request->input('limit', 100);
            $offset = $request->input('offset', 0);
            $activeOnly = $request->input('active', true);

            // Récupérer les agents
            $agentsQuery = \DB::table('agent_tracking_agent');
            
            if ($activeOnly) {
                $agentsQuery->where('active', true);
            }
            
            $totalAgents = $agentsQuery->count();
            
            $agents = $agentsQuery->offset($offset)
                ->limit($limit)
                ->get();

            // Récupérer les superviseurs
            $superviseursQuery = \DB::table('agent_tracking_superviseur');
            
            if ($activeOnly) {
                $superviseursQuery->where('active', true);
            }
            
            $totalSuperviseurs = $superviseursQuery->count();
            
            $superviseurs = $superviseursQuery->offset($offset)
                ->limit($limit)
                ->get();

            // Combiner les résultats
            $allUsers = [];
            
            foreach ($agents as $agent) {
                $allUsers[] = [
                    'id' => $agent->id,
                    'type' => 'agent',
                    'nom' => $agent->nom,
                    'prenom' => $agent->prenom,
                    'nom_complet' => $agent->nom_complet,
                    'login' => $agent->login,
                    'email' => $agent->email,
                    'role' => $agent->role ?? 'agent',
                    'telephone' => $agent->telephone,
                    'active' => (bool)$agent->active,
                    'created_at' => $agent->created_at ?? null,
                    'motDePasse' => $agent->motDePasse ?? '',
                ];
            }
            
            foreach ($superviseurs as $superviseur) {
                $allUsers[] = [
                    'id' => $superviseur->id,
                    'type' => 'superviseur',
                    'nom' => $superviseur->nom,
                    'prenom' => $superviseur->prenom,
                    'nom_complet' => $superviseur->nom_complet,
                    'login' => $superviseur->login,
                    'email' => $superviseur->email,
                    'role' => 'superviseur',
                    'telephone' => $superviseur->telephone,
                    'active' => (bool)$superviseur->active,
                    'created_at' => $superviseur->created_at ?? null,
                    'motDePasse' => $superviseur->motDePasse ?? '',
                ];
            }

            return response()->json([
                'success' => true,
                'total_agents' => $totalAgents,
                'total_superviseurs' => $totalSuperviseurs,
                'total_users' => $totalAgents + $totalSuperviseurs,
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($allUsers),
                'data' => $allUsers
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur de récupération des utilisateurs',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // 🔓 NOUVELLE MÉTHODE: Rechercher un utilisateur par login (sans token)
    /**
     * Rechercher un utilisateur par login (sans authentification)
     */
    public function findUserByLogin(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'login' => 'required|string'
            ]);

            $login = $request->input('login');

            // Chercher d'abord dans les agents
            $agent = \DB::table('agent_tracking_agent')
                ->where('login', $login)
                ->where('active', true)
                ->first();

            if ($agent) {
                return response()->json([
                    'success' => true,
                    'found' => true,
                    'user' => [
                        'id' => $agent->id,
                        'type' => 'agent',
                        'nom' => $agent->nom,
                        'prenom' => $agent->prenom,
                        'nom_complet' => $agent->nom_complet,
                        'login' => $agent->login,
                        'email' => $agent->email,
                        'role' => $agent->role ?? 'agent',
                        'telephone' => $agent->telephone,
                        'active' => (bool)$agent->active,
                        'motDePasse' => $agent->motDePasse ?? '',
                    ]
                ]);
            }

            // Chercher dans les superviseurs
            $superviseur = \DB::table('agent_tracking_superviseur')
                ->where('login', $login)
                ->where('active', true)
                ->first();

            if ($superviseur) {
                return response()->json([
                    'success' => true,
                    'found' => true,
                    'user' => [
                        'id' => $superviseur->id,
                        'type' => 'superviseur',
                        'nom' => $superviseur->nom,
                        'prenom' => $superviseur->prenom,
                        'nom_complet' => $superviseur->nom_complet,
                        'login' => $superviseur->login,
                        'email' => $superviseur->email,
                        'role' => 'superviseur',
                        'telephone' => $superviseur->telephone,
                        'active' => (bool)$superviseur->active,
                        'motDePasse' => $superviseur->motDePasse ?? '',
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'found' => false,
                'message' => 'Utilisateur non trouvé'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erreur de recherche',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Trouver ou créer un superviseur dans agent_tracking_superviseur
     */
    private function findOrCreateSuperviseur($user)
    {
        try {
            // D'abord chercher par login
            $superviseur = DB::table('agent_tracking_superviseur')
                ->where('login', $user->login)
                ->first();
            
            if ($superviseur) {
                return $superviseur->id;
            }
            
            // Chercher par email
            if (isset($user->email)) {
                $superviseur = DB::table('agent_tracking_superviseur')
                    ->where('email', $user->email)
                    ->first();
                
                if ($superviseur) {
                    return $superviseur->id;
                }
            }
            
            // Créer un nouveau superviseur
            $superviseurId = DB::table('agent_tracking_superviseur')->insertGetId([
                'nom' => $user->nom ?? '',
                'prenom' => $user->prenom ?? '',
                'nom_complet' => $user->nom_complet ?? $user->name ?? 'Superviseur',
                'login' => $user->login,
                'email' => $user->email ?? $user->login . '@example.com',
                'motDePasse' => bcrypt('password123'), // Mot de passe temporaire
                'role' => 'superviseur',
                'telephone' => '',
                'statut' => 'present',
                'active' => true,
                'niveau_acces' => 'standard',
                'date_nomination' => now(),
                'create_date' => now(),
                'write_date' => now(),
                'create_uid' => 1,
                'write_uid' => 1,
                'odoo_id' => $user->id, // ⭐ IMPORTANT: Sauvegarder l'ID Odoo
            ]);
            
            
            return $superviseurId;
            
        } catch (\Exception $e) {
            return 1; // ID par défaut
        }
    }

    /**
     * Endpoint générique pour lire les données d'un modèle
     */
    public function modelRead(Request $request): JsonResponse
    {
        // Authentifier l'utilisateur
        $user = $this->authenticateToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $request->validate([
            'model' => 'required|string',
            'offset' => 'sometimes|integer|min:0',
            'limit' => 'sometimes|integer|min:1|max:1000'
        ]);

        $modelName = $request->input('model');
        $offset = $request->input('offset', 0);
        $limit = $request->input('limit', 100);

        // Vérifier si le modèle existe et est autorisé
        if (!$this->isModelAllowed($modelName)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid model',
                'message' => "Model '$modelName' not found or not allowed for API access"
            ], 400);
        }

        try {
            $modelClass = $this->getModelClass($modelName);
            $query = $modelClass::query();

            // Appliquer les filtres WHERE
            if ($request->has('where')) {
                $where = $request->input('where');
                $this->applyWhereConditions($query, $where);
            }

            // Appliquer les relations si demandées
            if ($request->has('with')) {
                $with = $request->input('with');
                if (is_array($with)) {
                    $query->with($with);
                }
            }

            // Trier les résultats
            if ($request->has('order_by')) {
                $orderBy = $request->input('order_by');
                $direction = $request->input('order_direction', 'asc');
                $query->orderBy($orderBy, $direction);
            } else {
                // CORRECTION : Utiliser 'id' au lieu de 'created_at'
                $query->orderBy('id', 'desc');
            }

            // Compter le total
            $totalCount = $query->count();

            // Appliquer offset et limit
            $records = $query->offset($offset)
                        ->limit($limit)
                        ->get();

            return response()->json([
                'success' => true,
                'model' => $modelName,
                'total' => $totalCount,
                'offset' => $offset,
                'limit' => $limit,
                'count' => $records->count(),
                'data' => $records
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Query failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Endpoint générique pour créer des données
     */
    public function modelCreate(Request $request): JsonResponse
    {
        // Authentifier l'utilisateur
        $user = $this->authenticateToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $request->validate([
            'model' => 'required|string',
            'values' => 'required|array'
        ]);

        $modelName = $request->input('model');
        $values = $request->input('values');

        \Log::info("🔄 Création modèle: $modelName", [
            'user_id' => $user->id,
            'user_login' => $user->login,
            'values_keys' => array_keys($values)
        ]);

        // ⭐ FILTRER LES TIMESTAMPS POUR LE MODÈLE SITE

        if ($modelName === 'site') {
            \Log::info('🏢 Création site - données reçues:', $values);
            
            // 1. Nettoyer les données
            $values = array_filter($values, function($key) {
                return !in_array($key, ['created_at', 'updated_at', 'id', 'superviseur_nom']);
            }, ARRAY_FILTER_USE_KEY);
            
            // 2. 🔥 CRITIQUE : TROUVER LE SUPERVISEUR DANS LA BONNE TABLE
            if (!isset($values['superviseur_id']) || $values['superviseur_id'] <= 0) {
                // Chercher le superviseur par login dans la table superviseur
                if (isset($user->login)) {
                    $superviseur = DB::table('agent_tracking_superviseur')
                        ->where('login', $user->login)
                        ->first();
                    
                    if ($superviseur) {
                        $values['superviseur_id'] = $superviseur->id;
                        \Log::info('✅ Superviseur trouvé dans agent_tracking_superviseur: ID ' . $superviseur->id);
                    } else {
                        // Si non trouvé, chercher ou créer le superviseur
                        $values['superviseur_id'] = $this->findOrCreateSuperviseur($user);
                        \Log::info('🆕 Superviseur créé: ID ' . $values['superviseur_id']);
                    }
                } else {
                    // Superviseur par défaut
                    $values['superviseur_id'] = 1;
                    \Log::warning('⚠️ Utilisation superviseur par défaut (ID 1)');
                }
            } else {
                // Vérifier que l'ID superviseur existe dans la bonne table
                $superviseurExists = DB::table('agent_tracking_superviseur')
                    ->where('id', $values['superviseur_id'])
                    ->exists();
                
                if (!$superviseurExists) {
                    \Log::warning('⚠️ Superviseur ID ' . $values['superviseur_id'] . ' non trouvé, correction automatique');
                    
                    // Chercher le superviseur par login
                    if (isset($user->login)) {
                        $superviseur = DB::table('agent_tracking_superviseur')
                            ->where('login', $user->login)
                            ->first();
                        
                        if ($superviseur) {
                            $values['superviseur_id'] = $superviseur->id;
                        } else {
                            $values['superviseur_id'] = 1; // Fallback
                        }
                    }
                }
            }
            
            // 3. Adresse par défaut
            if (!isset($values['adresse']) && isset($values['nom'])) {
                $values['adresse'] = $values['nom'];
            }
            
            // 4. Champs Odoo obligatoires
            $values['create_date'] = now()->toDateTimeString();
            $values['write_date'] = now()->toDateTimeString();
            $values['create_uid'] = $user->id;
            $values['write_uid'] = $user->id;
            $values['active'] = true;
            
            // 5. Assurer nom et name
            if (isset($values['nom']) && !isset($values['name'])) {
                $values['name'] = $values['nom'];
            } elseif (isset($values['name']) && !isset($values['nom'])) {
                $values['nom'] = $values['name'];
            }
            
            // 6. Statut par défaut
            if (!isset($values['statut'])) {
                $values['statut'] = 'actif';
            }
            
            \Log::info('✅ Données site finalisées');
        }

        if (!$this->isModelAllowed($modelName)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid model',
                'message' => "Model '$modelName' not found or not allowed for API access"
            ], 400);
        }

        try {
            $modelClass = $this->getModelClass($modelName);
            
            // ==================== LOGIQUE SPÉCIALE POUR LES UTILISATEURS ====================
            if ($modelName === 'agent' || $modelName === 'superviseur') {
                \Log::info('👤 Création utilisateur via modelCreate', [
                    'model' => $modelName,
                    'login' => $values['login'] ?? 'N/A'
                ]);
                
                // Vérifier si login existe déjà
                if (isset($values['login'])) {
                    $tableName = 'agent_tracking_' . $modelName;
                    $existing = DB::table($tableName)
                        ->where('login', $values['login'])
                        ->first();
                        
                    if ($existing) {
                        \Log::warning('Login déjà utilisé', ['login' => $values['login']]);
                        return response()->json([
                            'success' => false,
                            'error' => 'Login déjà utilisé',
                            'message' => 'Un utilisateur avec ce login existe déjà'
                        ], 400);
                    }
                }
                
                // Hasher le mot de passe si présent
                if (isset($values['motDePasse'])) {
                    $values['motDePasse'] = bcrypt($values['motDePasse']);
                    \Log::info('🔐 Mot de passe hashé');
                }
                
                // Ajouter champs obligatoires
                $values['active'] = true;
                $values['created_at'] = now();
                $values['updated_at'] = now();
                
                // Créer nom_complet si manquant
                if (!isset($values['nom_complet']) && isset($values['nom']) && isset($values['prenom'])) {
                    $values['nom_complet'] = trim($values['nom'] . ' ' . $values['prenom']);
                    \Log::info('📛 Nom complet généré: ' . $values['nom_complet']);
                }
                
                // Pour les superviseurs, ajouter des champs par défaut
                if ($modelName === 'superviseur') {
                    if (!isset($values['niveau_acces'])) {
                        $values['niveau_acces'] = 'standard';
                    }
                    if (!isset($values['date_nomination'])) {
                        $values['date_nomination'] = now();
                    }
                }
                
                // Pour les agents, ajouter des champs par défaut
                if ($modelName === 'agent') {
                    if (!isset($values['matricule'])) {
                        $values['matricule'] = 'AG' . date('Ymd') . rand(100, 999);
                    }
                    if (!isset($values['date_embauche'])) {
                        $values['date_embauche'] = now();
                    }
                }
            }
            // ==================== FIN LOGIQUE UTILISATEURS ====================

            // ==================== LOGIQUE SPÉCIALE POUR LES RONDES ====================
            if ($modelName === 'round') {
                \Log::info('🔄 Création ronde - données reçues:', $values);
                
                // 1. GÉRER LE SITE (convertir nom → ID)
                if (isset($values['site']) && is_string($values['site'])) {
                    $siteName = trim($values['site']);
                    \Log::info('📍 Traitement site: ' . $siteName);
                    
                    // Chercher ou créer le site
                    $siteId = $this->findOrCreateSite($siteName, $user->id);
                    $values['site_id'] = $siteId;
                    unset($values['site']); // Supprimer le champ 'site'
                    
                    \Log::info('✅ Site traité - ID: ' . $siteId);
                }
                
                // 2. GÉRER LE SUPERVISEUR
                if (!isset($values['superviseur_id'])) {
                    // Chercher l'ID du superviseur correspondant à l'utilisateur
                    $superviseur = DB::table('agent_tracking_superviseur')
                        ->where('user_id', $user->id)
                        ->orWhere('login', $user->login)
                        ->first();
                    
                    if ($superviseur) {
                        $values['superviseur_id'] = $superviseur->id;
                    } else {
                        // Utiliser l'ID de l'utilisateur comme fallback
                        $values['superviseur_id'] = $user->id;
                    }
                    \Log::info('👨‍💼 Superviseur ID: ' . $values['superviseur_id']);
                }
                
                // 3. GÉRER LES POINTS (JSON)
                if (isset($values['points'])) {
                    if (is_string($values['points'])) {
                        // Déjà en JSON string, le garder
                        $values['geolocation_coords'] = $values['points'];
                    } elseif (is_array($values['points'])) {
                        // Convertir array en JSON
                        $values['geolocation_coords'] = json_encode($values['points']);
                    }
                    unset($values['points']); // Supprimer l'ancien champ
                }
                
                // 4. GÉRER LA GÉOLOCALISATION
                if (isset($values['latitude']) || isset($values['longitude']) || isset($values['radius_m'])) {
                    // Créer un objet Round pour validation
                    $round = new \App\Models\Round($values);
                    
                    // Validation des coordonnées (si la méthode existe)
                    try {
                        if (method_exists($round, 'validateCoordinates')) {
                            $round->validateCoordinates();
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Validation coordonnées ignorée: ' . $e->getMessage());
                    }
                }
                
                // 5. GÉRER DATE_CREATION (devenir create_date)
                if (isset($values['date_creation'])) {
                    $values['create_date'] = $values['date_creation'];
                    unset($values['date_creation']);
                } else {
                    $values['create_date'] = now()->toDateTimeString();
                }
                
                // 6. CHAMPS ODOO OBLIGATOIRES
                $values['write_date'] = now()->toDateTimeString();
                $values['create_uid'] = $user->id;
                $values['write_uid'] = $user->id;
                
                // 7. S'ASSURER DU STATUT
                if (!isset($values['statut'])) {
                    $values['statut'] = 'en_cours';
                }
                // Si le statut vient de Flutter 'en_cours', le garder tel quel
                if (isset($values['statut']) && $values['statut'] === 'en_cours') {
                    // Garder 'en_cours' pour Laravel
                    \Log::info("✅ Statut ronde: 'en_cours' (venant de Flutter)");
                }
                
                // 8. CALCULER L'AREA SI RAYON DISPONIBLE
                if (isset($values['radius_m']) && is_numeric($values['radius_m'])) {
                    $radius = floatval($values['radius_m']);
                    $values['area_m2'] = pi() * pow($radius, 2);
                }
                
                // 9. ADRESSE PAR DÉFAUT
                if (!isset($values['address']) && isset($siteName)) {
                    $values['address'] = $siteName;
                }
                
                \Log::info('📦 Données finales ronde:', $values);
            }
            // ==================== FIN LOGIQUE RONDES ====================

            // ==================== LOGIQUE SPÉCIALE POUR LES SITES ====================
            if ($modelName === 'site') {
                \Log::info('🏢 Création site - données reçues:', $values);
                
                // 1. Nettoyer les données
                $values = array_filter($values, function($key) {
                    return !in_array($key, ['created_at', 'updated_at', 'id']);
                }, ARRAY_FILTER_USE_KEY);
                
                // 2. Ajouter champs obligatoires
                if (!isset($values['statut'])) {
                    $values['statut'] = 'actif';
                }
                
                if (!isset($values['superviseur_id']) && isset($user->id)) {
                    $values['superviseur_id'] = $user->id;
                }
                
                // 3. Adresse par défaut
                if (!isset($values['adresse']) && isset($values['nom'])) {
                    $values['adresse'] = $values['nom'];
                }
                
                // 4. Champs Odoo
                $values['create_date'] = now()->toDateTimeString();
                $values['write_date'] = now()->toDateTimeString();
                $values['create_uid'] = $user->id;
                $values['write_uid'] = $user->id;
                
                // 5. Assurer nom et name
                if (isset($values['nom']) && !isset($values['name'])) {
                    $values['name'] = $values['nom'];
                } elseif (isset($values['name']) && !isset($values['nom'])) {
                    $values['nom'] = $values['name'];
                }
                
                \Log::info('✅ Données site finalisées');
            }
            // ==================== FIN LOGIQUE SITES ====================

            // ==================== LOGIQUE SPÉCIALE POUR LES RONDE.PHOTO ====================
            if ($modelName === 'ronde.photo') {
                \Log::info('📷 Création photo ronde - données reçues:', array_keys($values));
                
                // Traitement spécial pour l'image
                if (isset($values['image']) && is_string($values['image'])) {
                    $imageData = $values['image'];
                    
                    // ⭐ NETTOYAGE UTF-8
                    $imageData = mb_convert_encoding($imageData, 'UTF-8', 'UTF-8');
                    
                    // Vérifier si c'est du base64 valide
                    if (base64_decode($imageData, true) !== false) {
                        // C'est du base64 valide, le garder tel quel
                        $values['image'] = $imageData;
                        \Log::info('✅ Image en base64 valide, longueur: ' . strlen($imageData));
                    } else {
                        // Ce n'est pas du base64 valide
                        \Log::warning('⚠️ Image pas en base64 valide, tentative de correction');
                        
                        // Essayer d'extraire le base64 si format data URL
                        if (strpos($imageData, 'data:image') === 0) {
                            $parts = explode(',', $imageData);
                            if (count($parts) > 1) {
                                $values['image'] = $parts[1];
                                \Log::info('✅ Extrait base64 depuis data URL');
                            }
                        }
                    }
                }
                
                // S'assurer des champs Odoo obligatoires
                if (!isset($values['create_date'])) {
                    $values['create_date'] = now()->toDateTimeString();
                }
                if (!isset($values['write_date'])) {
                    $values['write_date'] = now()->toDateTimeString();
                }
                
                // S'assurer que ronde_id existe
                if (!isset($values['ronde_id']) || $values['ronde_id'] <= 0) {
                    \Log::error('❌ ronde_id manquant ou invalide', ['ronde_id' => $values['ronde_id'] ?? 'N/A']);
                    return response()->json([
                        'success' => false,
                        'error' => 'ronde_id manquant ou invalide'
                    ], 400);
                }
                
                \Log::info('📦 Données finales photo ronde préparées');
            }
            // ==================== FIN LOGIQUE PHOTOS ====================

            // ==================== LOGIQUE SPÉCIALE POUR LES VISITEURS ====================
            if ($modelName === 'visiteur') {
                \Log::info('👤 Création visiteur - données reçues:', $values);
                
                // Validation des champs obligatoires
                if (!isset($values['name']) || !isset($values['prenom'])) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Nom et prénom sont obligatoires'
                    ], 400);
                }
                
                // Créer nom_complet
                $values['nom_complet'] = trim($values['name'] . ' ' . $values['prenom']);
                
                // Type visiteur par défaut
                if (!isset($values['type_visiteur_id'])) {
                    $defaultType = DB::table('type_visiteur')
                        ->where('active', true)
                        ->orderBy('id')
                        ->first();
                    
                    if ($defaultType) {
                        $values['type_visiteur_id'] = $defaultType->id;
                    } else {
                        // Créer un type par défaut
                        $typeId = DB::table('type_visiteur')->insertGetId([
                            'name' => 'Visiteur standard',
                            'code' => 'VST',
                            'description' => 'Type par défaut',
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                        $values['type_visiteur_id'] = $typeId;
                    }
                }
                
                // Champs par défaut
                $values['active'] = true;
                $values['created_at'] = now();
                $values['updated_at'] = now();
                
                // Référence automatique si manquante
                if (!isset($values['ref'])) {
                    $values['ref'] = 'VIS' . date('Ymd') . rand(100, 999);
                }
            }
            // ==================== FIN LOGIQUE VISITEURS ====================

            // ==================== LOGIQUE SPÉCIALE POUR LES AFFECTATIONS ====================
            if ($modelName === 'affectation') {
                \Log::info('📋 Création affectation - données reçues:', $values);

                 TimezoneHelper::adjustDates($values, [
                    'date_debut_affectation',
                    'date_fin_affectation',
                    'heure_debut_reelle',
                    'heure_fin_reelle'
                ]);
    
                
                // Validation des dates
                if (isset($values['date_debut_affectation']) && isset($values['date_fin_affectation'])) {
                    try {
                        $dateDebut = Carbon::parse($values['date_debut_affectation']);
                        $dateFin = Carbon::parse($values['date_fin_affectation']);
                        
                        if ($dateFin->lt($dateDebut)) {
                            return response()->json([
                                'success' => false,
                                'error' => 'La date de fin doit être après la date de début'
                            ], 400);
                        }
                    } catch (\Exception $e) {
                        \Log::warning('Erreur parsing dates affectation: ' . $e->getMessage());
                    }
                }
                if ($modelName === 'affectation') {
                    \Log::info('📋 Création affectation - Données reçues:', $values);
                    
                    // ⭐ NORMALISATION DES STATUTS POUR ODOO
                    if (isset($values['statut_affectation'])) {
                        $statut = strtolower(trim($values['statut_affectation']));
                        
                        // Conversion depuis Flutter vers Odoo
                        $statutMap = [
                            // Format Flutter → Format Odoo
                            'planifiee' => 'planifie',     // Flutter 'planifiee' → Odoo 'planifie'
                            'planifie' => 'planifie',      // Flutter 'planifie' → Odoo 'planifie'
                            'planifié' => 'planifie',      // Interface 'Planifié' → Odoo 'planifie'
                            'planned' => 'planifie',       // Anglais
                            
                            'en_cours' => 'en_cours',      // Même format
                            'en cours' => 'en_cours',      // Sans underscore
                            
                            'terminee' => 'termine',       // Flutter 'terminee' → Odoo 'termine'
                            'termine' => 'termine',        // Flutter 'termine' → Odoo 'termine'
                            'terminé' => 'termine',        // Interface 'Terminé' → Odoo 'termine'
                            'completed' => 'termine',      // Anglais
                            
                            'annulee' => 'annule',         // Flutter 'annulee' → Odoo 'annule'
                            'annule' => 'annule',          // Flutter 'annule' → Odoo 'annule'
                            'annulé' => 'annule',          // Interface 'Annulé' → Odoo 'annule'
                            'cancelled' => 'annule',       // Anglais
                        ];
                        
                        if (isset($statutMap[$statut])) {
                            $values['statut_affectation'] = $statutMap[$statut];
                            \Log::info("✅ Statut Odoo: '{$values['statut_affectation']}' (original: '{$statut}')");
                        } else {
                            // Valeur par défaut Odoo
                            $values['statut_affectation'] = 'planifie';
                            \Log::warning("⚠️ Statut inconnu: '{$statut}', valeur Odoo par défaut: 'planifie'");
                        }
                    } else {
                        // Statut par défaut Odoo
                        $values['statut_affectation'] = 'planifie';
                        \Log::info('📌 Statut non fourni, valeur Odoo par défaut: planifie');
                    }
    
                    \Log::info('📦 Données finales pour Odoo:', $values);
                }
                
                // Vérifier disponibilité de l'agent
                if (isset($values['agent_id']) && isset($values['date_debut_affectation']) && isset($values['date_fin_affectation'])) {
                    $overlap = DB::table('agent_tracking_affectation')
                        ->where('agent_id', $values['agent_id'])
                        ->where('statut_affectation', '!=', 'termine')
                        ->where(function($query) use ($values) {
                            $query->whereBetween('date_debut_affectation', [$values['date_debut_affectation'], $values['date_fin_affectation']])
                                ->orWhereBetween('date_fin_affectation', [$values['date_debut_affectation'], $values['date_fin_affectation']])
                                ->orWhere(function($q) use ($values) {
                                    $q->where('date_debut_affectation', '<=', $values['date_debut_affectation'])
                                        ->where('date_fin_affectation', '>=', $values['date_fin_affectation']);
                                });
                        })
                        ->first();
                    
                    if ($overlap) {
                        return response()->json([
                            'success' => false,
                            'error' => "L'agent est déjà affecté pendant cette période"
                        ], 400);
                    }
                }
                
                // Statut par défaut
                if (!isset($values['statut_affectation'])) {
                    $values['statut_affectation'] = 'planifie';
                }
            }
            // ==================== FIN LOGIQUE AFFECTATIONS ====================

           // REMPLACEZ TOUTE LA SECTION agent_gps_history PAR CE CODE :

            if ($modelName === 'agent_gps_history') {
                try {
                    \Log::info('📍 CRÉATION POSITION GPS - DÉBUT');
                    \Log::info('📋 Données reçues:', $values);
                    
                    // ⭐ 1. TRANSFORMER LA SOURCE POUR ODOO
                    if (isset($values['source'])) {
                        $sourceMap = [
                            'Affectation Active' => 'mobile_app',
                            'Ronde Exécution' => 'mobile_app',
                            'Background GPS' => 'mobile_app',
                            'Début Ronde' => 'mobile_app',
                            'Fin Ronde' => 'mobile_app',
                            'mobile_app' => 'mobile_app',
                            'manual' => 'manual',
                            'system' => 'system'
                        ];
                        
                        $values['source'] = $sourceMap[$values['source']] ?? 'mobile_app';
                    } else {
                        $values['source'] = 'mobile_app';
                    }
                    
                    // ⭐ 2. FILTRER LES COLONNES POUR ODOO
                    $allowedColumns = [
                        'agent_id', 'round_id', 'affectation_id',
                        'latitude', 'longitude', 'accuracy', 'altitude',
                        'timestamp', 'source'
                    ];
                    
                    $filteredValues = [];
                    foreach ($allowedColumns as $column) {
                        if (isset($values[$column])) {
                            $filteredValues[$column] = $values[$column];
                        }
                    }
                    
                    // ⭐ 3. ENLEVER LES CHAMPS QUI N'EXISTENT PAS DANS ODOO
                    $invalidFields = ['speed', 'heading', 'affectation_name', 'affectation_status', 
                                    'is_ronde_start', 'is_ronde_end'];
                    foreach ($invalidFields as $field) {
                        unset($filteredValues[$field]);
                    }
                    
                    // ⭐⭐ 4. CORRECTION -1h POUR LE TIMESTAMP (LA PARTIE MANQUANTE !)
                    if (isset($filteredValues['timestamp'])) {
                        \Log::info("🕐 Timestamp reçu: " . $filteredValues['timestamp']);
                        
                        try {
                            // ⭐⭐ APPLIQUER LA CORRECTION -1h ICI
                            $filteredValues['timestamp'] = TimezoneHelper::adjustCasablancaToUTC($filteredValues['timestamp']);
                            \Log::info("   → Timestamp corrigé -1h: " . $filteredValues['timestamp']);
                        } catch (\Exception $e) {
                            \Log::warning("⚠️ Erreur correction timestamp, utilisation UTC actuel: " . $e->getMessage());
                            $filteredValues['timestamp'] = now()->setTimezone('UTC')->toDateTimeString();
                        }
                    } else {
                        $filteredValues['timestamp'] = now()->setTimezone('UTC')->toDateTimeString();
                    }
                    
                    // ⭐ 5. DÉBOGUER LE ROUND
                    if (isset($filteredValues['round_id'])) {
                        $round = DB::table('agent_tracking_round')
                            ->where('id', $filteredValues['round_id'])
                            ->select('id', 'libelle', 'latitude', 'longitude', 'radius_m')
                            ->first();
                        
                        if ($round) {
                            \Log::info('🔍 INFOS ROUND ' . $round->id . ':');
                            \Log::info('   Libellé: ' . $round->libelle);
                            \Log::info('   Latitude: ' . ($round->latitude ?? 'NULL'));
                            \Log::info('   Longitude: ' . ($round->longitude ?? 'NULL'));
                            \Log::info('   Radius (m): ' . ($round->radius_m ?? 'NULL'));
                        } else {
                            \Log::warning('⚠️ Round ' . $filteredValues['round_id'] . ' non trouvé!');
                        }
                    }
                    
                    \Log::info('📦 Données finales pour Odoo:', $filteredValues);
                    
                    // ⭐ 6. CRÉER L'ENREGISTREMENT
                    $record = $modelClass::create($filteredValues);
                    
                    // ⭐ 7. RÉPONSE AVEC DÉBOGUAGE
                    return response()->json([
                        'success' => true,
                        'message' => 'Position GPS créée avec succès',
                        'id' => $record->id,
                        'is_in_zone' => $record->is_in_zone ?? false,
                        'distance_from_zone' => $record->distance_from_zone ?? 0,
                        'debug' => [
                            'round_id' => $record->round_id,
                            'source' => $record->source,
                            'position_lat' => $record->latitude,
                            'position_lon' => $record->longitude,
                            'timestamp_received' => $values['timestamp'] ?? null,
                            'timestamp_stored' => $record->timestamp,
                            'has_round_coords' => ($round && $round->latitude && $round->longitude) ? 'OUI' : 'NON',
                            'round_radius' => $round->radius_m ?? 'NULL'
                        ]
                    ], 201);
                    
                } catch (\Illuminate\Validation\ValidationException $e) {
                    \Log::error('❌ Validation error: ' . json_encode($e->errors()));
                    return response()->json([
                        'success' => false,
                        'error' => 'Validation failed',
                        'errors' => $e->errors()
                    ], 422);
                } catch (\Exception $e) {
                    \Log::error('❌ ERREUR création position GPS: ' . $e->getMessage());
                    \Log::error('📋 Stack trace: ' . $e->getTraceAsString());
                    return response()->json([
                        'success' => false,
                        'error' => 'Creation failed',
                        'message' => $e->getMessage(),
                        'values_received' => $values ?? []
                    ], 500);
                }
            }


            // ==================== LOGIQUE SPÉCIALE POUR LES INCIDENT.PHOTO ====================
            if ($modelName === 'incident.photo') {
                \Log::info('📷 Création photo incident - données reçues:', [
                    'incident_id' => $values['incident_id'] ?? 'N/A',
                    'has_image' => isset($values['image']) && !empty($values['image']),
                    'image_length' => isset($values['image']) ? strlen($values['image']) : 0
                ]);
                
                // 1. VÉRIFIER L'INCIDENT
                if (!isset($values['incident_id']) || $values['incident_id'] <= 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'incident_id manquant ou invalide'
                    ], 400);
                }
                
                // Vérifier que l'incident existe
                $incident = DB::table('agent_incident')->where('id', $values['incident_id'])->first();
                if (!$incident) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Incident non trouvé'
                    ], 404);
                }
                
                // 2. TRAITEMENT DE L'IMAGE
                if (isset($values['image']) && is_string($values['image'])) {
                    $imageData = $values['image'];
                    
                    // ⭐ NETTOYAGE UTF-8
                    $imageData = mb_convert_encoding($imageData, 'UTF-8', 'UTF-8');
                    
                    // Vérifier si c'est du base64 valide
                    if (base64_decode($imageData, true) !== false) {
                        $values['image'] = $imageData;
                        \Log::info('✅ Image en base64 valide, longueur: ' . strlen($imageData));
                    } else {
                        \Log::warning('⚠️ Image pas en base64 valide, tentative de correction');
                        
                        // Essayer d'extraire le base64 si format data URL
                        if (strpos($imageData, 'data:image') === 0) {
                            $parts = explode(',', $imageData);
                            if (count($parts) > 1) {
                                $values['image'] = $parts[1];
                                \Log::info('✅ Extrait base64 depuis data URL');
                            } else {
                                return response()->json([
                                    'success' => false,
                                    'error' => 'Format image invalide'
                                ], 400);
                            }
                        } else {
                            return response()->json([
                                'success' => false,
                                'error' => 'Format image invalide (base64 attendu)'
                            ], 400);
                        }
                    }
                } else {
                    \Log::warning('⚠️ Aucune image fournie pour la photo d\'incident');
                    $values['image'] = null; // Permettre les photos sans image (pour texte seulement)
                }
                
                // 3. NOM DE FICHIER PAR DÉFAUT
                if (!isset($values['filename']) || empty($values['filename'])) {
                    $values['filename'] = 'incident_' . $values['incident_id'] . '_' . time() . '.jpg';
                }
                
                // 4. DESCRIPTION PAR DÉFAUT
                if (!isset($values['description'])) {
                    $values['description'] = 'Photo incident ' . date('d/m/Y H:i');
                }
                
                // 5. DATE PRISE DE VUE PAR DÉFAUT
                if (!isset($values['date_prise_vue'])) {
                    $values['date_prise_vue'] = now()->toDateTimeString();
                } else {
                    // ⭐ CORRECTION FUSEAU HORAIRE
                    try {
                        $values['date_prise_vue'] = TimezoneHelper::adjustCasablancaToUTC($values['date_prise_vue']);
                    } catch (\Exception $e) {
                        \Log::warning('Erreur correction fuseau horaire date_prise_vue: ' . $e->getMessage());
                    }
                }
                
                // 6. CHAMPS ODOO OBLIGATOIRES
                $values['create_date'] = now()->toDateTimeString();
                $values['write_date'] = now()->toDateTimeString();
                $values['create_uid'] = $user->id;
                $values['write_uid'] = $user->id;
                
                \Log::info('📦 Données finales photo incident préparées', [
                    'incident_id' => $values['incident_id'],
                    'filename' => $values['filename'],
                    'description_length' => strlen($values['description'] ?? ''),
                    'has_image' => !empty($values['image'])
                ]);
            }
// ==================== FIN LOGIQUE INCIDENT.PHOTO ====================
                        

            // Validation spécifique selon le modèle
            $this->validateModelData($modelName, $values);

            // Créer l'enregistrement
            $record = $modelClass::create($values);

            // LOG SUCCÈS
            \Log::info('✅ ' . ucfirst($modelName) . ' créé avec ID: ' . $record->id, [
                'model' => $modelName,
                'id' => $record->id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Record created successfully',
                'id' => $record->id,
                'data' => $record
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('❌ Erreur validation création ' . $modelName . ': ' . json_encode($e->errors()));
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Creation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    
    // ⭐⭐ AJOUTER CETTE MÉTHODE À LA CLASSE ApiController
    /**
     * Ajuste le fuseau horaire des dates (soustrait/ajoute des heures)
     */
    private function adjustTimezoneForDates(array &$data, array $dateFields, int $hoursToAdjust): void
    {
        foreach ($dateFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                try {
                    // Parser la date
                    $date = \Carbon\Carbon::parse($data[$field]);
                    
                    // Ajuster les heures (soustraire pour UTC+1 -> UTC)
                    $date = $date->addHours($hoursToAdjust); // Pour UTC+1, ajouter -1 = soustraire 1h
                    
                    // Forcer en UTC pour la base
                    $date = $date->setTimezone('UTC');
                    
                    // Mettre à jour la valeur
                    $data[$field] = $date->toDateTimeString();
                    
                    
                } catch (\Exception $e) {
                    \Log::warning("   ⚠️ Erreur ajustement date $field: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Chercher ou créer un site
     */
    private function findOrCreateSite($siteName, $superviseurId = null): int
    {
        try {
            $siteName = trim($siteName);
            
            if (empty($siteName)) {
                $siteName = 'Site Inconnu';
            }
            
            \Log::info('🔍 Recherche site: ' . $siteName);
            
            // Chercher le site (insensible à la casse)
            $site = DB::table('agent_tracking_site')
                ->where(function($query) use ($siteName) {
                    $query->whereRaw('LOWER(nom) = ?', [strtolower($siteName)])
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($siteName)]);
                })
                ->first();
            
            if ($site) {
                return $site->id;
            }
            
            // Créer un nouveau site
            $now = Carbon::now()->toDateTimeString();
            $siteId = DB::table('agent_tracking_site')->insertGetId([
                'nom' => $siteName,
                'name' => $siteName,
                'superviseur_id' => $superviseurId,
                'statut' => 'actif',
                'adresse' => 'À compléter',
                'ville' => 'À compléter',
                'create_date' => $now,
                'write_date' => $now,
                'create_uid' => 1,
                'write_uid' => 1,
                'active' => true,
            ]);
            
            
            return $siteId;
            
        } catch (\Exception $e) {
            \Log::error('❌ Erreur findOrCreateSite: ' . $e->getMessage());
            // Fallback: retourner ID 1 (site par défaut)
            return 1;
        }
    }

    // ApiController.php - AJOUTER CETTE MÉTHODE
    /**
     * Endpoint générique pour mettre à jour des données
     */
    // Cette méthode existe déjà dans votre ApiController
    public function modelUpdate(Request $request): JsonResponse
    {
        // Authentification via token
        $user = $this->authenticateToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $request->validate([
            'model' => 'required|string',
            'values' => 'required|array',
            'id' => 'required|string'
        ]);

        $modelName = $request->input('model');
        $values = $request->input('values');
        $id = $request->input('id');

        if (!$this->isModelAllowed($modelName)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid model',
                'message' => "Model '$modelName' not found or not allowed for API access"
            ], 400);
        }

        try {
            $modelClass = $this->getModelClass($modelName);
            
            // Trouver l'enregistrement
            $record = $modelClass::find($id);
            if (!$record) {
                return response()->json([
                    'success' => false,
                    'error' => 'Record not found',
                    'message' => "Record with id $id not found"
                ], 404);
            }

            // Mettre à jour
            $record->update($values);

            return response()->json([
                'success' => true,
                'message' => 'Record updated successfully',
                'data' => $record
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Update failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint générique pour supprimer des données
     */
    public function modelDelete(Request $request): JsonResponse
    {
        $user = $this->authenticateToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $request->validate([
            'model' => 'required|string',
            'id' => 'required|string'
        ]);

        $modelName = $request->input('model');
        $id = $request->input('id');

        if (!$this->isModelAllowed($modelName)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid model',
                'message' => "Model '$modelName' not found or not allowed for API access"
            ], 400);
        }

        try {
            $modelClass = $this->getModelClass($modelName);
            
            // Trouver l'enregistrement
            $record = $modelClass::find($id);
            if (!$record) {
                return response()->json([
                    'success' => false,
                    'error' => 'Record not found',
                    'message' => "Record with id $id not found"
                ], 404);
            }

            // Supprimer
            $record->delete();

            return response()->json([
                'success' => true,
                'message' => 'Record deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Delete failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Déconnexion - Invalider le token
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        
        if ($token) {
            Cache::forget("api_token:{$token}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Vérifier le token (endpoint de validation)
     */
    public function verifyToken(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticateToken($request);
            
            if ($user) {
                $nom_complet = property_exists($user, 'nom_complet') 
                    ? $user->nom_complet 
                    : ($user->name ?? 'Utilisateur');
                
                return response()->json([
                    'success' => true,
                    'valid' => true,
                    'user' => [
                        'id' => $user->id,
                        'nom_complet' => $nom_complet,
                        'login' => $user->login ?? '',
                        'type' => $user->type ?? 'user'
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Token invalide ou expiré'
            ], 401);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Erreur de validation du token: ' . $e->getMessage()
            ], 500);
        }
    }

    private function isModelAllowed(string $modelName): bool
    {
        $allowedModels = [
            'agent', 'superviseur', 'site', 'stock', 'affectation', 'round', 
            'incident', 'alert', 'pointage','ronde', 'ronde.photo','agent_gps_history', 'type_visiteur', 'visiteur', 'visiteur.photo', 'incident.photo'
        ];
        return in_array(strtolower($modelName), $allowedModels);
    }

    private function getModelClass(string $modelName): string
    {
        $modelMap = [
            'agent' => Agent::class,
            'superviseur' => Superviseur::class,
            'site' => Site::class,
            'stock' => Stock::class,
            'affectation' => Affectation::class,
            'round' => Round::class,
            'incident' => Incident::class,
            'alert' => Alert::class,
            'pointage' => Pointage::class,
            'ronde' => Ronde::class,  
            'ronde.photo' => RondePhoto::class,
            'agent_gps_history' => AgentGPSHistory::class,
            'visiteur' => Visiteur::class,
            'visiteur.photo' => VisiteurPhoto::class,
           'type_visiteur' => TypeVisiteur::class,
           'incident.photo' => IncidentPhoto::class,


        ];

        $modelName = strtolower($modelName);
        
        if (!isset($modelMap[$modelName])) {
            throw new \Exception("Model class not found for: $modelName");
        }

        return $modelMap[$modelName];
    }

    private function applyWhereConditions($query, $whereConditions): void
    {
        if (is_array($whereConditions)) {
            foreach ($whereConditions as $condition) {
                if (is_array($condition) && count($condition) >= 2) {
                    if (count($condition) === 3) {
                        $query->where($condition[0], $condition[1], $condition[2]);
                    } elseif (count($condition) === 2) {
                        $query->where($condition[0], '=', $condition[1]);
                    }
                } elseif (is_string($condition)) {
                    $query->whereRaw($condition);
                }
            }
        } elseif (is_array($whereConditions) && !empty($whereConditions)) {
            foreach ($whereConditions as $field => $value) {
                $query->where($field, $value);
            }
        }
    }

    private function validateModelData(string $modelName, array $data, $id = null): void
    {
        switch ($modelName) {
            case 'affectation':
                if (isset($data['date_debut_affectation']) && isset($data['date_fin_affectation'])) {
                    $dateDebut = Carbon::parse($data['date_debut_affectation']);
                    $dateFin = Carbon::parse($data['date_fin_affectation']);
                    
                    if ($dateFin->lt($dateDebut)) {
                        throw new \Exception("La date de fin doit être après la date de début");
                    }
                }
                break;

            case 'round':
                if (isset($data['latitude']) && ($data['latitude'] < -90 || $data['latitude'] > 90)) {
                    throw new \Exception("Latitude invalide");
                }
                if (isset($data['longitude']) && ($data['longitude'] < -180 || $data['longitude'] > 180)) {
                    throw new \Exception("Longitude invalide");
                }
                if (isset($data['radius_m']) && $data['radius_m'] < 0) {
                    throw new \Exception("Rayon invalide");
                }
                break;

            case 'stock':
                if (isset($data['quantite']) && $data['quantite'] < 0) {
                    throw new \Exception("Quantité invalide");
                }
                break;

            case 'agent':
            case 'superviseur':
                if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception("Email invalide");
                }
                if (isset($data['telephone']) && !preg_match('/^[0-9+\-\s]{8,}$/', $data['telephone'])) {
                    throw new \Exception("Téléphone invalide");
                }
                break;
        }
    }

    /**
     * Endpoint pour vérifier la santé de l'API
     */
    public function health(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            return response()->json([
                'success' => true,
                'status' => 'healthy',
                'database' => 'connected',
                'timestamp' => Carbon::now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 'unhealthy',
                'database' => 'disconnected',
                'error' => $e->getMessage(),
                'timestamp' => Carbon::now()->toISOString()
            ], 503);
        }
    }

    /**
     * Endpoint pour les statistiques
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $this->authenticateToken($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token'
            ], 401);
        }

        try {
            $stats = [
                'agents' => Agent::getAgentStats(),
                'sites' => [
                    'total' => Site::count(),
                    'actifs' => Site::where('statut', 'actif')->count(),
                ],
                'rounds' => [
                    'total' => Round::count(),
                    'en_cours' => Round::where('statut', 'en_cours')->count(),
                ],
                'alertes' => [
                    'actives' => Alert::where('state', 'active')->count(),
                    'critiques' => Alert::where('state', 'active')->where('severite', 'critique')->count(),
                ]
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Stats failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    



    
}

