#!/bin/bash
set -euo pipefail

# =====================================================
# DEPLOY DE SERVICIOS - VERSION 2.2 (LIMPIA)
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

    # Usamos un delimitador alternativo | para sed y escapamos posibles conflictos
    # Esta versión es limpia y evita duplicar líneas si la plantilla está bien formada
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

    if wait_container_healthy "${EMPRESA}_${SERVICIO}" 60; then
        log_success "Servicio activo"
        exit 0
    else
        log_error "El servicio no llegó a estado healthy"
        exit 1
    fi
fi

# =====================================================
# PREPARAR DIRECTORIO
# =====================================================

log_info "Realizando backup de seguridad..."
backup_servicio "$EMPRESA" "$SERVICIO"

rm -rf "$SERVICIO_DIR"
mkdir -p "$SERVICIO_DIR"
log_debug "Directorio listo: $SERVICIO_DIR"

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
        return 0
    fi
    
    local db_cred_file="$CREDENTIALS_DIR/${empresa}.mariadb"
    if [ -f "$db_cred_file" ]; then
        log_info "Usando credenciales de base de datos existente"
        local json=$(cat "$db_cred_file")
        DB_NAME=$(echo "$json" | jq -r .db_name)
        DB_USER=$(echo "$json" | jq -r .db_user)
        DB_PASSWORD=$(echo "$json" | jq -r .db_password)
        DB_ROOT_PASSWORD=$(echo "$json" | jq -r .db_root_password)
        ADMIN_USER="admin"
        ADMIN_PASSWORD=$(generar_password 16)
        JWT_SECRET=$(generar_token 32)
        return 0
    fi

    log_info "Generando nuevas credenciales..."
    DB_NAME="${EMPRESA}_db"
    DB_USER="${EMPRESA}_user"
    DB_PASSWORD=$(generar_password 16)
    DB_ROOT_PASSWORD=$(generar_password 16)
    ADMIN_USER="admin"
    ADMIN_PASSWORD=$(generar_password 16)
    JWT_SECRET=$(generar_token 32)
}

# =====================================================
# GENERAR CREDENCIALES Y VALORES
# =====================================================

log_info "Configurando credenciales..."

PUERTO=$(asignar_puerto "$EMPRESA" "$SERVICIO" "dev")
if [ -z "$PUERTO" ]; then
    log_failed "No se pudo asignar puerto"
    exit 1
fi

cargar_o_generar_credenciales "$EMPRESA" "$SERVICIO"

# =====================================================
# GUARDAR CREDENCIALES
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
log_success "Credenciales guardadas en $CRED_FILE"

# =====================================================
# REGISTRAR EN BASE DE DATOS
# =====================================================

db_register_empresa "$EMPRESA" || log_debug "Empresa ya registrada"
crear_usuario_admin "$EMPRESA" "$ADMIN_USER" "$ADMIN_PASSWORD" || log_debug "Usuario admin ya existe o BD no disponible"

if ! db_register_servicio "$EMPRESA" "$SERVICIO" "$PUERTO" "$CRED_FILE"; then
    log_error "Error registrando servicio en BD"
    exit 1
fi

# =====================================================
# GENERAR ARCHIVOS DESDE TEMPLATES
# =====================================================

log_info "Procesando templates..."

CATALOGO_SERVICIO="$CATALOGO_DIR/$SERVICIO"

if ! procesar_template "$CATALOGO_SERVICIO/docker-compose.tpl" "$COMPOSE_FILE"; then
    log_error "Template no encontrado o inválido: docker-compose.tpl"
    exit 1
fi

procesar_template "$CATALOGO_SERVICIO/env.tpl" "$SERVICIO_DIR/.env" \
    || log_debug "env.tpl no encontrado, continuando sin .env"

# =====================================================
# VALIDAR ARCHIVOS GENERADOS
# =====================================================

if ! validar_compose_template "$EMPRESA" "$SERVICIO"; then
    log_failed "docker-compose.yml inválido"
    exit 1
fi

# Pre-flight security scan
IMAGE=$(grep -m 1 "image:" "$COMPOSE_FILE" | awk '{print $2}' | tr -d '"' | tr -d "'")
if [ -n "$IMAGE" ]; then
    if ! scan_image "$IMAGE"; then
        log_failed "Escaneo de seguridad fallido"
        exit 1
    fi
fi

validar_env_template "$EMPRESA" "$SERVICIO" || log_warn ".env no validado"

# =====================================================
# CREAR RED DOCKER (si no existe)
# =====================================================

RED="${EMPRESA}_net"
if ! docker network inspect "$RED" >/dev/null 2>&1; then
    log_info "Creando red Docker: $RED"
    docker network create "$RED" --driver bridge >/dev/null
fi

# =====================================================
# DESPLEGAR EN DOCKER
# =====================================================

log_info "Desplegando en Docker..."

cd "$SERVICIO_DIR" || exit 1

if ! docker compose up -d 2>&1 | tee -a "$LOG_FILE"; then
    log_failed "Error en docker compose up"
    exit 1
fi

# =====================================================
# AUTOMATIZACIÓN NPM
# =====================================================

source "$SCRIPT_PATH/funciones/npm.sh"

log_info "Configurando Proxy NPM..."
TOKEN=$(npm_get_token)
SUBDOMAIN="${SERVICIO}.${EMPRESA}.tensaas.es"

if npm_add_proxy "$SUBDOMAIN" "${EMPRESA}_${SERVICIO}-1" "$PUERTO" "$NPM_CERT_ID" "$TOKEN"; then
    log_success "Proxy configurado: https://$SUBDOMAIN"
else
    log_error "Fallo al configurar proxy en NPM"
fi

# =====================================================
# VALIDACIONES POST-DEPLOY
# =====================================================

validar_post_deploy "$EMPRESA" "$SERVICIO" || log_warn "Algunas validaciones post-deploy fallaron"

# =====================================================
# RESUMEN FINAL
# =====================================================

LOCAL_IP=$(ip route get 1 | awk '{print $7; exit}')
DASHBOARD_URL="http://$LOCAL_IP:$PUERTO"

log_info "=================================================="
log_success "DEPLOY COMPLETADO"
log_info "=================================================="
log_info "Empresa:      $EMPRESA"
log_info "Servicio:     $SERVICIO"
log_info "Puerto:       $PUERTO"
log_info "URL:          $DASHBOARD_URL"
log_info "Red Docker:   $RED"
log_info "Directorio:   $SERVICIO_DIR"
log_info "Logs:         $LOG_FILE"
log_info "--------------------------------------------------"
log_info "Credenciales: cat $CRED_FILE"
log_info "Estado:       cd $SERVICIO_DIR && docker compose ps"
log_info "=================================================="

exit 0
