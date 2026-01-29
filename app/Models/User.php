<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // Utiliser la table Odoo des contacts
    protected $table = 'res_partner';
    public $timestamps = false;
    
    protected $fillable = [
        'id', 'name', 'email', 'login', 'password', 
        'type', 'active', 'phone'
    ];

    // Relation avec la table agent (si besoin)
    public function agent()
    {
        return $this->hasOne(Agent::class, 'partner_id', 'id');
    }

    // Relation avec la table superviseur (si besoin)
    public function superviseur()
    {
        return $this->hasOne(Superviseur::class, 'partner_id', 'id');
    }

    public function getAuthPassword()
    {
        return $this->password;
    }
    
    public function getRoleAttribute()
    {
        // Déterminer le rôle basé sur les relations
        if ($this->agent) {
            return $this->agent->role;
        }
        if ($this->superviseur) {
            return 'superviseur';
        }
        return 'contact';
    }
}