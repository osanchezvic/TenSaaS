# Plan de Mejoras Técnicas - Base de Datos (users_db)

Este documento registra las recomendaciones de arquitectura para mejorar la integridad, normalización y rendimiento de la base de datos de usuarios del sistema SaaS.

## Clasificación de Prioridad
- **Crítico:** Afecta directamente la seguridad o el funcionamiento actual.
- **Importante:** Mejora la integridad y mantenibilidad (Deuda técnica).
- **Sugerido:** Optimización de rendimiento a escala.

---

## Recomendaciones Identificadas

### 1. Normalización de usuarios
*   **Problema:** El campo `usuarios.empresa` es redundante respecto a `empresas.nombre`.
*   **Prioridad:** Importante.
*   **Impacto:** Riesgo de inconsistencia de datos si cambia el nombre de una empresa.
*   **Acción:** Eliminar columna `usuarios.empresa` y realizar `JOIN` con `empresas` en las consultas.

### 2. Estandarización de roles
*   **Problema:** Redundancia entre `rol` y `es_admin`.
*   **Prioridad:** Importante.
*   **Impacto:** Ambigüedad en la lógica de permisos.
*   **Acción:** Unificar privilegios usando únicamente la columna `rol` y actualizar la lógica de la aplicación.

### 3. Índices de rendimiento
*   **Problema:** Falta de índices explícitos en campos de consulta frecuente en tablas de alto volumen.
*   **Prioridad:** Sugerido (a medida que crezcan los logs).
*   **Acción:** Crear índices en `access_logs.fecha` y otras columnas de búsqueda frecuente.

### 4. Gestión de sesiones (Contexto)
*   **Nota:** Se confirma que la ausencia de tablas de sesión es intencional, ya que se gestionan mediante `Redis` y `Authelia` (JWT/Stateless).
