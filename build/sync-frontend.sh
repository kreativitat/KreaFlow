#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
MODULE_ROOT=$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)
APP_DIR="${MODULE_ROOT}/app"
FRONTEND_DIR="${MODULE_ROOT}/frontend"

if [ ! -f "${APP_DIR}/package.json" ]; then
  echo "Missing app/package.json" >&2
  exit 1
fi

cd "${APP_DIR}"

if [ ! -d "node_modules" ]; then
  npm install
fi

npm run build:module

echo "Frontend build published to ${FRONTEND_DIR}";
