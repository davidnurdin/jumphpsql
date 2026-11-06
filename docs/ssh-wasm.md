# Le client SSH d'OpenSSH en WebAssembly

Dans le navigateur, une application (un client SQL...) peut passer par un serveur SSH : `$this->engine->ssh()`.

```php
$ssh = $this->engine->ssh();
// un flux vers db.interne:3306 à travers le bastion (ssh -W) : mêmes événements que $engine->tcp()
$c = $ssh->connect('db.interne', 3306, ['sshHost' => 'bastion.exemple.com', 'sshUser' => 'moi', 'sshKey' => $cléPrivéeEnTexte]);
$c->on('data', fn (string $octets) => ...);  $c->write($octets);  $c->on('close', fn (?string $raison) => ...);
// pour ce qui attend un Tcp (MysqlConnection['tcp'] de jumphpsql) : $ssh->via($options) a le même connect($host, $port, $timeout)
```

Options : `sshHost`, `sshPort` (22), `sshUser`, `sshKey` (navigateur : le texte de la clé privée ; bureau : un fichier), `sshPassphrase`,
`sshPassword`, `knownHosts` (texte known_hosts ; l'événement `knownHosts` rend le fichier après la session pour qu'on le garde),
`sshOptions` (valeurs `-o` en plus, par exemple `StrictHostKeyChecking=yes`).

## Comment ça marche (deux wasm reliés par un tuyau)

1. Le PHP de l'application (WebAssembly) envoie au JS de la page `{t: 'ssh', op: 'open', ...}` par `JsBridge`.
2. La page (`engine/web/jumphp-ssh.js`) lance un **Web Worker** (`jumphp-ssh-worker.js`) qui charge `ssh.wasm`, **le vrai client `ssh` d'OpenSSH 9.9**
   compilé avec emscripten (OpenSSL 3 pour libcrypto, zlib) : `ssh -W hôte:port bastion`, dont stdin / stdout sont le tuyau.
3. stdin et stdout sont deux périphériques fournis par le worker ; les octets du PHP y arrivent (base64 par le pont de la page) et ceux de
   stdout repartent vers le PHP. Le réseau de ssh (vers le bastion) est un WebSocket brut vers le **pont TCP** (`wss://.../tcp?raw=1&ticket=...`).
4. Un navigateur n'a ni fork, ni tty, ni agent : `poll()` attend l'événement suivant au lieu de répondre tout de suite (Asyncify,
   `docker/ssh-wasm/poll.js`), `getpwuid` a un utilisateur fixe, la phrase secrète ou le mot de passe de l'application répond aux demandes
   (variable `JUMPHP_SSH_SECRET`, patch de `readpass.c`), la clé est un fichier dans la mémoire du module (`~/.ssh/id`), jamais envoyée au serveur.

Sur le bureau, `$engine->ssh()` lance le programme `ssh` du système (`ssh -W`, ~/.ssh, agent) avec les mêmes événements.

## Autorisation

L'application doit avoir le droit **SSH** (distinct du droit TCP) : la demande se fait sur la page du projet, l'administrateur l'autorise pour la version
exacte du code (docs/tcp.md). Le ticket du pont a la portée `ssh` : n'importe quel port d'une adresse publique (les adresses privées sont refusées),
alors qu'un ticket `tcp` ne vaut que pour des ports de bases de données. Statistiques, coupure et blocage : l'Administration du playground.

## Compiler

`bin/jumphp ssh:build` (Docker, quelques minutes la première fois) : `build/ssh/ssh.js` + `ssh.wasm` (3,8 Mo), copiés dans le runtime des jeux distants
(`runtime:build`) et dans les builds web. Sources : `docker/ssh-wasm/` (`Dockerfile`, `patch-openssh.sh`, `stubs.c`, `poll.js`, `link.sh`).
