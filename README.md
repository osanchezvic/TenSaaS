# 🚀 TenSaaS Multiempresa ASIR

Plataforma de gestión SaaS automatizada para orquestar servicios basados en Docker en entornos multiempresa. Diseñada para ofrecer despliegue centralizado, seguridad avanzada y monitorización profesional.

## 🏗️ Arquitectura del Sistema

El sistema se basa en un modelo **Multi-Tenant** con aislamiento total:

- **Infraestructura Base:**
  - **API (FastAPI):** Orquestador central que comunica el Dashboard con los scripts de sistema.
  - **Admin Dashboard (PHP/JS):** Panel visual para gestionar empresas, usuarios y despliegues.
  - **Reverse Proxy (Nginx Proxy Manager):** Gestión de certificados SSL y exposición de servicios.
  - **Autenticación (Authelia + Redis):** Seguridad centralizada (SSO) y protección 2FA para paneles críticos.
  - **Monitorización (Prometheus + Grafana):** Control de métricas y salud del sistema.
  - **Alertas (Alertmanager):** Notificaciones integradas con **Telegram**.

- **Aislamiento Multi-Empresa:**
  - Cada empresa tiene su propia red Docker aislada (`{empresa}_net`).
  - Almacenamiento persistente dedicado en `data/{empresa}/{servicio}`.
  - Gestión independiente de credenciales en `scripts/databases/credentials`.

## 📁 Estructura del Proyecto

```text
/
├── catalogo/           # Plantillas (.tpl) de servicios (WordPress, MariaDB, Nextcloud, etc.)
├── infra/              # Servicios globales de infraestructura
│   ├── api/            # Backend de automatización (FastAPI)
│   ├── admin-dashboard/# Panel de administración (PHP/JS)
│   ├── monitorizacion/ # Stack de observabilidad (Prometheus, Grafana, Alertmanager)
│   ├── proxy/          # Nginx Proxy Manager y configuración de Authelia
│   ├── users-db/       # Base de datos central de usuarios y empresas
│   └── authelia/       # Configuración de Single Sign-On y 2FA
├── scripts/            # Motor de orquestación en Bash
│   ├── funciones/      # Módulos de lógica (DB, Puertos, Seguridad, NPM)
│   ├── deploy.sh       # Script inteligente de despliegue con dependencias
│   └── destroy.sh      # Script de eliminación segura de servicios
├── data/               # Almacenamiento persistente aislado por empresa
└── docs/               # Guías técnicas y documentación de seguridad
```

## ✨ Características Principales

1.  **Despliegue Inteligente:** Orquestador que detecta y despliega dependencias automáticamente.
2.  **Dashboard Moderno:** Interfaz con actualización en tiempo real mediante Fetch API.
3.  **Seguridad por Diseño:**
    *   Protección contra Inyección SQL (Prepared Statements).
    *   Protección CSRF en todas las acciones críticas.
    *   Aislamiento de redes Docker por tenant.
    *   Gestión de secretos mediante variables de entorno (Zero Hardcoded Secrets).
4.  **Observabilidad:** Paneles de Grafana preconfigurados y alertas automáticas a Telegram.
5.  **Single Sign-On (SSO):** Acceso unificado mediante Authelia para todos los paneles administrativos.

## 🚀 Instalación y Despliegue

### 1. Preparación del Entorno
Copia las plantillas de configuración y define tus secretos:
```bash
cp .env.example .env
cp .env infra/.env
cp scripts/config.env.example scripts/config.env
```
*Edita `.env` con valores seguros (API_TOKEN, DB_PASSWORD, TELEGRAM_BOT_TOKEN, etc.).*

### 2. Levantar Infraestructura
```bash
cd infra
./deploy-infra.sh start
```

### 3. Acceso a los Servicios
- **Dashboard:** `https://panel.tensaas.es`
- **Grafana:** `https://grafana.tensaas.es`
- **Nginx Proxy Manager:** `http://localhost:81`

---

## 🔒 Seguridad Implementada
- **Zero Secrets in Git:** Uso exclusivo de variables de entorno para credenciales.
- **Acceso Restringido:** Puertos administrativos limitados y exposición vía túnel de Cloudflare.
- **Validación Estricta:** Saneamiento de entradas en API y scripts para prevenir inyección de comandos.
- **Protección de Datos:** Copias de seguridad automáticas antes de modificaciones críticas.
