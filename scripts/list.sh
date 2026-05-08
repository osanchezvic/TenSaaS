#!/bin/bash

# =========================================
# LIST.SH - Listar empresas y servicios
# =========================================

set -euo pipefail

SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)
source "$SCRIPT_PATH/config.env"
source "$SCRIPT_PATH/funciones/logging.sh"
source "$SCRIPT_PATH/funciones/db.sh"

# Parámetros
FILTRO_EMPRESA="${1:-}"
FORMATO="${2:-table}"

# Validar directorio de BD
if [ ! -f "$DB_DIR/servicios.txt" ]; then
    echo_info "No hay servicios desplegados aún"
    exit 0
fi

# Función para obtener estado del contenedor
get_estado() {
    local empresa="$1"
    local servicio="$2"
    local container="${empresa}_${servicio}_1"
    
    if docker ps -q --filter "name=$container" 2>/dev/null | grep -q .; then
        echo "running"
    elif docker ps -qa --filter "name=$container" 2>/dev/null | grep -q .; then
        echo "stopped"
    else
        echo "missing"
    fi
}

echo_info "Servicios desplegados:"
echo ""

# Formato tabla
if [ "$FORMATO" = "table" ] || [ "$FORMATO" = "" ]; then
    echo "Empresa     Servicio           Puerto    Estado"
    echo "=========   ================   =======   ==========="
    
    while IFS=: read -r empresa servicio puerto status; do
        if [ -z "$FILTRO_EMPRESA" ] || [ "$FILTRO_EMPRESA" = "$empresa" ]; then
            estado=$(get_estado "$empresa" "$servicio")
            printf "%-11s %-18s %-9s %s\n" "$empresa" "$servicio" "$puerto" "$estado"
        fi
    done < "$DB_DIR/servicios.txt"

# Formato JSON
elif [ "$FORMATO" = "json" ]; then
    echo "["
    primera=1
    while IFS=: read -r empresa servicio puerto status; do
        if [ -z "$FILTRO_EMPRESA" ] || [ "$FILTRO_EMPRESA" = "$empresa" ]; then
            estado=$(get_estado "$empresa" "$servicio")
            if [ $primera -eq 0 ]; then
                echo ","
            fi
            cat <<EOF
    {
      "empresa": "$empresa",
      "servicio": "$servicio",
      "puerto": "$puerto",
      "estado": "$estado",
      "url": "http://localhost:$puerto"
    }
EOF
            primera=0
        fi
    done < "$DB_DIR/servicios.txt"
    echo ""
    echo "]"

# Formato CSV
elif [ "$FORMATO" = "csv" ]; then
    echo "empresa,servicio,puerto,estado,url"
    while IFS=: read -r empresa servicio puerto status; do
        if [ -z "$FILTRO_EMPRESA" ] || [ "$FILTRO_EMPRESA" = "$empresa" ]; then
            estado=$(get_estado "$empresa" "$servicio")
            echo "$empresa,$servicio,$puerto,$estado,http://localhost:$puerto"
        fi
    done < "$DB_DIR/servicios.txt"
fi

echo ""
echo "Resumen de empresas:"
echo ""
sort -u "$DB_DIR/empresas.txt" 2>/dev/null | while read empresa; do
    count=$(grep "^$empresa:" "$DB_DIR/servicios.txt" 2>/dev/null | wc -l)
    echo "  $empresa: $count servicio(s)"
done

echo ""

