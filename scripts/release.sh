#!/usr/bin/env bash
# Release helper for graystackit/laravel-mollie-billing.
#
# Usage:
#   scripts/release.sh [patch|minor|major]
#
# Defaults to "patch". The script:
#   1. Reads the latest semver tag (vX.Y.Z) from git and bumps it.
#   2. Rewrites the leading "## [Unreleased]" block in CHANGELOG.md to
#      "## [X.Y.Z] - YYYY-MM-DD" and inserts a fresh empty "## [Unreleased]"
#      block above it. Aborts if the Unreleased block has no entries.
#   3. Commits the CHANGELOG change, creates an annotated tag, and pushes
#      both the branch and the tag to origin.

set -euo pipefail

BUMP="${1:-patch}"
case "$BUMP" in
    patch|minor|major) ;;
    *)
        echo "error: unknown bump '$BUMP' (expected: patch, minor, major)" >&2
        exit 1
        ;;
esac

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

CHANGELOG="CHANGELOG.md"

if [[ ! -f "$CHANGELOG" ]]; then
    echo "error: $CHANGELOG not found in $REPO_ROOT" >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "error: working tree is not clean — commit or stash changes first" >&2
    git status --short >&2
    exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" != "main" ]]; then
    echo "error: must be on 'main' to release (currently on '$BRANCH')" >&2
    exit 1
fi

echo "→ fetching tags from origin"
git fetch --tags origin >/dev/null

LATEST_TAG="$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n1 || true)"
if [[ -z "$LATEST_TAG" ]]; then
    CURRENT="0.0.0"
else
    CURRENT="${LATEST_TAG#v}"
fi

IFS='.' read -r MAJOR MINOR PATCH <<<"$CURRENT"

case "$BUMP" in
    patch) PATCH=$((PATCH + 1)) ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"
NEW_TAG="v${NEW_VERSION}"
TODAY="$(date +%Y-%m-%d)"

if git rev-parse "$NEW_TAG" >/dev/null 2>&1; then
    echo "error: tag $NEW_TAG already exists" >&2
    exit 1
fi

echo "→ bumping ${CURRENT} → ${NEW_VERSION} (${BUMP})"

# Extract the body between "## [Unreleased]" and the next "## [" heading,
# fail if there are no real entries (only whitespace).
UNRELEASED_BODY="$(awk '
    /^## \[Unreleased\]/ { capture = 1; next }
    capture && /^## \[/ { exit }
    capture { print }
' "$CHANGELOG")"

if ! grep -qE '\S' <<<"$UNRELEASED_BODY"; then
    echo "error: [Unreleased] block in $CHANGELOG is empty — nothing to release" >&2
    exit 1
fi

# Replace the FIRST "## [Unreleased]" line with a fresh Unreleased block
# followed by the new dated heading. Awk handles this with a single pass so
# we don't depend on GNU vs BSD sed differences.
TMP="$(mktemp)"
awk -v new_heading="## [${NEW_VERSION}] - ${TODAY}" '
    !done && /^## \[Unreleased\]/ {
        print "## [Unreleased]"
        print ""
        print new_heading
        done = 1
        next
    }
    { print }
' "$CHANGELOG" >"$TMP"
mv "$TMP" "$CHANGELOG"

echo "→ committing CHANGELOG"
git add "$CHANGELOG"
git commit -m "chore: release ${NEW_VERSION}"

echo "→ creating tag ${NEW_TAG}"
git tag -a "$NEW_TAG" -m "Release ${NEW_VERSION}"

echo "→ pushing main and ${NEW_TAG} to origin"
git push origin "$BRANCH"
git push origin "$NEW_TAG"

echo "✓ released ${NEW_TAG}"
