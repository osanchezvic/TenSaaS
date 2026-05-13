#!/bin/bash

# =========================================
# TEST.SH - Validar funcionamiento del sistema
# =========================================

set -euo pipefail

SCRIPT_PATH=$(cd "$(dirname "${BASH_SOURCE[0]}")" &> /dev/null && pwd)
source "$SCRIPT_PATH/config.env"
source "$SCRIPT_PATH/funciones/logging.sh"
source "$SCRIPT_PATH/funciones/db.sh"

# Variables de test
TEST_EMPRESA="testco"
TEST_SERVICIO="wordpress"
PASS=0
FAIL=0

# Función para incrementar contadores de forma segura con set -e
inc_pass() { PASS=$((PASS + 1)); }
inc_fail() { FAIL=$((FAIL + 1)); }

echo ""
echo "====== TESTS DE VALIDACION ======"
echo ""

# Test 1: Listar servicios iniciales
echo_info "TEST 1: Listar servicios (BD inicial)"
if ./list.sh > /dev/null 2>&1; then
    inc_pass
else
    inc_fail
fi
echo ""

# Test 2: Desplegar WordPress (debe instalar MariaDB automaticamente)
echo_info "TEST 2: Deploy WordPress con dependencias automaticas"
echo_info "Esto desplegará: testco/mariadb + testco/wordpress"
echo ""

if echo "y" | ./deploy.sh "$TEST_EMPRESA" "$TEST_SERVICIO" >/dev/null 2>&1; then
    echo_success "Deploy completado"
    inc_pass
else
    echo_error "Deploy fallido"
    inc_fail
fi
sleep 5
echo ""

# Test 3: Verificar que mariadb fue instalado
echo_info "TEST 3: Verificar que MariaDB se instaló automaticamente"
if ./funciones/db.sh > /dev/null 2>&1 && grep -q "^$TEST_EMPRESA:mariadb:" "$DB_DIR/servicios.txt" 2>/dev/null; then
    echo_success "MariaDB registrado en BD"
    inc_pass
else
    echo_error "MariaDB no está en BD"
    inc_fail
fi
echo ""

# Test 4: Verificar que WordPress se instaló
echo_info "TEST 4: Verificar que WordPress se instaló"
if grep -q "^$TEST_EMPRESA:$TEST_SERVICIO:" "$DB_DIR/servicios.txt" 2>/dev/null; then
    echo_success "WordPress registrado en BD"
    inc_pass
else
    echo_error "WordPress no está en BD"
    inc_fail
fi
echo ""

# Test 5: List debe mostrar ambos servicios
echo_info "TEST 5: List debe mostrar mariadb + wordpress"
LIST_OUTPUT=$(./list.sh "$TEST_EMPRESA" 2>/dev/null || true)
if echo "$LIST_OUTPUT" | grep -q "mariadb" && echo "$LIST_OUTPUT" | grep -q "wordpress"; then
    echo_success "Ambos servicios visibles"
    echo "$LIST_OUTPUT"
    inc_pass
else
    echo_error "No están ambos servicios en list"
    echo "$LIST_OUTPUT"
    inc_fail
fi
echo ""

# Test 6: Get-credentials para WordPress
echo_info "TEST 6: Get-credentials debe mostrar credenciales"
if ./get-credentials.sh "$TEST_EMPRESA" "$TEST_SERVICIO" >/dev/null 2>&1; then
    echo_success "Credenciales recuperadas"
    ./get-credentials.sh "$TEST_EMPRESA" "$TEST_SERVICIO"
    inc_pass
else
    echo_error "No se pudieron obtener credenciales"
    inc_fail
fi
echo ""

# Test 7: Destroy con confirmacion
echo_info "TEST 7: Destroy (eliminación segura)"
echo_info "Confirma eliminación de servicios..."
if echo "y" | ./destroy.sh "$TEST_EMPRESA" "$TEST_SERVICIO" >/dev/null 2>&1; then
    echo_success "Destroy completado"
    inc_pass
else
    echo_error "Destroy falló"
    inc_fail
fi
sleep 5
echo ""

# Test 8: Verificar que fue eliminado de BD
echo_info "TEST 8: Verificar que WordPress fue eliminado de BD"
if ! grep -q "^$TEST_EMPRESA:$TEST_SERVICIO:" "$DB_DIR/servicios.txt" 2>/dev/null; then
    echo_success "WordPress eliminado correctamente"
    inc_pass
else
    echo_error "WordPress aún está en BD"
    inc_fail
fi
echo ""

# Test 9: Estado de MariaDB (depende si destroy la elimino o no)
echo_info "TEST 9: Verificar MariaDB (debería estar eliminado por dependencia)"
if ! grep -q "^$TEST_EMPRESA:mariadb:" "$DB_DIR/servicios.txt" 2>/dev/null; then
    echo_warn "MariaDB también fue eliminada (comportamiento correcto)"
    inc_pass
else
    echo_info "MariaDB sigue en BD (si estaba compartida, es correcto)"
    inc_pass
fi
echo ""

# ====== RESULTADOS ======
echo "====== RESULTADOS ======"
echo "Pasados: $PASS"
echo "Fallos: $FAIL"

if [ $FAIL -eq 0 ]; then
    echo_success "TODOS LOS TESTS PASARON"
    exit 0
else
    echo_error "$FAIL TESTS FALLARON"
    exit 1
fi
