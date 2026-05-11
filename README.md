# 🚀 TenSaaS Multiempresa ASIR

Plataforma de gestión SaaS automatizada y profesional diseñada para orquestar servicios basados en Docker en entornos multiempresa. Este sistema permite el despliegue centralizado, la observabilidad avanzada y la gestión de acceso segura para múltiples inquilinos.

## 🏗️ Arquitectura y Seguridad de Alto Nivel

El sistema ha sido diseñado bajo principios de **Security by Design** y **Observabilidad Total**:

- **Aislamiento Multi-Tenant:** Cada empresa cuenta con su propio ecosistema de directorios, redes Docker aisladas y gestión de credenciales independiente.
- **Blindaje de Código:**
  - **Protección SQL:** Uso estricto de sentencias preparadas (Prepared Statements) para prevenir inyecciones.
  - **Seguridad Web:** Protección contra ataques CSRF mediante tokens de sesión validados en cada acción crítica.
- **Gestión de Infraestructura (IaC):**
  - **API-driven:** Orquestación mediante una API segura (FastAPI) que actúa como puente entre la UI y los scripts de sistema.
  - **Zero-Hardcoded Secrets:** Eliminación de credenciales por defecto en el código. Uso obligatorio de variables de entorno para tokens de API y contraseñas.
- **Monitorización Proactiva:**
  - **Alertmanager:** Sistema de alertas crítico integrado con **Telegram** para notificaciones en tiempo real.
  - **Stack de Métricas:** Prometheus y Grafana para el control exhaustivo de recursos.

## 📁 Estructura del Proyecto

```text
/
├── catalogo/        # Plantillas (.tpl) de servicios listos para SaaS
├── infra/           # Servicios globales (Proxy, API, Monitorización, Dashboards)
│   ├── api/         # Backend de automatización (FastAPI)
│   ├── admin-dashboard/ # Panel de administración visual (PHP/JS)
│   └── monitorizacion/ # Stack Prometheus, Grafana y Alertmanager
├── scripts/         # Motor de orquestación en Bash
│   ├── funciones/   # Módulos de lógica (DB, Puertos, Seguridad, NPM)
│   └── deploy.sh    # Script inteligente de despliegue con gestión de dependencias
├── data/            # Almacenamiento persistente aislado por empresa
├── docs/            # Diagramas de arquitectura y guías técnicas
└── README.md        # Documentación principal
```

## ✨ Características del "10" (Valor Añadido)

1. **Orquestador con Dependencias:** El sistema detecta automáticamente si un servicio (ej. WordPress) requiere otro (ej. MariaDB) y lo despliega de forma autónoma.
2. **Dashboard Admin Moderno:** Interfaz profesional rediseñada con **Glassmorphism** y actualización en tiempo real mediante Fetch API para la gestión de despliegues.
3. **Portal Dashy:** Punto de entrada visual que monitoriza la salud de los servicios en tiempo real con indicadores de estado.
4. **Sistema de Alertas:** Notificaciones automáticas al móvil si un contenedor cae o si hay un consumo excesivo de CPU/RAM.
5. **Backups Garantizados:** Script de respaldo mejorado que asegura la integridad total de los volúmenes antes de cualquier cambio.

## 🚀 Instalación y Configuración Segura

Este proyecto utiliza variables de entorno para gestionar secretos. **Nunca** subas archivos `.env` reales a un repositorio público.

### Pasos para el despliegue

1. **Configurar Secretos:**
   Copia las plantillas de configuración y rellena tus credenciales (Tokens de API, Passwords de DB, etc.):
   ```bash
   cp .env.example .env
   cp .env infra/.env
   cp scripts/config.env.example scripts/config.env
   ```
   *Edita `.env` con valores seguros antes de proceder.*

2. **Levantar la Infraestructura Base:**
   ```bash
   cd infra
   docker compose up -d
   ```

3. **Acceso a los Paneles:**
   - **Admin Dashboard:** `https://panel.tensaas.es` (Gestionado por Nginx Proxy Manager + Cloudflare)
   - **Monitorización:** `https://grafana.tensaas.es`
   - **Portal Dashy:** `https://portal.tensaas.es`

---

## 🔒 Seguridad Implementada
- **Zero Secrets in Git:** Eliminación de credenciales hardcodeadas en favor de variables de entorno.
- **Acceso Restringido:** Puertos administrativos (NPM) limitados a `127.0.0.1`.
- **Validación de Entradas:** Saneamiento de parámetros en la API para prevenir inyección de comandos.
- **Aislamiento Docker:** Redes independientes por cada inquilino (tenant).
