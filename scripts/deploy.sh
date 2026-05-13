#!/bin/bash
set -euo pipefail

# =====================================================
# DEPLOY DE SERVICIOS - VERSION 2.3 (MULTI-TENANT FIX)
# =====================================================

# Cargar configuración
SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)
source "$SCRIPT_PATH/config.env"
source "$SCRIPT_PATH/funciones/logging.sh"
source "$SCRIPT_PATH/funciones/db.sh"
source "$SCRIPT_PATH/funciones/puertos.sh"
source "$SCRIPT_PATH/funciones/utils.sh"
source "$SCRIPT_PATH/funciones/validaciones.sh"
source "$SCRIPT_PATH/funciones/seguridad.sh"

# =====================================================
# INICIO Y BLOQUEO
# =====================================================
if [ "${SKIP_LOCK:-false}" != "true" ]; then
    exec 200>/tmp/iaas_deploy.lock
    flock -n 200 || { echo "Otro proceso de deploy está en ejecución"; exit 1; }
fi

# Parámetros
EMPRESA="${1:-}"
SERVICIO="${2:-}"

# Iniciar log
init_log "$EMPRESA" "$SERVICIO" "deploy"
LOG_FILE="$LOG_DIR/${EMPRESA}_${SERVICIO}_$(date +%Y%m%d).log"

# =====================================================
# FUNCIÓN: PROCESAR TEMPLATES
# =====================================================

procesar_template() {
    local tpl="$1" dest="$2"
    [ -f "$tpl" ] || return 1

    sed -e "s|{{EMPRESA}}|$EMPRESA|g" \
        -e "s|{{SERVICIO}}|$SERVICIO|g" \
        -e "s|{{PUERTO}}|$PUERTO|g" \
        -e "s|{{RUTA_DATOS}}|$DOCKER_DATA_DIR/$EMPRESA|g" \
        -e "s|{{DB_NAME}}|$DB_NAME|g" \
        -e "s|{{DB_USER}}|$DB_USER|g" \
        -e "s|{{DB_PASSWORD}}|$DB_PASSWORD|g" \
        -e "s|{{DB_ROOT_PASSWORD}}|$DB_ROOT_PASSWORD|g" \
        -e "s|{{ADMIN_USER}}|$ADMIN_USER|g" \
        -e "s|{{ADMIN_PASSWORD}}|$ADMIN_PASSWORD|g" \
        -e "s|{{JWT_SECRET}}|$JWT_SECRET|g" \
        "$tpl" > "$dest"
}

# =====================================================
# VALIDACIONES DE ENTRADA
# =====================================================

if [ -z "$EMPRESA" ] || [ -z "$SERVICIO" ]; then
    log_error "Uso: $(basename "$0") <empresa> <servicio>"
    exit 1
fi

log_info "Iniciando deploy de $EMPRESA/$SERVICIO"

if ! validar_pre_deploy "$EMPRESA" "$SERVICIO"; then
    log_failed "Validaciones pre-deploy fallidas"
    exit 1
fi

# =====================================================
# COMPROBACIÓN DE EXISTENCIA
# =====================================================

SERVICIO_DIR="$DATA_DIR/$EMPRESA/$SERVICIO"
COMPOSE_FILE="$SERVICIO_DIR/docker-compose.yml"

if [ -f "$COMPOSE_FILE" ]; then
    log_warn "Servicio ya existe para $EMPRESA/$SERVICIO — levantando si es necesario..."
    docker compose -f "$COMPOSE_FILE" up -d
    # Seguir adelante para asegurar registro en DB y Proxy
fi

# =====================================================
# PREPARAR DIRECTORIO
# =====================================================

log_info "Preparando entorno..."
mkdir -p "$SERVICIO_DIR"

# =====================================================
# FUNCIÓN: CARGAR O GENERAR CREDENCIALES
# =====================================================

