# jumphpsql

A MySQL / MariaDB client: a graphic PHP app (`JumPHP\Gui` forms, no HTML; AGENTS.md §17 of JumPHP, `docs/gui.md`).
Desktop and browser: it opens its connections through `$engine->tcp()` (`JumPHP\Net\Tcp`: a socket on desktop, the playground's TCP bridge in the browser, which needs the administrator's approval of this exact version: docs/tcp.md). SSH tunnels: the system's `ssh` + a local port on desktop (`SshTunnel`); in the browser OpenSSH compiled to WebAssembly pipes the MySQL stream (`Endpoint::browserSsh()`, `$engine->ssh()->via(...)`, the application needs the SSH right too: docs/ssh-wasm.md; the key is pasted as text in the SSH key field, known hosts kept in `var/known_hosts`).

## Code

- `src/Db/MysqlConnection.php`: the client/server protocol in pure PHP, non-blocking: commands queued, answered by
  `Promise`s; `poll()` every frame moves the bytes; the protocol is written straight in a Fiber. `wait()` blocks: tests
  and scripts only. `ResultSet` (columns, rows as strings / null, summary), `MysqlError` (code 0 = the connection).
- `src/Db/Sql.php` (quoting, splitting scripts, the statement under the cursor), `ConnectionStore` (var/connections.json),
  `Dumper` / `Importer` (a connection of their own each, polled by TransferForm).
- `src/Gui/`: widgets of the app, used in the form files with `class:` — `TreeView`, `DataGrid` (editable, changes kept in
  `changes` / `rowStatus` until the form saves), `SqlEditor`, `MonoFont` (assets/fonts/mono.ttf drawn glyph by glyph).
- `src/Form/MainForm.php` (+ `forms/MainForm.form.yaml`): tree on the left (node ids `c|d|t` + `\x1f` + parts), pages Data /
  Structure / SQL. Every answer of the server comes back in a callback: requests of the data page carry `$generation`
  (stale answers dropped), the structure is checked against `tableKey()`. Saving: `changeStatements()` (UPDATE / DELETE by
  primary key, else by all the row's values, NULL-safe, LIMIT 1; INSERT of the cells typed) run one by one in a transaction,
  ROLLBACK at the first error. Paging / sort / filter refuse while changes are not saved.
- `src/Form/ConnectionForm.php` (dialog: Test, Save, Delete), `src/Form/TransferForm.php` (dialog: dump or import with a
  progress bar; the default dump file is `var/dumps/`). Dialogs close with `['dialog' => 'connection'|'transfer', ...]`,
  read in `MainForm::resume()`. File choosers only where `Native::supports()` (not in an SDL app): a path field otherwise.
- SSH tunnels: `src/Db/SshTunnel.php` runs the system's `ssh -N -L 127.0.0.1:<free port>:<db host>:<db port>` with proc_open
  (never waited for; ready when the local port answers; stderr gives the reason of a failure). sshAuth: `agent` (session key,
  SSH_AUTH_SOCK), `key` (sshKey + sshPassphrase), `password`, `auto` (ssh decides). Secrets go through the environment and a
  tiny SSH_ASKPASS script, never on the command line. Host keys: accept-new into var/known_hosts (+ ~/.ssh/known_hosts).
  In the container of bin/jumphp the uid has no passwd entry: `passwdWrapper()` gives ssh one with nss_wrapper.
  `src/Db/Endpoint.php` = tunnel + MysqlConnection (one per open connection in MainForm, also ConnectionForm's Test);
  `MainForm::transferConfig()` gives dumps / imports the tunnel's local port. project.yaml `ssh: true`: bin/jumphp run
  mounts the user's agent and ~/.ssh (read only).
- Dialogs close on Escape read from the keyboard, not on the `back` action (it is also Backspace).
- `src/Db` and `src/Gui` are excluded from the container (config/services.yaml), except ConnectionStore.

## Tests

`tests/SqlTest.php` (no server), `tests/MainFormTest.php` (game tests that play the window against a real server). They use
a throwaway server on port 3399, root / jumphp, on 127.0.0.1 or the Docker gateway 172.17.0.1 (bin/jumphp test runs in a
container without the host's network); skipped without it:

    docker run -d --name jumphpsql-mariadb -e MARIADB_ROOT_PASSWORD=jumphp -p 127.0.0.1:3399:3306 -p 172.17.0.1:3399:3306 mariadb:11

SSH tests (tests/SshTunnelTest.php, and the tunnel in MainFormTest): a throwaway sshd on port 2299 (user tunnel / tunnelpw,
keys made by the tests in var/tmp/ssh), the command in tests/TestServer.php (`PerSourcePenalties=no`: OpenSSH 10 penalises
connections that do not log in, like a port probe).

A game test's click on a widget of a page just made visible needs a frame first (`$this->game->run(2)`): its place is laid
out at the next frame. Smoke test: `demo: true` (the SQL page with a query typed, no server needed).
