# TCP depuis le navigateur (pont TCP)

Un navigateur ne sait pas ouvrir de socket TCP. Une application (un client SQL...) qui en a besoin utilise `$this->engine->tcp()` :
sur le bureau c'est un socket normal ; dans le navigateur c'est le **pont TCP** du playground (WebSocket vers TCP).

```php
$c = $this->engine->tcp()->connect('db.example.com', 3306);
$c->on('open', fn () => ...);
$c->on('data', fn (string $octets) => ...);
$c->on('close', fn (?string $raison) => ...);
$c->on('error', fn (string $message) => ...);   // refus, port interdit, pas d'autorisation...
$c->write($octets);
```

## Qui a le droit

- Le propriétaire de l'application demande l'accès sur la page du projet (studio, « Accès TCP »).
- L'administrateur (le compte GitHub `davidnurdin`) autorise ou refuse dans ses notifications / `Administration`. Il voit aussi tous les projets.
- L'autorisation vaut pour **un commit précis** : une application modifiée doit être autorisée de nouveau.
- Chaque application a une paire de clés Ed25519. La clé privée est gardée chiffrée sur le compte du propriétaire et signe les tickets ;
  la plateforme signe « slug | commit | clé publique » à l'autorisation. Le pont ne fait confiance qu'à la clé publique de la plateforme
  (`/api/tcp/public-key`) : il vérifie les deux signatures.
- Le ticket (2 minutes, une connexion) nomme l'hôte et le port. Ports permis : bases de données (3306, 3307, 33060, 5432, 1433, 1521, 6379, 27017, 11211, 8086, 9042).
  Adresses privées / réservées refusées après résolution du nom. Limites par adresse IP.

Le pont : `docker/tcpbridge` du dépôt jumphp-playground (service `tcpbridge`, réseau à part, route `/tcp` du Caddy).
`project.yaml` : `tcp_ticket_url` (défaut `https://jumphp.net/api/tcp/ticket`).

## À sauvegarder (administrateur du site)

- **Le droit d'administrateur ne se perd pas avec un fichier** : il est lié à ton compte GitHub (`User::ADMIN_GITHUB_ID` = 50957749, l'id,
  pas le login : un login renommé et repris par un autre ne donne rien). Garde l'accès à ce compte GitHub (2FA, codes de secours).
- **`TOKEN_KEY` (deploy/.env sur le serveur, `/mimichatPROD/jumphp/deploy/.env`) : à sauvegarder absolument, hors du serveur.** Elle sert à
  chiffrer les jetons GitHub et les clés privées des applications, et la clé de signature de la plateforme en est dérivée. Perdue ou changée :
  les jetons ne se déchiffrent plus (tout le monde doit se reconnecter), les clés des applications sont perdues et toutes les autorisations TCP
  deviennent invalides (à redemander et réautoriser).
- **La base de données** (`db`, volume `db`, table `tcp_grant` : état des demandes, clés publiques, clés privées chiffrées) : sauvegarde
  régulière (`mysqldump`). Sans elle, les autorisations sont à refaire.
- Le reste (`APP_SECRET`, `GITHUB_CLIENT_SECRET`, `DB_PASSWORD`...) est dans le même `deploy/.env`: sauvegarde-le en entier, jamais dans git.
