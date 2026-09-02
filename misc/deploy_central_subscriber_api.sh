#!/bin/bash
set -euo pipefail

if [ "$#" -ne 1 ]; then
    echo "Usage: sudo bash misc/deploy_central_subscriber_api.sh /absolute/website/root" >&2
    exit 2
fi

WEB_ROOT="$1"
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ ! -d "$WEB_ROOT" ] || [ "${WEB_ROOT:0:1}" != "/" ]; then
    echo "ERROR: website root must be an existing absolute directory." >&2
    exit 1
fi

IDENTITY_DIR="$WEB_ROOT/api/v1/identity"
SUBSCRIBER_DIR="$WEB_ROOT/api/v1/subscriber"
install -d -m 0755 "$IDENTITY_DIR" "$SUBSCRIBER_DIR"

for file in identity-common.php identity-start.php identity-callback.php identity-exchange.php; do
    install -m 0644 "$SOURCE_ROOT/hotspot/opennds/$file" "$IDENTITY_DIR/$file"
done
for file in subscriber-api-common.php subscriber-login.php subscriber-account.php subscriber-credit-draw.php payment-service-client.php accounting-api.php; do
    install -m 0644 "$SOURCE_ROOT/hotspot/opennds/$file" "$SUBSCRIBER_DIR/$file"
done

echo "Central TaraSec subscriber API deployed under $WEB_ROOT/api/v1."
echo "The node-local $WEB_ROOT/hotspot directory was not modified."
