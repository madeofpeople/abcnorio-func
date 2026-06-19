#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="${ROOT_DIR}/node_modules/abcnorio-webcomponents/dist"
DST_DIR="${ROOT_DIR}/resources/vendor/components/dist"
REQUIRED_FILES=(
  "manifest.json"
)

if [[ ! -d "${SRC_DIR}" ]]; then
  echo "Missing source dist directory: ${SRC_DIR}" >&2
  exit 1
fi

for relative_path in "${REQUIRED_FILES[@]}"; do
  if [[ ! -f "${SRC_DIR}/${relative_path}" ]]; then
    echo "Missing required source artifact: ${SRC_DIR}/${relative_path}" >&2
    exit 1
  fi
done

rm -rf "${DST_DIR}"
mkdir -p "$(dirname "${DST_DIR}")"
cp -R "${SRC_DIR}" "${DST_DIR}"

for relative_path in "${REQUIRED_FILES[@]}"; do
  if [[ ! -f "${DST_DIR}/${relative_path}" ]]; then
    echo "Missing required ingested artifact: ${DST_DIR}/${relative_path}" >&2
    exit 1
  fi
done

echo "Ingested components dist: ${SRC_DIR} -> ${DST_DIR}"
