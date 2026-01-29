<?php

namespace App\Services;

use PhpXmlRpc\Client;
use PhpXmlRpc\Request;
use PhpXmlRpc\Value;

class OdooService
{
    private string $url;
    private string $db;
    private string $user;
    private string $password;
    private int $uid;

    public function __construct()
    {
        $this->url = config('odoo.url');
        $this->db = config('odoo.db');
        $this->user = config('odoo.username');
        $this->password = config('odoo.password');

        $this->authenticate();
    }

    private function authenticate(): void
    {
        $client = new Client("{$this->url}/xmlrpc/2/common");

        $response = $client->send(new Request('authenticate', [
            new Value($this->db, 'string'),
            new Value($this->user, 'string'),
            new Value($this->password, 'string'),
            new Value([], 'struct'),
        ]));

        $this->uid = $response->value()->scalarval();
        if (!$this->uid) {
            throw new \Exception('Connexion Odoo impossible');
        }
    }

    public function createUser(array $data, string $role): int
    {
        $client = new Client("{$this->url}/xmlrpc/2/object");

        $groupXmlId = $role === 'agent'
            ? 'agent_tracking.group_agent_tracking_agent'
            : 'agent_tracking.group_agent_tracking_superviseur';

        $groupId = $this->getGroupId($groupXmlId);

        // Forcer l'encodage UTF-8
        $name = mb_convert_encoding($data['prenom'] . ' ' . $data['nom'], 'UTF-8', 'auto');
        $login = mb_convert_encoding($data['login'], 'UTF-8', 'auto');
        $email = mb_convert_encoding($data['email'], 'UTF-8', 'auto');
        $password = $data['motDePasse'];

        $response = $client->send(new Request('execute_kw', [
            new Value($this->db, 'string'),
            new Value($this->uid, 'int'),
            new Value($this->password, 'string'),
            new Value('res.users', 'string'),
            new Value('create', 'string'),
            new Value([[
                'name' => $name,
                'login' => $login,
                'email' => $email,
                'password' => $password,
                'groups_id' => [[6, 0, [$groupId]]],
                'active' => true,
            ]], 'array'),
        ]));

        $userId = $response->value();
        if ($userId instanceof Value) {
            $userId = $userId->scalarval();
        }

        return (int) $userId;
    }

    private function getGroupId(string $groupName): int
    {
        $client = new Client("{$this->url}/xmlrpc/2/object");

        $request = new Request('execute_kw', [
            new Value($this->db, 'string'),
            new Value($this->uid, 'int'),
            new Value($this->password, 'string'),
            new Value('res.groups', 'string'),
            new Value('search', 'string'),
            new Value([[
                new Value(['name', '=', $groupName], 'array')
            ]], 'array'),
        ]);

        $response = $client->send($request);

        if ($response->faultCode()) {
            throw new \Exception('Erreur Odoo : ' . $response->faultString());
        }

        $idsValue = $response->value();
        $idsArray = [];
        foreach ($idsValue as $idValue) {
            $idsArray[] = $idValue->scalarval();
        }

        if (empty($idsArray)) {
            throw new \Exception("Groupe Odoo introuvable : {$groupName}");
        }

        return (int) $idsArray[0];
    }
}
