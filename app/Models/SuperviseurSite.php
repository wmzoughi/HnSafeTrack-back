<?php
// app/Models/SuperviseurSite.php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class SuperviseurSite extends Pivot
{
    protected $table = 'agent_tracking_superviseur_site';
    
    protected $fillable = [
        'superviseur_id', 'site_id'
    ];
}