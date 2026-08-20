#!/bin/bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO_ROOT/html"
DST="/var/www/html"

if [[ ! -d "$SRC" ]]; then
  echo "ERROR: HTML source directory not found: $SRC" >&2
  exit 1
fi

sudo mkdir -p "$DST"

# Copy the complete web tree, including nested script/, gatekeeper/, ajax/, etc.
# The old 'cp ../html/*.* /var/www/html' pattern copied only top-level files.
sudo cp -a "$SRC"/. "$DST"/

critical=(
  "$DST/script/managerAuth.php"
  "$DST/script/managerResend.php"
  "$DST/script/managerAi.php"
  "$DST/script/appSetup.php"
  "$DST/script/appInfection.php"
)

failed=0
for f in "${critical[@]}"; do
  if [[ -f "$f" ]]; then
    echo "[OK] $f"
  else
    echo "[MISSING] $f" >&2
    failed=1
  fi
done

if [[ $failed -ne 0 ]]; then
  echo "ERROR: one or more critical TaraSec web endpoints were not deployed." >&2
  exit 1
fi

echo "TaraSec web tree deployed recursively to $DST."
