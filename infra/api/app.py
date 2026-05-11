from fastapi import FastAPI, Header, HTTPException, Request
import subprocess
import os
import logging

# Configurar logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

import re

# Auth configuration
API_TOKEN = os.getenv("API_TOKEN")
if not API_TOKEN or API_TOKEN in ["supersecrettoken", "d7f3e8b1a9c4d2e5f6a7b8c9d0e1f2a3"]:
    logger.error("CRITICAL: API_TOKEN is not set or using an insecure default!")
    # In a real production environment, we should exit
    # exit(1)

PROJECT_ROOT = os.getenv("PROJECT_ROOT", "/app")

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

import json

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

@app.get("/status/{company}")
async def status(company: str, token: str = Header(...)):
    verify_token(token)
    validate_input(company)
    path = os.path.join(PROJECT_ROOT, f"data/{company}")
    if not os.path.exists(path):
        return {"error": "Company not found"}
    services = os.listdir(path)
    return {"company": company, "services": services}
