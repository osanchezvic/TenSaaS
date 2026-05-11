-- Base de datos para usuarios multiempresa
-- Tabla de usuarios: empresa, usuario, hash_password (bcrypt), rol

CREATE DATABASE IF NOT EXISTS users_db;

USE users_db;

CREATE TABLE IF NOT EXISTS empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT,
    estado ENUM('activa', 'inactiva') DEFAULT 'activa',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NULL,
    empresa VARCHAR(100) NULL,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    hash_password VARCHAR(255) NOT NULL,
    rol VARCHAR(20) DEFAULT 'user',
    es_admin TINYINT(1) DEFAULT 0,
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS servicios_contratados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    nombre_servicio VARCHAR(100) NOT NULL,
    tipo VARCHAR(50),
    puerto INT,
    url_admin VARCHAR(255),
    estado ENUM('activo', 'inactivo', 'eliminado') DEFAULT 'activo',
    fecha_contratacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    accion VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Insertar empresa admin global si no existe
INSERT IGNORE INTO empresas (id, nombre, descripcion) VALUES (1, 'SaaS_Global', 'Administración Global del Sistema');

-- Usuario admin por defecto (admin / password)
-- Hash para 'password'
INSERT IGNORE INTO usuarios (empresa_id, empresa, usuario, hash_password, es_admin, rol) 
VALUES (1, 'SaaS_Global', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'admin');
