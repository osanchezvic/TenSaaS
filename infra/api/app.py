from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
import subprocess
import os
import logging
import json
import yaml
import re
import resend

# Configurar logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

# Configurar CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Auth configuration
API_TOKEN = os.getenv("API_TOKEN")
if not API_TOKEN or API_TOKEN in ["supersecrettoken", "d7f3e8b1a9c4d2e5f6a7b8c9d0e1f2a3"]:
    logger.error("CRITICAL: API_TOKEN is not set or using an insecure default!")
    # In a real production environment, we should exit
    # exit(1)

PROJECT_ROOT = os.getenv("PROJECT_ROOT", "/app")
USERS_YML_PATH = os.path.join(PROJECT_ROOT, "infra/authelia/config/users.yml")

def validate_input(name: str):
    if not re.match(r"^[a-zA-Z0-9_-]+$", name):
        raise HTTPException(status_code=400, detail=f"Invalid name: {name}. Only alphanumeric, hyphens and underscores allowed.")

def verify_token(token: str = Header(...)):
    if not API_TOKEN or token != API_TOKEN:
        logger.warning(f"Intento de acceso con token inválido o no configurado.")
        raise HTTPException(status_code=403, detail="Invalid or unconfigured token")

@app.post("/deploy/{company}/{service}")
async def deploy(company: str, service: str, token: str = Header(...)):
    verify_token(token)
    validate_input(company)
    validate_input(service)
    logger.info(f"Iniciando despliegue: {company}/{service}")
    
    deploy_script = os.path.join(PROJECT_ROOT, "scripts/deploy.sh")
    
    if not os.path.exists(deploy_script):
        logger.error(f"Script no encontrado: {deploy_script}")
        raise HTTPException(status_code=500, detail="Deployment script not found")

    try:
        # Ejecutar el script capturando la salida
        result = subprocess.run(
            [deploy_script, company, service], 
            capture_output=True, 
            text=True,
            env={**os.environ, "FORCE_MODE": "1"}
        )
        
        if result.returncode != 0:
            logger.error(f"Error en el script de despliegue (Code {result.returncode}): {result.stderr}")
            return {
                "status": "error",
                "message": "Deployment failed",
                "stdout": result.stdout,
                "stderr": result.stderr,
                "returncode": result.returncode
            }
            
        logger.info(f"Despliegue completado con éxito: {company}/{service}")
        return {
            "status": "success",
            "stdout": result.stdout,
            "returncode": result.returncode
        }
    except Exception as e:
        logger.error(f"Excepción durante el despliegue: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/contact")
async def contact(request: Request):
    logger.info("Recibida petición en /contact")
    try:
        data = await request.json()
    except Exception as e:
        raise HTTPException(status_code=400, detail="Invalid JSON body")

    nombre = data.get("nombre")
    email = data.get("email")
    mensaje = data.get("mensaje")

    if not nombre or not email or not mensaje:
        raise HTTPException(status_code=400, detail="Missing required fields")

    resend.api_key = os.getenv("RESEND_API_KEY")
    
    params = {
        "from": os.getenv("RESEND_FROM_EMAIL"),
        "to": [os.getenv("RESEND_TO_EMAIL")],
        "subject": f"Nuevo mensaje de contacto: {nombre}",
        "html": f"<p><strong>Nombre:</strong> {nombre}</p><p><strong>Email:</strong> {email}</p><p><strong>Mensaje:</strong><br>{mensaje}</p>"
    }
    
    try:
        resend.Emails.send(params)
        logger.info("Email enviado exitosamente vía Resend")
    except Exception as e:
        logger.error(f"Error enviando email: {str(e)}")
        raise HTTPException(status_code=500, detail="Error enviando el correo")

    return {"status": "success", "message": "Mensaje recibido correctamente"}

