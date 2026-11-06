#!/bin/sh
# Run in the OpenSSH source folder. A browser has no tty: the passphrase / password the page was given (JUMPHP_SSH_SECRET in the
# module's environment) answers every prompt of read_passphrase().
set -e
perl -0pi -e 's/(read_passphrase\(const char \*prompt, int flags\)\n\{\n)/$1\t{\n\t\tconst char *jh = getenv("JUMPHP_SSH_SECRET");\n\t\tif (jh != NULL)\n\t\t\treturn xstrdup(jh);\n\t}\n/' readpass.c
grep -q JUMPHP_SSH_SECRET readpass.c
