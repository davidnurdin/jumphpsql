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

## Statistiques, coupure, serveur indépendant

- **Administration** (`/studio/admin`, section « Pont TCP ») : connexions ouvertes (application, cible, adresse, durée, octets, débit),
  totaux depuis le démarrage (connexions, refus, coupures, ↑ ↓), totaux par application et par adresse, journal. Boutons :
  **Couper** une connexion, **Bloquer** l'application ou l'adresse pour une heure, **Débloquer**. Les chiffres sont gardés en mémoire
  par le pont (un redémarrage repart de zéro). Un blocage définitif = refuser l'autorisation de l'application (plus de tickets).
- Le pont tient ces chiffres et répond à `/admin/stats|kill|block|unblock` (en-tête `X-Admin-Secret`, secret partagé
  `TCP_ADMIN_SECRET` dans `deploy/.env`). Caddy ne transmet au pont que `/tcp` : l'administration n'est pas publique.
- **Serveur indépendant** : le pont est un seul fichier PHP (`docker/tcpbridge/` du dépôt jumphp-playground, sans dépendance). Sur un autre
  serveur : `docker compose up -d --build` avec `docker/tcpbridge/compose.yaml` (le pont + Caddy pour le certificat `wss://`),
  `.env` : `BRIDGE_HOST`, `PLAYGROUND_URL`, `TCP_ADMIN_SECRET`. Sur le playground (`deploy/.env`) : `TCP_BRIDGE_PUBLIC_URL=wss://<hôte>/tcp`
  (l'adresse que les navigateurs reçoivent avec leur ticket), `TCP_BRIDGE_ADMIN_URL=https://<hôte>`, et le même `TCP_ADMIN_SECRET`.
  Le pont ne fait confiance qu'à la clé publique du playground (`/api/tcp/public-key`) : il n'a aucun secret. Plusieurs ponts possibles
  (un par région) avec le même `PLAYGROUND_URL`.
- À faire (TODO.md) : encapsuler aussi UDP et ICMP.

## Blocages durables, quota, alertes

- Les blocages et les totaux du pont survivent à un redémarrage (`TCP_STATE_FILE`, un volume : `/data/state.json`).
- **Quota de bande passante** par application : `TCP_QUOTA_MB_PER_HOUR` (2048 Mo par heure par défaut sur le playground, 0 = aucun). Dépassé :
  l'application est bloquée 15 minutes et l'administrateur est prévenu : le pont appelle `TCP_ALERT_URL` (`/api/tcp/alert` du playground, avec le
  secret partagé), la cloche s'allume et la page Administration liste les alertes.
