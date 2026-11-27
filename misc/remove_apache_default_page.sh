#!/bin/bash
# Remove the web server package's default page from the TaraSec document root.
# Apache serves index.html before index.php, so the stock "It works" page hides
# TaraSec's start page. Only that stock page is removed; a site's own index.html
# is left alone.
set -euo pipefail
docroot="${1:-/var/www/html}"
page="$docroot/index.html"
[[ -f $page && ! -L $page ]] || exit 0
[[ -f $docroot/index.php ]] || exit 0
if grep -qE '<title>(Apache2 (Ubuntu|Debian) Default Page|Welcome to nginx!)' "$page"; then
    rm -f -- "$page"
    echo "Removed the web server's default page $page."
fi
