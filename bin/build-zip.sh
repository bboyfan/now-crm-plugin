#!/usr/bin/env bash
#
# Build the distributable plugin ZIP: dist/now-crm-<version>.zip with a single
# top-level folder named now-crm/. Only runtime files are copied. Source, tests,
# docs, and build tooling stay in the public repository.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${PLUGIN_DIR}/dist"
VERSION="$(sed -n 's/^ \* Version: *//p' "${PLUGIN_DIR}/now-crm.php" | head -1)"

if [ -z "${VERSION}" ]; then
	echo "Cannot read the plugin version from now-crm.php" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

DEST="${STAGE}/now-crm"

PLUGIN_FILES=(
	now-crm.php
	readme.txt
	LICENSE
)

ASSET_FILES=(
	assets/css/admin.css
	assets/css/analytics.css
	assets/css/automation-editor.css
	assets/css/campaign-editor.css
	assets/css/cart-admin.css
	assets/css/contact-detail.css
	assets/css/contact-terms.css
	assets/css/form-workspace.css
	assets/css/order-panel.css
	assets/css/workflow-catalog.css
	assets/css/workflow-email.css
	assets/js/contact-detail.js
	assets/js/contact-list.js
	assets/js/contact-terms.js
	assets/js/workflow-catalog.js
	assets/js/workflow-email.js
)

BUILD_FILES=(
	assets/build/cart-track.js
	assets/build/cart-track.asset.php
	assets/build/campaign-editor.js
	assets/build/campaign-editor.asset.php
	assets/build/content-editor.js
	assets/build/content-editor.asset.php
)

for file in "${PLUGIN_FILES[@]}" "${ASSET_FILES[@]}"; do
	if [ ! -f "${PLUGIN_DIR}/${file}" ]; then
		echo "Missing release file: ${file}" >&2
		exit 1
	fi
done

for file in "${BUILD_FILES[@]}"; do
	if [ ! -f "${PLUGIN_DIR}/${file}" ]; then
		echo "Missing browser build: ${file}. Run 'npm ci && npm run build' first." >&2
		exit 1
	fi
done

copy_file() {
	local relative="$1"

	mkdir -p "${DEST}/$(dirname "${relative}")"
	cp "${PLUGIN_DIR}/${relative}" "${DEST}/${relative}"
}

mkdir -p "${DEST}" "${DIST_DIR}"

for file in "${PLUGIN_FILES[@]}" "${ASSET_FILES[@]}" "${BUILD_FILES[@]}"; do
	copy_file "${file}"
done

(
	cd "${PLUGIN_DIR}"
	find includes -type f -name '*.php' -print0
) | while IFS= read -r -d '' file; do
	copy_file "${file}"
done

ZIP="${DIST_DIR}/now-crm-${VERSION}.zip"

rm -f "${ZIP}"
(cd "${STAGE}" && zip -qr "${ZIP}" now-crm)

echo "${ZIP}"
unzip -l "${ZIP}" | tail -1
