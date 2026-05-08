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

## 🚀 Guía de Instalación Rápida

1. **Configurar el entorno:**
   ```bash
   # Copiar las plantillas de configuración
   cp scripts/config.env.example scripts/config.env
   cp infra/authelia/config/users.yml.example infra/authelia/config/users.yml
   ```
2. **Editar credenciales:** Modifica `scripts/config.env` y el `.env` de la infraestructura con tus tokens y contraseñas.
3. **Levantar la infraestructura base:**
   ```bash
   cd infra && docker compose up -d
   ```

## 🖥️ Acceso a la Plataforma (Default)

| Servicio | URL | Descripción |
| :--- | :--- | :--- |
| **Admin Dashboard** | `http://localhost:8000` | Gestión de despliegues y empresas. |
| **Portal Global** | `http://localhost:4000` | Estado visual de toda la infraestructura (Dashy). |
| **Monitorización** | `http://localhost:3000` | Métricas avanzadas en Grafana (Admin:admin). |
| **API Control** | `http://localhost:8001` | Backend de automatización (Documentación en `/docs`). |

## 🛠️ Comandos de Gestión

```bash
# Desplegar un servicio para una empresa (ej: panaderia wordpress)
./scripts/deploy.sh <empresa> <servicio>

# Destruir un servicio con backup automático
./scripts/destroy.sh <empresa> <servicio>

# Listar servicios activos
./scripts/list.sh
```

## 🔒 Seguridad Aplicada
- **RBAC:** Control de acceso mediante base de datos MariaDB con hashes Bcrypt.
- **Authelia:** Autenticación centralizada de doble factor (2FA).
- **Fail2ban:** Protección contra ataques de fuerza bruta integrando logs de Nginx.
- **Secrets Management:** Los archivos sensibles están excluidos de Git mediante un `.gitignore` estricto y el uso de archivos `.example`.
