#!/bin/bash

# =========================================
# GET-CREDENTIALS.SH - Mostrar credenciales
# =========================================

set -euo pipefail

SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)
source "$SCRIPT_PATH/config.env"
source "$SCRIPT_PATH/funciones/logging.sh"
source "$SCRIPT_PATH/funciones/db.sh"
source "$SCRIPT_PATH/funciones/utils.sh"

# Parámetros
EMPRESA="${1:-}"
SERVICIO="${2:-}"

if [ -z "$EMPRESA" ] || [ -z "$SERVICIO" ]; then
    echo_error "Parámetros insuficientes"
    echo_info "Uso: $(basename "$0") <empresa> <servicio>"
    exit 1
fi

# Verificar que servicio existe
if ! servicio_existe "$EMPRESA" "$SERVICIO"; then
    echo_error "Servicio no encontrado: $EMPRESA/$SERVICIO"
    exit 1
fi

# Archivo de credenciales
CRED_FILE="$CREDENTIALS_DIR/${EMPRESA}.${SERVICIO}"

if [ ! -f "$CRED_FILE" ]; then
    echo_error "Credenciales no encontradas para $EMPRESA/$SERVICIO"
    echo_info "Archivo esperado: $CRED_FILE"
    exit 1
fi

echo_info "Credenciales de $EMPRESA/$SERVICIO:"
echo ""
echo "===================================================="

if command -v jq >/dev/null 2>&1; then
    jq -r 'to_entries | .[] | "\(.key): \(.value)"' "$CRED_FILE" | while read -r line; do
        key=$(echo "$line" | cut -d: -f1)
        val=$(echo "$line" | cut -d: -f2-)
        printf "%-20s : %s\n" "$key" "$val"
    done
else
    # Fallback si no hay jq (aunque deploy.sh lo requiere)
    cat "$CRED_FILE"
fi

echo "===================================================="
echo ""
echo_debug "Archivo: $CRED_FILE"
