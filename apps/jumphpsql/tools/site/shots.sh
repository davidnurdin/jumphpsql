#!/usr/bin/env bash
# The pictures of the web site (site/images/): screenshots of the real window against the throwaway test server
# (tests/TestServer.php: start it first), then resized / og.png / favicon. Run from the project root:
#   apps/jumphpsql/tools/site/shots.sh
set -euo pipefail
app="$(cd "$(dirname "$0")/../.." && pwd)"
root="$(cd "$app/../.." && pwd)"
"$root/bin/jumphp" test jumphpsql tools/site/SiteShotsTest.php
python3 -I "$app/tools/site/images.py" "$app"
python3 -I "$app/tools/site/page.py" "$app"
