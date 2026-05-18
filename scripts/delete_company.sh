#!/bin/bash

# =========================================
# DELETE_COMPANY.SH - Eliminar empresa completa
# =========================================

exec 200>/tmp/iaas_delete_company.lock
flock -n 200 || { echo "Otro proceso de eliminación está en ejecución"; exit 1; }

set -euo pipefail

SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)
source "$SCRIPT_PATH/config.env"
source "$SCRIPT_PATH/funciones/logging.sh"
source "$SCRIPT_PATH/funciones/db.sh"
source "$SCRIPT_PATH/funciones/utils.sh"

# Parámetros
EMPRESA="${1:-}"

# Iniciar log
init_log "$EMPRESA" "all" "delete_company"

if [ -z "$EMPRESA" ]; then
    echo_error "Parámetros insuficientes"
    echo_info "Uso: $(basename "$0") <empresa>"
    exit 1
fi

echo_info "Eliminando empresa completa: $EMPRESA"
echo ""

# Confirmar acción
if ! confirmar "¿Confirma eliminar COMPLETAMENTE la empresa $EMPRESA y todos sus servicios?"; then
    echo_warn "Operación cancelada"
    exit 0
fi

# 1. Identificar servicios de la empresa
SERVICIOS=$(grep "^$EMPRESA:" "$DB_DIR/servicios.txt" | cut -d':' -f2 || true)

# 2. Eliminar cada servicio usando destroy.sh
for SERVICIO in $SERVICIOS; do
    echo_info "Eliminando servicio: $SERVICIO"
    # Ejecutar destroy.sh en modo forzado para que no pida confirmación interna
    FORCE_MODE=1 "$SCRIPT_PATH/destroy.sh" "$EMPRESA" "$SERVICIO" || echo_warn "Error al eliminar servicio $SERVICIO, continuando..."
done

# 3. Eliminar directorio base de la empresa en data/
EMPRESA_DIR="$DATA_DIR/$EMPRESA"
if [ -d "$EMPRESA_DIR" ]; then
    echo_info "Eliminando directorio de empresa: $EMPRESA_DIR"
    rm -rf "$EMPRESA_DIR"
else
    echo_warn "Directorio de empresa no encontrado: $EMPRESA_DIR"
fi

# 4. Eliminar red de la empresa si existe
RED="${EMPRESA}_net"
if docker network inspect "$RED" >/dev/null 2>&1; then
    echo_info "Eliminando red: $RED"
    docker network rm "$RED" || echo_warn "No se pudo eliminar la red $RED"
fi

# 5. Limpiar registro de empresa en BD (txt)
if [ -f "$DB_DIR/empresas.txt" ]; then
    grep -v "^$EMPRESA$" "$DB_DIR/empresas.txt" > "$DB_DIR/empresas.txt.tmp" || true
    mv "$DB_DIR/empresas.txt.tmp" "$DB_DIR/empresas.txt"
    echo_info "Empresa eliminada del registro de empresas"
fi

# 6. Limpiar registro de servicios restantes en BD (txt)
if [ -f "$DB_DIR/servicios.txt" ]; then
    grep -v "^$EMPRESA:" "$DB_DIR/servicios.txt" > "$DB_DIR/servicios.txt.tmp" || true
    mv "$DB_DIR/servicios.txt.tmp" "$DB_DIR/servicios.txt"
    echo_info "Servicios de la empresa eliminados del registro de servicios"
fi

echo ""
echo_info "Empresa $EMPRESA eliminada correctamente del sistema"