cargar_o_generar_credenciales() {
    local empresa="$1"
    local servicio="$2"
    local cred_file="$CREDENTIALS_DIR/${empresa}.${servicio}"
    
    if [ -f "$cred_file" ]; then
        log_info "Cargando credenciales existentes: $cred_file"
        local json=$(cat "$cred_file")
        DB_NAME=$(echo "$json" | jq -r .db_name)
        DB_USER=$(echo "$json" | jq -r .db_user)
        DB_PASSWORD=$(echo "$json" | jq -r .db_password)
        DB_ROOT_PASSWORD=$(echo "$json" | jq -r .db_root_password)
        ADMIN_USER=$(echo "$json" | jq -r .admin_user)
        ADMIN_PASSWORD=$(echo "$json" | jq -r .admin_password)
        JWT_SECRET=$(echo "$json" | jq -r .jwt_secret)
        PUERTO=$(echo "$json" | jq -r .puerto)
        return 0
    fi
    
    log_info "Generando nuevas credenciales..."
    DB_NAME="${EMPRESA}_db"
    DB_USER="${EMPRESA}_user"
    DB_PASSWORD=$(generar_password 16)
    DB_ROOT_PASSWORD=$(generar_password 16)
    ADMIN_USER="admin_${EMPRESA}" # Fix collision
    ADMIN_PASSWORD=$(generar_password 16)
    JWT_SECRET=$(generar_token 32)
    PUERTO=$(asignar_puerto "$EMPRESA" "$SERVICIO" "dev")
}

# =====================================================
# GENERAR CREDENCIALES Y VALORES
# =====================================================

cargar_o_generar_credenciales "$EMPRESA" "$SERVICIO"

if [ -z "$PUERTO" ]; then
    log_failed "No se pudo asignar puerto"
    exit 1
fi

# =====================================================
# GUARDAR CREDENCIALES (JSON)
# =====================================================

CREDENCIALES_JSON=$(jq -n \
    --arg db_name     "$DB_NAME" \
    --arg db_user     "$DB_USER" \
    --arg db_pass     "$DB_PASSWORD" \
    --arg db_root_pass "$DB_ROOT_PASSWORD" \
    --arg admin_user  "$ADMIN_USER" \
    --arg admin_pass  "$ADMIN_PASSWORD" \
    --arg jwt_secret  "$JWT_SECRET" \
    --arg puerto      "$PUERTO" \
    --arg timestamp   "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    '{
        db_name:        $db_name,
        db_user:        $db_user,
        db_password:    $db_pass,
        db_root_password: $db_root_pass,
        admin_user:     $admin_user,
        admin_password: $admin_pass,
        jwt_secret:     $jwt_secret,
        puerto:         $puerto,
        created_at:     $timestamp
    }')

CRED_FILE=$(guardar_credenciales "$EMPRESA" "$SERVICIO" "$CREDENCIALES_JSON")

# =====================================================
# GENERAR ARCHIVOS DESDE TEMPLATES
# =====================================================

log_info "Procesando templates..."
CATALOGO_SERVICIO="$CATALOGO_DIR/$SERVICIO"

if ! procesar_template "$CATALOGO_SERVICIO/docker-compose.tpl" "$COMPOSE_FILE"; then
    log_error "Template no encontrado: docker-compose.tpl"
    exit 1
fi

procesar_template "$CATALOGO_SERVICIO/env.tpl" "$SERVICIO_DIR/.env" || true

# =====================================================
# DESPLEGAR EN DOCKER
# =====================================================

RED="${EMPRESA}_net"
if ! docker network inspect "$RED" >/dev/null 2>&1; then
    docker network create "$RED" --driver bridge >/dev/null
fi

log_info "Levantando contenedores..."
cd "$SERVICIO_DIR"
docker compose up -d 2>&1 | tee -a "$LOG_FILE"

# =====================================================
# AUTOMATIZACIÓN NPM
# =====================================================

source "$SCRIPT_PATH/funciones/npm.sh"
log_info "Configurando Proxy NPM..."
TOKEN=$(npm_get_token || echo "")
SUBDOMAIN="${SERVICIO}.${EMPRESA}.tensaas.es"
URL_ACCESO="https://$SUBDOMAIN"

if [ -n "$TOKEN" ]; then
    npm_add_proxy "$SUBDOMAIN" "${EMPRESA}_${SERVICIO}-1" "$PUERTO" "$NPM_CERT_ID" "$TOKEN" || URL_ACCESO="http://localhost:$PUERTO"
else
    URL_ACCESO="http://localhost:$PUERTO"
fi

# =====================================================
# REGISTRO FINAL EN BASE DE DATOS
# =====================================================

log_info "Finalizando registro..."
db_register_empresa "$EMPRESA" || true
crear_usuario_admin "$EMPRESA" "$ADMIN_USER" "$ADMIN_PASSWORD" || true
db_register_servicio "$EMPRESA" "$SERVICIO" "$PUERTO" "$URL_ACCESO" || true

log_success "DEPLOY COMPLETADO PARA $EMPRESA"
log_info "URL: $URL_ACCESO"
log_info "User: $ADMIN_USER"
log_info "Pass: $ADMIN_PASSWORD"
