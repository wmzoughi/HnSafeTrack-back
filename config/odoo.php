<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuration Odoo
    |--------------------------------------------------------------------------
    | Ces valeurs sont lues depuis le fichier .env
    | Ne jamais mettre les valeurs en dur ici
    */

    'url' => env('ODOO_URL', 'http://localhost:8069'),

    'db' => env('ODOO_DB', ''),

    'username' => env('ODOO_USERNAME', ''),

    'password' => env('ODOO_PASSWORD', ''),

];