@app.post("/auth/sync_user")
async def sync_user(request: Request, token: str = Header(...)):
    verify_token(token)
    try:
        data = await request.json()
    except:
        raise HTTPException(status_code=400, detail="Invalid JSON body")
        
    username = data.get("username")
    password = data.get("password")
    display_name = data.get("display_name")
    email = data.get("email")
    groups = data.get("groups", ["users"])
    
    if not username or not password:
        raise HTTPException(status_code=400, detail="Username and password are required")

    # Generar Hash usando el propio Authelia para compatibilidad total
    try:
        logger.info(f"Generando hash para usuario: {username}")
        cmd = [
            "docker", "run", "--rm", "authelia/authelia:latest", 
            "authelia", "crypto", "hash", "generate", "argon2", "--password", password
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            logger.error(f"Fallo comando docker authelia: {result.stderr}")
            raise Exception(f"Fallo al generar hash: {result.stderr}")
        
        if "Digest: " not in result.stdout:
            logger.error(f"Salida inesperada de authelia: {result.stdout}")
            raise Exception("No se encontró el Digest en la salida de Authelia")
            
        digest = result.stdout.split("Digest: ")[1].strip()
        logger.info(f"Hash generado con éxito para {username}")
    except Exception as e:
        logger.error(f"Error generando hash Argon2: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Error al generar hash de contraseña: {str(e)}")

    # Actualizar users.yml
    try:
        config = {"users": {}}
        if os.path.exists(USERS_YML_PATH):
            with open(USERS_YML_PATH, 'r') as f:
                config = yaml.safe_load(f) or {"users": {}}
        
        if "users" not in config:
            config["users"] = {}
            
        config["users"][username] = {
            "displayname": display_name or username,
            "password": digest,
            "email": email or f"{username}@tensaas.es",
            "groups": groups
        }
        
        with open(USERS_YML_PATH, 'w') as f:
            yaml.dump(config, f, default_flow_style=False)
            
        # Reiniciar Authelia para aplicar cambios
        subprocess.run(["docker", "restart", "authelia"], capture_output=True)
        
        return {"status": "success", "message": f"Usuario {username} sincronizado con Authelia"}
    except Exception as e:
        logger.error(f"Error actualizando users.yml: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Error al actualizar configuración de Authelia: {str(e)}")

@app.post("/auth/remove_user/{username}")
async def remove_user(username: str, token: str = Header(...)):
    verify_token(token)
    try:
        if not os.path.exists(USERS_YML_PATH):
            return {"status": "success", "message": "No users.yml found"}
            
        with open(USERS_YML_PATH, 'r') as f:
            config = yaml.safe_load(f)
        
        if config and "users" in config and username in config["users"]:
            del config["users"][username]
            with open(USERS_YML_PATH, 'w') as f:
                yaml.dump(config, f, default_flow_style=False)
            subprocess.run(["docker", "restart", "authelia"], capture_output=True)
            return {"status": "success", "message": f"Usuario {username} eliminado de Authelia"}
        
        return {"status": "success", "message": f"El usuario {username} no existía en Authelia"}
    except Exception as e:
        logger.error(f"Error eliminando usuario: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Error al actualizar configuración de Authelia: {str(e)}")

@app.get("/api/v1/system/status")
async def system_status(token: str = Header(...)):
    verify_token(token)
    try:
        # Obtener información de todos los contenedores usando docker inspect
        # Formato: Nombre, Estado, Imagen, Puertos
        cmd = [
            "docker", "ps", "-a", 
            "--format", '{"name":"{{.Names}}", "status":"{{.Status}}", "state":"{{.State}}", "image":"{{.Image}}", "ports":"{{.Ports}}"}'
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        
        if result.returncode != 0:
            raise HTTPException(status_code=500, detail="Error getting docker status")

        # Convertir la salida (una línea por contenedor) a una lista de diccionarios
        lines = result.stdout.strip().split("\n")
        containers = [json.loads(line) for line in lines if line]
        
        return {"status": "success", "containers": containers}
    except Exception as e:
        logger.error(f"Error en system_status: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/destroy/{company}/{service}")
async def destroy(company: str, service: str, token: str = Header(...)):
    verify_token(token)
    validate_input(company)
    validate_input(service)
    logger.info(f"Iniciando destrucción: {company}/{service}")

    destroy_script = os.path.join(PROJECT_ROOT, "scripts/destroy.sh")

    if not os.path.exists(destroy_script):
        logger.error(f"Script no encontrado: {destroy_script}")
        raise HTTPException(status_code=500, detail="Destroy script not found")

    try:
        result = subprocess.run(
            [destroy_script, company, service], 
            capture_output=True, 
            text=True,
            env={**os.environ, "FORCE_MODE": "1"}
        )
        
        if result.returncode != 0:
            logger.error(f"Error en el script de destrucción: {result.stderr}")
            return {
                "status": "error",
                "message": "Destruction failed",
                "stderr": result.stderr
            }
            
        return {"status": "success", "message": "Service destroyed"}
    except Exception as e:
        logger.error(f"Excepción durante la destrucción: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/delete_company/{company}")
async def delete_company(company: str, token: str = Header(...)):
    verify_token(token)
    validate_input(company)
    logger.info(f"Iniciando eliminación completa de empresa: {company}")

    delete_script = os.path.join(PROJECT_ROOT, "scripts/delete_company.sh")

    if not os.path.exists(delete_script):
        logger.error(f"Script no encontrado: {delete_script}")
        raise HTTPException(status_code=500, detail="Delete company script not found")

    try:
        result = subprocess.run(
            [delete_script, company], 
            capture_output=True, 
            text=True,
            env={**os.environ, "FORCE_MODE": "1"}
        )
        
        if result.returncode != 0:
            logger.error(f"Error en el script de eliminación de empresa: {result.stderr}")
            return {
                "status": "error",
                "message": "Company deletion failed",
                "stderr": result.stderr,
                "stdout": result.stdout
            }
            
        return {"status": "success", "message": "Company deleted"}
    except Exception as e:
        logger.error(f"Excepción durante la eliminación de empresa: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/status/{company}")
async def status(company: str, token: str = Header(...)):
    verify_token(token)
    validate_input(company)
    path = os.path.join(PROJECT_ROOT, f"data/{company}")
    if not os.path.exists(path):
        return {"error": "Company not found"}
    services = os.listdir(path)
    return {"company": company, "services": services}
