#!/bin/bash

# =========================================
# VALIDACIONES BÁSICAS Y DEPENDENCIAS
# =========================================

# Validar servicio en catálogo
validar_servicio() {
    local servicio="$1"
    
    if [ ! -d "$CATALOGO_DIR/$servicio" ]; then
        echo_error "Servicio no existe: $servicio"
        echo_info "Disponibles: $(ls $CATALOGO_DIR)"
        return 1
    fi
    
    if [ ! -f "$CATALOGO_DIR/$servicio/config.yml" ]; then
        echo_error "Falta config.yml en $servicio"
        return 1
    fi
    
    return 0
}

# Obtener dependencias de un servicio desde config.yml
obtener_dependencias() {
    local servicio="$1"
    local config_file="$CATALOGO_DIR/$servicio/config.yml"
    
    if [ ! -f "$config_file" ]; then
        return
    fi
    
    # Extraer bloque de dependencias de forma robusta hasta la siguiente clave de primer nivel
    sed -n '/^dependencias:/,/^[^ ]/p' "$config_file" | grep "^  - " | sed 's/^  - //' || true
}

# Validar y resolver dependencias automáticamente
validar_dependencias_auto() {
    local empresa="$1"
    local servicio="$2"
    
    echo_info "Verificando dependencias de $servicio..."
    
    local deps=$(obtener_dependencias "$servicio")
    
    if [ -z "$deps" ]; then
        echo_debug "Sin dependencias"
        return 0
    fi
    
    while read -r dep; do
        if [ -z "$dep" ]; then
            continue
        fi
        
        echo_debug "Validando dependencia: $dep"
        
        # Verificar si la dependencia ya existe para esta empresa
        if servicio_existe "$empresa" "$dep"; then
            echo_info "Dependencia OK: $empresa/$dep ya existe"
        else
            echo_warn "Dependencia FALTA: $empresa/$dep"
            
            # Mostrar info de dependencia
            if validar_servicio "$dep"; then
                echo_info "Instalando dependencia: $dep..."
                
                # Desplegar dependencia automáticamente
                cd "$SCRIPT_PATH" || return 1
                
                if SKIP_LOCK=true ./deploy.sh "$empresa" "$dep"; then
                    echo_info "Dependencia instalada: $empresa/$dep"
                else
                    echo_error "Error instalando dependencia: $empresa/$dep"
                    echo_info "Intenta manualmente: ./deploy.sh $empresa $dep"
                    return 1
                fi
            else
                echo_error "Error: no se pudo validar la dependencia $dep"
                return 1
            fi
        fi
    done <<< "$deps"
    
    return 0
}

# Pre-validaciones antes del deploy
validar_pre_deploy() {
    local empresa="$1"
    local servicio="$2"
    
    # Validar nombre empresa
    if ! validar_nombre "$empresa"; then
        return 1
    fi
    
    # Validar nombre servicio
    if ! validar_nombre "$servicio"; then
        return 1
    fi
    
    # Validar servicio existe en catálogo
    if ! validar_servicio "$servicio"; then
        return 1
    fi
    
    # Validar y resolver dependencias automáticamente
    if ! validar_dependencias_auto "$empresa" "$servicio"; then
        echo_error "No se pudieron resolver dependencias"
        return 1
    fi
    
    return 0
}
# Validar compose template
validar_compose_template() {
    local empresa="$1"
    local servicio="$2"
    local compose_file="$DATA_DIR/$empresa/$servicio/docker-compose.yml"
    
    if [ -f "$compose_file" ]; then
        docker compose -f "$compose_file" config -q
        return $?
    fi
    return 1
}

# Post-validaciones después del deploy
validar_post_deploy() {
    local empresa="$1"
    local servicio="$2"
    local container="${empresa}_${servicio}"
    
    echo_info "Validaciones post-deploy en curso..."
    
    # Verificar que contenedor existe
    if ! docker inspect "$container" >/dev/null 2>&1; then
        echo_error "Contenedor no encontrado: $container"
        return 1
    fi
    
    # Esperar a que esté running
    local max_intentos=10
    local intento=0
    
    while [ $intento -lt $max_intentos ]; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null)" == "true" ]; then
            echo_info "Contenedor corriendo: $container"
            return 0
        fi
        
        intento=$((intento + 1))
        echo_debug "Esperando contenedor... ($intento/$max_intentos)"
        sleep 2
    done
    
    echo_error "Timeout esperando contenedor $container"
    return 1
}

# Validar env template
validar_env_template() {
    local empresa="$1"
    local servicio="$2"
    local env_file="$DATA_DIR/$empresa/$servicio/.env"
    
    [ -f "$env_file" ]
}

# Exportar funciones
export -f validar_servicio obtener_dependencias validar_dependencias_auto validar_pre_deploy validar_post_deploy validar_compose_template validar_env_template



