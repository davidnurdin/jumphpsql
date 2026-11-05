# jumphpsql

A MySQL / MariaDB client written in PHP with JumPHP (`JumPHP\Gui`: forms drawn by PHP, no HTML).

- saved connections (host, port, user, password saved or not, default database), tested before saving;
- a tree of the databases, their tables and views;
- the rows of a table: filter (WHERE), sort (click a header), pages of 200; edit cells in place, add and delete rows,
  then save them in one transaction (nothing is saved if one statement fails);
- the structure of a table (its columns, its CREATE TABLE);
- a SQL editor (colours, Ctrl+Return runs the statement under the cursor, F5 everything) and the rows of each result;
- SQL dumps of a database or a table, and imports of .sql files;
- optionally through an SSH tunnel (a bastion): with the session's key (ssh-agent), a key file (with its passphrase), a
  password, or what `~/.ssh/config` says. It uses the system's `ssh` client.

The MySQL protocol is spoken in pure PHP (no mysqli / PDO), without ever blocking the window. MySQL 5.7 / 8.x and
MariaDB: `mysql_native_password`, `caching_sha2_password`, `client_ed25519`.

```bash
bin/jumphp run jumphpsql
bin/jumphp test jumphpsql      # the tests that need a server use a throwaway one: see tests/TestServer.php
```
