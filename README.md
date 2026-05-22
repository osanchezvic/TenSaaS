<div align="center">
# TenSaaS
 
**Orquestador Multi-Tenant de servicios gestionados basado en Docker**
 
*Proyecto Final de Ciclo — ASIR (Administración de Sistemas Informáticos en Red)*
 
![Shell](https://img.shields.io/badge/Shell-Bash_4.0+-4EAA25?logo=gnubash&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-20+-2496ED?logo=docker&logoColor=white)
![Python](https://img.shields.io/badge/API-FastAPI-009688?logo=fastapi&logoColor=white)
![PHP](https://img.shields.io/badge/Dashboard-PHP-777BB4?logo=php&logoColor=white)
![Cloudflare](https://img.shields.io/badge/Exposición-Cloudflare_Tunnel-F38020?logo=cloudflare&logoColor=white)
![License](https://img.shields.io/badge/Licencia-MIT-blue)
 
</div>
---
 
## ¿Qué es TenSaaS?
 
TenSaaS es una plataforma de orquestación Multi-Tenant diseñada para que pequeñas y medianas empresas puedan consumir servicios de software (WordPress, Nextcloud, Gitea, etc.) de forma completamente aislada, segura y automatizada, sin necesidad de gestión técnica por parte del cliente.
 
El sistema garantiza tres principios fundamentales:
 
- **Soberanía del dato**: los datos de cada empresa residen en rutas físicas separadas del servidor y nunca se mezclan entre tenants.
- **Zero-Exposure**: el servidor no expone ningún puerto al exterior. Toda la conectividad a internet se establece mediante un túnel cifrado saliente de Cloudflare, eliminando la superficie de ataque perimetral.
- **Automatización completa**: desde la validación de la petición hasta el registro del proxy y la emisión del certificado SSL, el despliegue de un nuevo servicio es un único comando.
---
 
## Arquitectura General
 
```
                          ┌─────────────────────────────────────────┐
                          │              INTERNET                   │
                          └────────────────┬────────────────────────┘
                                           │ HTTPS (Cloudflare)
                          ┌────────────────▼────────────────────────┐
                          │         Cloudflare Tunnel               │
                          │       (cloudflared — sin puertos)       │
                          └────────────────┬────────────────────────┘
                                           │
                          ┌────────────────▼────────────────────────┐
                          │       Nginx Proxy Manager (NPM)         │
                          │   SSL automático · Ruteo de dominios    │
                          └──────┬─────────────────┬────────────────┘
                                 │                 │
                   ┌─────────────▼──┐     ┌────────▼────────────────┐
                   │    Authelia    │     │    Servicios Tenant      │
                   │ SSO + 2FA/TOTP │     │  empresa1_wp  |  8101   │
                   │ (paneles admin)│     │  empresa2_nc  |  8245   │
                   └────────────────┘     │  empresa3_gt  |  8312   │
                                          └─────────────────────────┘
                          ┌──────────────────────────────────────────┐
                          │           INFRAESTRUCTURA GLOBAL         │
                          │  FastAPI · Dashboard PHP · Portainer     │
                          │  Prometheus · Grafana · Alertmanager     │
                          └──────────────────────────────────────────┘
```
 
---
 
## Estructura del Repositorio
 
```
/
├── catalogo/                  # Definiciones del catálogo de servicios
│   ├── wordpress/
│   │   ├── config.yml         # Metadatos y dependencias del servicio
│   │   ├── docker-compose.tpl # Plantilla de composición
│   │   └── env.tpl            # Plantilla de variables de entorno
│   ├── nextcloud/
│   ├── gitea/
│   └── mariadb/               # Servicio de dependencia (no expuesto)
│
├── infra/                     # Infraestructura global permanente
│   ├── proxy/                 # Nginx Proxy Manager + MariaDB interna
│   ├── authelia/              # SSO y 2FA
│   ├── cloudflared/           # Cloudflare Tunnel
│   ├── portainer/             # Gestión de contenedores
│   ├── monitoring/            # Prometheus + Grafana + Node Exporter
│   ├── alertmanager/          # Alertas + Bot Telegram
│   ├── api/                   # Capa FastAPI (Python)
│   └── dashboard/             # Panel administrativo (PHP)
│
├── scripts/                   # Motor de orquestación
│   ├── deploy.sh              # Orquestador principal
│   ├── destroy.sh             # Eliminación con backup automático
│   ├── list.sh                # Listado de servicios activos
│   ├── get-credentials.sh     # Recuperación de credenciales
│   ├── catalogo-deps.sh       # Resolución de dependencias
│   ├── config.env             # Configuración global del motor
│   └── funciones/             # Módulos Bash reutilizables
│       ├── npm.sh             # Driver API REST de Nginx Proxy Manager
│       ├── puertos.sh         # Gestión dinámica de puertos
│       ├── db.sh              # Persistencia híbrida (txt + MariaDB)
│       ├── validaciones.sh    # Validación con Regex
│       └── seguridad.sh       # Escaneo de imágenes con Trivy
│
└── docs/                      # Documentación técnica
```
 
---
 
## Flujo de Despliegue
 
Cada llamada a `deploy.sh` ejecuta el siguiente pipeline de forma atómica (protegido con `flock` para evitar colisiones de procesos concurrentes):
 
```
1. VALIDACIÓN          validaciones.sh → Regex sobre nombre de empresa y servicio
         │
2. DEPENDENCIAS        catalogo-deps.sh → Lectura de config.yml; despliegue
         │              previo de dependencias si no existen (ej: MariaDB para WordPress)
         │
3. PUERTO              puertos.sh → Selección de puerto libre en rango 8100-8999
         │              (doble validación: ss -tlnp + registro interno)
         │
4. CREDENCIALES        seguridad.sh → Generación de contraseñas únicas por despliegue
         │              Guardado en JSON con chmod 600
         │
5. PLANTILLAS          sed → Procesado de docker-compose.tpl y env.tpl
         │              con inyección de variables en tiempo de ejecución
         │
6. DESPLIEGUE          docker compose up -d en red aislada {{EMPRESA}}_net
         │
7. PROXY + SSL         npm.sh → Llamada a la API REST de NPM para crear
         │              el Proxy Host y solicitar el certificado SSL
         │
8. REGISTRO            db.sh → Persistencia en archivo .txt (velocidad)
                        y en MariaDB users_db (visibilidad del Dashboard)
```
 
---
 
## Catálogo de Servicios
 
Los servicios disponibles se definen en `/catalogo`. Añadir un nuevo servicio al catálogo es tan simple como crear una carpeta con tres ficheros:
 
### `config.yml` — Manifiesto del servicio
 
```yaml
nombre: wordpress
imagen: wordpress:latest
descripcion: "CMS WordPress con base de datos dedicada"
dependencias:
  - mariadb
puerto_interno: 80
variables:
  - WORDPRESS_DB_HOST
  - WORDPRESS_DB_NAME
  - WORDPRESS_DB_USER
  - WORDPRESS_DB_PASSWORD
```
 
El motor resuelve las dependencias automáticamente. Si `mariadb` no está desplegada para esa empresa, se despliega antes de continuar.
 
### Servicios disponibles actualmente
 
| Servicio | Descripción | Dependencias |
|---|---|---|
| `wordpress` | CMS con base de datos dedicada | `mariadb` |
| `nextcloud` | Almacenamiento en nube privada | `mariadb` |
| `gitea` | Forja de repositorios Git | `mariadb` |
| `mariadb` | Base de datos relacional | — |
 
---
 
## Infraestructura Global
 
Servicios que corren de forma permanente y son compartidos por todos los tenants:
 
### Exposición y Seguridad Perimetral
 
- **Cloudflare Tunnel (`cloudflared`)**: el servidor establece una conexión saliente cifrada hacia Cloudflare. No hay ningún puerto abierto en el firewall hacia internet. La superficie de ataque perimetral es cero.
- **Nginx Proxy Manager (NPM)**: proxy inverso que gestiona el ruteo de subdominios y la emisión automática de certificados SSL (Let's Encrypt vía Cloudflare DNS).
- **Authelia**: capa de Single Sign-On (SSO) con autenticación de dos factores (2FA/TOTP) integrada con NPM para proteger todos los paneles de gestión internos.
### Gestión y Operaciones
 
- **Portainer**: interfaz de gestión de contenedores Docker, accesible vía socket `/var/run/docker.sock` y protegida por Authelia.
- **FastAPI (Python)**: capa de API REST que recibe peticiones del Dashboard y ejecuta los scripts de Bash del motor de orquestación.
- **Dashboard (PHP)**: panel administrativo que consulta la base de datos `users_db` (MariaDB) para mostrar el estado de todos los tenants y servicios.
### Monitorización y Alertas
 
- **Prometheus + Node Exporter**: recogida de métricas del sistema (CPU, RAM, disco, red) y de los contenedores.
- **Grafana**: visualización de métricas con dashboards preconfigurados.
- **Alertmanager + Bot Telegram**: notificaciones automáticas en Telegram ante alertas críticas (caída de servicio, uso excesivo de recursos, etc.).
---
 
## Aislamiento Multi-Tenant
 
El aislamiento entre empresas se implementa en tres capas independientes:
 
| Capa | Implementación | Resultado |
|---|---|---|
| **Red** | Red bridge dedicada `{{EMPRESA}}_net` por tenant | Los contenedores de distintas empresas no pueden comunicarse entre sí |
| **Datos** | Volúmenes en `/data/{{EMPRESA}}/{{SERVICIO}}` | Separación física en disco; sin rutas compartidas entre tenants |
| **Secretos** | JSON por despliegue con `chmod 600` | Solo el proceso propietario puede leer las credenciales |
 
---
 
## Seguridad y Hardening
 
Se ha aplicado una política de **reducción de superficie de ataque** en todas las capas:
 
- **Zero-Exposure**: UFW y fail2ban se eliminaron del host al delegar la protección perimetral completamente al túnel de Cloudflare. Sin puertos abiertos, no hay nada que filtrar.
- **SSH sin contraseña**: acceso al host limitado estrictamente a autenticación por clave pública. Las contraseñas SSH están desactivadas.
- **Credenciales únicas**: cada despliegue genera contraseñas aleatorias distintas, almacenadas en JSON con permisos `600`.
- **Escaneo de imágenes (experimental)**: integración con [Trivy](https://github.com/aquasecurity/trivy) en `seguridad.sh` para analizar vulnerabilidades conocidas en las imágenes del catálogo antes de desplegar.
### Pendiente de implementar
 
- [ ] **`docker-socket-proxy`**: limitar el acceso de Portainer al socket de Docker mediante un proxy que restrinja las operaciones permitidas. El acceso actual al socket completo representa un vector de escalada de privilegios conocido.
---
 
## Requisitos
 
| Componente | Versión mínima |
|---|---|
| Docker Engine | 20.10+ |
| Docker Compose | v2+ (plugin integrado) |
| Bash | 4.0+ |
| Sistema operativo | Ubuntu 22.04 / Debian 12 (recomendado) |
| `util-linux` (`flock`, `ss`) | Incluido en la mayoría de distribuciones |
 
---
 
## Instalación y Configuración
 
### 1. Clonar el repositorio
 
```bash
git clone https://github.com/osanchezvic/SaaS-multiempresa-ASIR.git
cd SaaS-multiempresa-ASIR
```
 
### 2. Configurar variables de entorno
 
Copiar y editar el fichero de configuración del motor:
 
```bash
cp scripts/config.env.example scripts/config.env
nano scripts/config.env
```
 
Variables obligatorias:
 
```bash
# Directorio de datos de los tenants
DATA_DIR=/srv
 
# Rango de puertos para los servicios
PUERTO_MIN=8100
PUERTO_MAX=8999
 
# Base de datos del Dashboard
DB_HOST=localhost
DB_NAME=users_db
DB_USER=tensaas
DB_PASS=tu_contraseña_segura
 
# Credenciales de Nginx Proxy Manager
NPM_API_URL=http://localhost:81
NPM_USER=admin@example.com
NPM_PASSWORD=tu_contraseña_npm
 
# Dominio base para los subdominos de tenant
DOMINIO_BASE=tudominio.com
```
 
Variables de la infraestructura global:
 
```bash
GRAFANA_ADMIN_PASSWORD=...
NPM_DB_PASSWORD=...
NPM_DB_ROOT_PASSWORD=...
```
 
### 3. Levantar la infraestructura global
 
```bash
cd infra/
docker compose up -d
```
 
### 4. Dar permisos de ejecución a los scripts
 
```bash
chmod +x scripts/*.sh scripts/funciones/*.sh
```
 
---
 
## Uso
 
### Desplegar un servicio para una empresa
 
```bash
./scripts/deploy.sh <empresa> <servicio>
 
# Ejemplo: desplegar WordPress para "acmecorp"
./scripts/deploy.sh acmecorp wordpress
```
 
El sistema valida el nombre, resuelve dependencias, asigna puerto, procesa plantillas, despliega los contenedores en la red aislada de `acmecorp` y registra el proxy con SSL automáticamente.
 
### Referencia de comandos
 
| Comando | Descripción |
|---|---|
| `./scripts/deploy.sh <empresa> <servicio>` | Despliega un servicio y resuelve sus dependencias |
| `./scripts/destroy.sh <empresa> <servicio>` | Elimina el servicio generando un backup `.tar.gz` previo |
| `./scripts/list.sh [empresa] [formato]` | Lista servicios activos (formatos: `tabla`, `json`, `csv`) |
| `./scripts/get-credentials.sh <empresa> <servicio>` | Muestra las credenciales de acceso de un servicio |
| `./scripts/catalogo-deps.sh <servicio>` | Muestra el árbol de dependencias de un servicio |
 
---
 
## Licencia
 
Este proyecto se distribuye bajo la licencia MIT. Consulta el fichero [LICENSE](LICENSE) para más información.
 
---
 
<div align="center">
Proyecto desarrollado por **Óscar Sánchez** como Proyecto Final de Ciclo — ASIR
 
</div>
 

