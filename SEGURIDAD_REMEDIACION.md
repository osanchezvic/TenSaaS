# Registro de Remediación de Seguridad y Hardening

Este documento detalla las acciones realizadas para asegurar la plataforma y las tareas de endurecimiento (hardening) pendientes.

## ✅ Acciones Realizadas (Actualizado: Mayo 2026)

### 1. Gestión de Identidades y Acceso (IAM)
- [x] **SSO con Authelia**: Implementación de Authelia como guardián de acceso para Grafana, Portainer y el Panel de Administración.
- [x] **Seguridad 2FA**: Configuración de reglas en Authelia para exigir segundo factor de autenticación en rutas críticas.
- [x] **Hashing Seguro**: Migración de contraseñas a hashes **Argon2id** (vía Authelia Crypto).
- [x] **Rotación de Secretos**: Generación de nuevos `API_TOKEN` y contraseñas de BD eliminando valores por defecto.

### 2. Seguridad en Aplicaciones y API
- [x] **Sanitización de Entradas**: Implementación de Regex en la API (FastAPI) para validar nombres de empresa y servicio, evitando inyección de comandos.
- [x] **Prepared Statements**: Uso estricto de sentencias preparadas en PHP para prevenir SQL Injection.
- [x] **Tokens CSRF**: Validación de tokens en todas las peticiones POST del Dashboard.
- [x] **Cierre de Servicios Innecesarios**: Eliminación de `dashy`, `fail2ban` y `watchtower` para reducir la superficie de ataque y simplificar la gestión.

### 3. Seguridad de Red y Docker
- [x] **Aislamiento Multi-tenant**: Uso de redes bridge dedicadas por empresa, impidiendo que contenedores de distintos clientes se comuniquen entre sí.
- [x] **Túnel de Cloudflare**: Exposición de servicios mediante `cloudflared`, ocultando la IP real del servidor y cerrando puertos de entrada directos.
- [x] **Alertas de Seguridad**: Integración con Alertmanager y Telegram para notificaciones instantáneas de caídas de servicios.

---

## 🛠️ Tareas de Hardening Pendientes (Roadmap)

### 1. Endurecimiento del Demonio de Docker (Prioridad: Alta)
*   **Problema:** La API y Portainer montan `/var/run/docker.sock`, lo que otorga privilegios de root sobre el host.
*   **Remediación:** Implementar `docker-socket-proxy` para filtrar las peticiones permitidas (solo lectura o acciones específicas) y limitar el acceso al socket.

### 2. Segmentación de Red Interna (Prioridad: Media)
*   **Problema:** Todos los servicios de infraestructura están en la misma red `infra_net`.
*   **Remediación:** Separar la red de base de datos (`db_net`) de la red de la aplicación (`app_net`), permitiendo que solo el Dashboard y la API lleguen a MariaDB.

### 3. Hardening de Cabeceras HTTP (Prioridad: Baja)
*   **Problema:** Faltan cabeceras de seguridad (HSTS, CSP, X-Frame-Options) en algunas respuestas.
*   **Remediación:** Configurar perfiles de seguridad globales en Nginx Proxy Manager.

### 4. Escaneo de Vulnerabilidades (Prioridad: Media)
*   **Remediación:** Integrar escaneo de imágenes de catálogo (vía Trivy o similar) antes de permitir su despliegue en producción.
