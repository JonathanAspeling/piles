#!/usr/bin/env bash
# Plays a full game end to end and screenshots it (tests/e2e/screenshots/).
#
# Starts its own compose project ("piles-e2e", separate database volume) built
# from the working tree, and a Playwright browser server in Docker — the
# official image, so the host OS doesn't need Playwright's system packages.
# Inside that container a tiny forwarder maps localhost:8000/:8080 to the host's
# published ports, since the built assets dial localhost.
# Extra arguments go to `playwright test`, e.g. `npm run e2e -- --project=dark`.
set -euo pipefail
cd "$(dirname "$0")/.."

PLAYWRIGHT_VERSION="$(node -p "require('@playwright/test/package.json').version")"
BROWSER_CONTAINER=piles-e2e-browser
BROWSER_PORT=3123

compose() { docker compose -p piles-e2e --env-file tests/e2e/e2e.env "$@"; }

cleanup() { docker rm -f "$BROWSER_CONTAINER" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "› Building and starting the e2e stack…"
compose up --build -d --wait

echo "› Starting the Playwright $PLAYWRIGHT_VERSION browser server…"
cleanup
docker run -d --rm --init --name "$BROWSER_CONTAINER" \
    -p "${BROWSER_PORT}:${BROWSER_PORT}" --add-host host.docker.internal:host-gateway \
    -v "$PWD/tests/e2e/support/forward-ports.mjs:/forward-ports.mjs:ro" \
    "mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble" \
    sh -c "node /forward-ports.mjs & exec npx -y playwright@${PLAYWRIGHT_VERSION} run-server --port ${BROWSER_PORT} --host 0.0.0.0" >/dev/null

for _ in $(seq 1 60); do
    if docker logs "$BROWSER_CONTAINER" 2>&1 | grep -q "Listening"; then
        break
    fi
    sleep 1
done

rm -rf tests/e2e/screenshots
PW_WS_ENDPOINT="ws://localhost:${BROWSER_PORT}/" npx playwright test "$@"

echo "› Screenshots are in tests/e2e/screenshots/. Stop the stack with: docker compose -p piles-e2e down"
