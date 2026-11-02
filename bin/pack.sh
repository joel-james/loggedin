#!/usr/bin/env bash
#
# Build a WordPress.org-ready ZIP of the Loggedin plugin.
#
# Steps:
#   1. Verify Version: header / package.json / Stable tag all match.
#   2. Generate the POT file and audit text-domains.
#   3. Build front-end assets.
#   4. Install production-only Composer deps.
#   5. Stage only runtime files and zip them into ./releases/.

set -euo pipefail

SLUG="loggedin"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT/$SLUG.php"
README_FILE="$ROOT/readme.txt"
PKG_FILE="$ROOT/package.json"
DIST_DIR="$ROOT/releases"

# Files/folders that land inside the ZIP. Anything not listed here is excluded.
# composer.json ships alongside vendor/ so the bundled dependencies are
# attributable — Plugin Check flags a vendor tree without it.
INCLUDES=(
	"$SLUG.php"
	"uninstall.php"
	"readme.txt"
	"license"
	"composer.json"
	"src"
	"languages"
	"build"
	"vendor"
)

i18n_log=""
stage=""
restore_dev_deps=false
cleanup() {
	[[ -n "$i18n_log" ]] && rm -f "$i18n_log"
	[[ -n "$stage" ]] && rm -rf "$stage"

	# CI runners are ephemeral, so restoring dev deps is pure waste there.
	# Export `LOGGEDIN_PACK_SKIP_RESTORE=1` from the workflow to opt out.
	if [[ "$restore_dev_deps" == true && "${LOGGEDIN_PACK_SKIP_RESTORE:-0}" != "1" ]]; then
		printf '\n==> Restoring development dependencies\n'
		composer install --quiet
	fi
}
trap cleanup EXIT

log()  { printf '\n==> %s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

command -v wp       >/dev/null 2>&1 || fail "WP-CLI ('wp') is required. See https://wp-cli.org/"
command -v zip      >/dev/null 2>&1 || fail "'zip' is required."
command -v composer >/dev/null 2>&1 || fail "'composer' is required. See https://getcomposer.org/"

cd "$ROOT"

#
# 1. Version consistency.
#
log "Checking version consistency"

plugin_version=$(grep -E '^[[:space:]]*\*?[[:space:]]*Version:' "$PLUGIN_FILE" \
	| head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')
pkg_version=$(node -p "require('$PKG_FILE').version")
readme_version=$(grep -E '^Stable tag:' "$README_FILE" \
	| head -1 | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '\r')

printf '    plugin header: %s\n' "$plugin_version"
printf '    package.json:  %s\n' "$pkg_version"
printf '    readme.txt:    %s\n' "$readme_version"

if [[ "$plugin_version" != "$pkg_version" || "$plugin_version" != "$readme_version" ]]; then
	fail "Version mismatch — sync all three values before packing."
fi

VERSION="$plugin_version"

#
# 2. POT + text-domain audit.
#
log "Generating POT and auditing text-domains"

mkdir -p "$ROOT/languages"
i18n_log="$(mktemp)"

wp i18n make-pot "$ROOT" "$ROOT/languages/$SLUG.pot" \
	--slug="$SLUG" \
	--domain="$SLUG" \
	--exclude="node_modules,vendor,tests,bin,build,releases" \
	2>&1 | tee "$i18n_log"

if grep -qiE 'different text domain|wrong text domain|mismatched text' "$i18n_log"; then
	fail "Text-domain mismatches reported above."
fi

#
# 3. Build assets.
#
log "Building assets"
npm run build

#
# 4. Install production-only Composer dependencies.
#
# Dev deps are restored by the EXIT trap once packing finishes (or fails).
#
log "Installing production Composer dependencies"
restore_dev_deps=true
composer install --no-dev --optimize-autoloader --classmap-authoritative --quiet

#
# 5. Stage and zip.
#
log "Packing $SLUG-$VERSION.zip"

mkdir -p "$DIST_DIR"
zip_path="$DIST_DIR/$SLUG-$VERSION.zip"
rm -f "$zip_path"

stage="$(mktemp -d)"
plugin_stage="$stage/$SLUG"
mkdir -p "$plugin_stage"

for item in "${INCLUDES[@]}"; do
	if [[ -e "$ROOT/$item" ]]; then
		cp -R "$ROOT/$item" "$plugin_stage/"
	else
		printf 'WARNING: %s not found, skipping\n' "$item" >&2
	fi
done

# Strip OS noise that may have crept into the staged copy.
find "$plugin_stage" -name '.DS_Store' -delete

# Drop compiled/source translation catalogues. The plugin no longer calls
# `load_plugin_textdomain()`, so WordPress loads language packs from
# wp-content/languages/plugins/ and a bundled .mo would never be read.
# The .pot stays — it documents the string set for translators.
find "$plugin_stage/languages" -type f \( -name '*.mo' -o -name '*.po' \) -delete 2>/dev/null || true

# Prune dev junk from each vendor package. Conservative list — LICENSE,
# composer.json, README and anything under src/ stays. Removes CI
# configs, test fixtures, dev-tool configs and editor metadata that have
# no runtime use and just bloat the ZIP.
if [[ -d "$plugin_stage/vendor" ]]; then
	log "Pruning dev junk from staged vendor tree"

	find "$plugin_stage/vendor" -type d \( \
		-name '.github' -o \
		-name 'tests' -o \
		-name 'Tests' -o \
		-name 'test' -o \
		-name 'docs' -o \
		-name 'examples' \
	\) -prune -exec rm -rf {} +

	find "$plugin_stage/vendor" -type f \( \
		-name '.gitignore' -o \
		-name '.gitattributes' -o \
		-name '.editorconfig' -o \
		-name '.travis.yml' -o \
		-name '.scrutinizer.yml' -o \
		-name 'phpcs*.xml*' -o \
		-name 'phpunit*.xml*' -o \
		-name 'psalm*.xml*' -o \
		-name 'phpstan*.neon*' -o \
		-name 'Makefile' \
	\) -delete
fi

(cd "$stage" && zip -rq "$zip_path" "$SLUG")

printf '\nPacked: %s\n' "$zip_path"
ls -lh "$zip_path"
