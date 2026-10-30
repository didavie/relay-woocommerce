#!/usr/bin/env bash
# Builds build/relay-delivery-<version>.zip, ready to upload in WordPress (Plugins > Add New > Upload).
set -euo pipefail

cd "$(dirname "$0")/.."
version="$(sed -n 's/^ \* Version: *//p' relay-delivery/relay-delivery.php | tr -d '[:space:]')"
if [ -z "$version" ]; then
	echo "Could not read the plugin version." >&2
	exit 1
fi

mkdir -p build
rm -f "build/relay-delivery-${version}.zip"
zip -rq "build/relay-delivery-${version}.zip" relay-delivery -x '*.DS_Store'
echo "build/relay-delivery-${version}.zip"
