#!/usr/bin/env bash
#
# Build and assemble a clean, distributable PaymentForm WordPress plugin zip.
#
# Pipeline:
#   1. Build the frontend (pnpm) -> emits $ROOT/assets/.
#   2. Stage the shipped source-of-truth in a throwaway temp dir.
#   3. Run `composer install --no-dev` in the stage so vendored deps are
#      production-only and re-prefixed (imposter) into $STAGE/vendor.
#   4. Prune dev-only files from vendor/, collect third-party licenses.
#   5. Zip the stage with a top-level `paymentform/` dir (the WP plugin slug).
#
# The repo's own vendor/ is never touched (dev deps such as PHPUnit stay intact).
#
# Usage: bin/package.sh [output-zip-path]
#   output-zip-path defaults to $ROOT/paymentform-<version>.zip
#
# Non-interactive, idempotent, CI-friendly.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Version comes from the canonical source: the plugin file header.
VERSION="$(awk '/^[[:space:]]*\*?[[:space:]]*Version:/ {print $NF; exit}' "$ROOT/paymentform.php")"
if [[ -z "$VERSION" ]]; then
    echo "package.sh: could not read 'Version:' from $ROOT/paymentform.php" >&2
    exit 1
fi

OUT="${1:-$ROOT/paymentform-$VERSION.zip}"
# Absolutize: the zip step runs from inside the stage dir.
mkdir -p "$(dirname "$OUT")"
OUT="$(cd "$(dirname "$OUT")" && pwd)/$(basename "$OUT")"

for tool in pnpm composer zip rsync; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "package.sh: required tool '$tool' is not installed" >&2
        exit 1
    fi
done

echo "==> Packaging PaymentForm v$VERSION"

echo "==> Building frontend"
pnpm --dir "$ROOT" install --frozen-lockfile
pnpm --dir "$ROOT" build

if [[ ! -d "$ROOT/assets" ]]; then
    echo "package.sh: expected build output at '$ROOT/assets' was not produced" >&2
    exit 1
fi

# Stage in a throwaway temp dir, never the repo's own vendor/.
STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/paymentform"
mkdir -p "$STAGE"

cleanup() { rm -rf "$STAGE_PARENT"; }
trap cleanup EXIT

echo "==> Staging shipped source-of-truth in $STAGE"
# Copy ONLY what ships; anything not listed is excluded. The frontend source and
# build config ship too: WordPress.org requires human-readable source for assets/.
SHIPPED=(
    assets
    backend
    paymentform.php
    readme.txt
    composer.json
    composer.lock
    frontend
    package.json
    pnpm-lock.yaml
    tsconfig.json
    vite.config.ts
)
for item in "${SHIPPED[@]}"; do
    if [[ ! -e "$ROOT/$item" ]]; then
        echo "package.sh: required artifact '$item' is missing from the repo" >&2
        exit 1
    fi
    rsync -a \
        --exclude=node_modules --exclude=e2e --exclude='__tests__' \
        --exclude='*.test.ts' --exclude='*.test.tsx' --exclude=vitest.setup.ts \
        --exclude=.env --exclude=port --exclude='.*' \
        "$ROOT/$item" "$STAGE/"
done

echo "==> Installing production composer dependencies in the stage"
# imposter (a composer-plugin) re-prefixes vendored namespaces into
# PaymentForm\Deps\ during this install.
composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --working-dir="$STAGE"

if [[ ! -f "$STAGE/vendor/autoload.php" ]]; then
    echo "package.sh: composer did not produce vendor/autoload.php in the stage" >&2
    exit 1
fi

echo "==> Pruning build-only and dev files from the stage vendor/"
# Prefixing is already baked into the autoloader and dep files, so imposter
# is dead weight at runtime. Delete the dirs only -- regenerating the
# autoloader now (without imposter) would undo the prefixing.
rm -rf "$STAGE/vendor/typisttech"
# WordPress.org rejects hidden files; tests and tool configs never run in production.
find "$STAGE/vendor" -mindepth 3 \( -type d \( -name '.*' -o -name tests -o -name test \) \) -prune -exec rm -rf {} +
find "$STAGE/vendor" -mindepth 3 -type f \( -name '.*' -o -name 'phpunit.xml*' -o -name 'phpcs.xml*' \
    -o -name rector.php -o -name captainhook.json -o -name lefthook.yml \) -delete

echo "==> Collecting third-party licenses"
LICENSES="$STAGE/licenses"
mkdir -p "$LICENSES"
composer licenses --no-dev --working-dir="$STAGE" > "$LICENSES/php.txt"
mv "$STAGE/assets/licenses/javascript.md" "$LICENSES/javascript.md"
rmdir "$STAGE/assets/licenses"

if find "$STAGE" -name '.*' -print -quit | grep -q .; then
    echo "package.sh: hidden files remain in the stage:" >&2
    find "$STAGE" -name '.*' >&2
    exit 1
fi

echo "==> Zipping artifact to $OUT"
# Top-level dir inside the zip MUST be `paymentform/` (the WP plugin slug).
rm -f "$OUT"
( cd "$STAGE_PARENT" && zip -rq "$OUT" paymentform )

echo "==> Done: $OUT"
