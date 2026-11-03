#!/usr/bin/env bash
#
# Build a release zip with only the files Vaka needs to run.
# Usage: scripts/build-release.sh <version> [<git ref>]
# Example: scripts/build-release.sh 0.1.0
#
# The files come from git (the ref, HEAD by default), never from the working
# tree, so a local .env, users.json or database can never end up in the zip.

set -euo pipefail

VERSION="${1:-}"
VERSION="${VERSION#v}"
REF="${2:-HEAD}"

if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version> [<git ref>]"
    echo "Example: $0 0.1.0"
    exit 1
fi
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "\"$VERSION\" is not a version like 0.1.0 or 0.2.0-beta.1."
    exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$ROOT/build"
NAME="vaka-$VERSION"
ZIP_FILE="$BUILD_DIR/$NAME.zip"

# What a running Vaka needs, and the docs for setting it up
FILES=(
    vaka config.php .env.example src public
    LICENSE README.md CHANGELOG.md
    docs/install.md docs/configuration.md docs/checks.md docs/alerts.md docs/api.md
)

# The version in the code must be the one being released
CODE_VERSION=$(git -C "$ROOT" show "$REF:src/bootstrap.php" | sed -n "s/^const VAKA_VERSION = '\(.*\)';/\1/p")
if [ "$CODE_VERSION" != "$VERSION" ]; then
    echo "src/bootstrap.php says VAKA_VERSION = '$CODE_VERSION', not '$VERSION'."
    echo "Set it to '$VERSION' and give CHANGELOG.md a [$VERSION] section before releasing."
    exit 1
fi
# grep reads it all: with -q it stops at the match, and with pipefail the writer's SIGPIPE would count as no match
if ! git -C "$ROOT" show "$REF:CHANGELOG.md" | grep -F "## [$VERSION]" > /dev/null; then
    echo "CHANGELOG.md has no [$VERSION] section: the release notes come from it."
    echo "Rename [Unreleased] to [$VERSION] - $(date +%Y-%m-%d) and start a new empty [Unreleased]."
    exit 1
fi

if [ -n "$(git -C "$ROOT" status --porcelain -- "${FILES[@]}")" ]; then
    echo "Note: uncommitted changes are not in the zip, which is built from $REF."
fi

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
git -C "$ROOT" archive --format=tar --prefix="$NAME/" "$REF" -- "${FILES[@]}" | tar -x -C "$BUILD_DIR"

# A second line of defence: nothing private or for development only
LEAKED=$(cd "$BUILD_DIR/$NAME" && find . \( -name '.env' -o -name 'users.json' -o -name '*.sqlite*' \
    -o -path './data' -o -path './tests' -o -path './scripts' -o -path './.claude' -o -path './.private' \
    -o -path './.github' -o -path './vendor' \) -print)
if [ -n "$LEAKED" ]; then
    echo "Stopped: the release would contain files it must not:"
    echo "$LEAKED"
    exit 1
fi

(cd "$BUILD_DIR" && zip -qrX "$ZIP_FILE" "$NAME")

echo "Built $ZIP_FILE"
echo "  from $(git -C "$ROOT" rev-parse --short "$REF") ($REF), $(cd "$BUILD_DIR/$NAME" && find . -type f | wc -l | tr -d ' ') files, $(du -h "$ZIP_FILE" | cut -f1 | tr -d ' ')"
echo ""
echo "Try it: cd build/$NAME && php vaka version"
