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
  - **Hardening:** Permisos restrictivos en archivos de configuración (600), rotación de secretos y eliminación de credenciales por defecto.
- **Monitorización Proactiva:**
  - **Alertmanager:** Sistema de alertas crítico integrado con **Telegram** para notificaciones en tiempo real.
  - **Stack de Métricas:** Prometheus y Grafana para el control exhaustivo de recursos.

## 📁 Estructura del Proyecto

```text
/
├── catalogo/        # Plantillas (.tpl) de servicios listos para SaaS
├── infra/           # Servicios globales (Proxy, API, Monitorización, Dashboards)
├── scripts/         # Motor de orquestación en Bash
│   ├── funciones/   # Módulos de lógica (DB, Puertos, Seguridad)
│   └── deploy.sh    # Script inteligente de despliegue con validación de dependencias
├── data/            # Almacenamiento persistente aislado por empresa
├── docs/            # Diagramas de arquitectura y guías técnicas
└── README.md        # Documentación principal
```

## ✨ Características del "10" (Valor Añadido)

1. **Orquestador con Dependencias:** El sistema detecta automáticamente si un servicio (ej. WordPress) requiere otro (ej. MariaDB) y lo despliega de forma autónoma.
2. **Dashboard Admin Moderno:** Interfaz profesional rediseñada con Glassmorphism para la gestión visual de despliegues y estadísticas.
3. **Portal Dashy:** Punto de entrada visual que monitoriza la salud de los servicios en tiempo real con indicadores de estado.
4. **Sistema de Alertas:** Notificaciones automáticas al móvil si un contenedor cae o si hay un consumo excesivo de CPU/RAM.
5. **Backups Garantizados:** Script de respaldo mejorado que asegura la integridad total de los volúmenes antes de cualquier cambio.

## 🖥️ Acceso a la Plataforma (Localhost)

| Servicio | URL | Descripción |
| :--- | :--- | :--- |
| **Admin Dashboard** | `http://localhost:8000` | Gestión de despliegues y empresas. |
| **Portal Global** | `http://localhost:4000` | Estado visual de toda la infraestructura. |
| **Monitorización** | `http://localhost:3000` | Métricas avanzadas en Grafana. |
| **API Control** | `http://localhost:8001` | Backend de automatización. |

## 🛠️ Comandos Rápidos

```bash
# Desplegar un servicio para una empresa (CLI)
./scripts/deploy.sh <empresa> <servicio>

# Destruir con backup automático
./scripts/destroy.sh <empresa> <servicio>

# Levantar infraestructura base
cd infra && docker compose up -d
```

## 🔒 Seguridad Aplicada
- **RBAC:** Control de acceso mediante `users_db` (MariaDB) con hashes Bcrypt.
- **Authelia:** Autenticación centralizada y portal de seguridad.
- **Fail2ban:** Protección contra ataques de fuerza bruta en los servicios expuestos.
