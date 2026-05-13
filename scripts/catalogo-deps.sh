#!/bin/bash

# =========================================
# CATALOGO-DEPS.SH - Inspecciona el catalogo y exporta dependencias
# =========================================

set -euo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.."
CATALOGO_DIR="$BASE_DIR/catalogo"
OUT_FILE="$BASE_DIR/scripts/databases/catalogo-dependencias.txt"

mkdir -p "$BASE_DIR/scripts/databases"
: > "$OUT_FILE"

echo "Revisando catalogo en: $CATALOGO_DIR"

for svc_dir in "$CATALOGO_DIR"/*; do
    [ -d "$svc_dir" ] || continue
    svc_name="$(basename "$svc_dir")"
    cfg="$svc_dir/config.yml"
    
    if [ ! -f "$cfg" ]; then
        echo "[WARN] Servicio sin config.yml: $svc_name"
        continue
    fi
    
    deps=$(sed -n '/^dependencias:/,/^[^ ]/p' "$cfg" | grep "^  - " | sed 's/^  - //' || true)

    if [ -z "$deps" ]; then
        echo "[OK] $svc_name sin dependencias"
    else
        echo "[OK] $svc_name depende de:"
        while IFS= read -r dep; do
            [ -z "$dep" ] && continue
            echo "    - $dep"
        done <<< "$deps"
        
        echo "$svc_name:$(echo "$deps" | tr '\n' ',' | sed 's/,$//')" >> "$OUT_FILE"
    fi

done

if [ -s "$OUT_FILE" ]; then
    echo "Dependencias guardadas en: $OUT_FILE"
else
    echo "No hay dependencias definidas. Archivo vacío: $OUT_FILE"
fi
