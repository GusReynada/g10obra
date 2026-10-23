-- Base de datos completa para G10 Obra
-- Compatible con MySQL / phpMyAdmin en Hostinger

CREATE DATABASE IF NOT EXISTS `g10obra_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `g10obra_db`;

-- 1. Tabla de Administradores
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuario admin inicial: usuario 'admin', clave 'admin123' (hash bcrypt)
INSERT INTO `admins` (`username`, `email`, `password`) VALUES
('admin', 'admin@g10obra.com', '$2y$10$WpZ6/n6gM5M093k6tZkC3.0fJk90qS2z7r5K1hE5r4y0u1c8.Oge.')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- 2. Tabla de Mensajes de Contacto
CREATE TABLE IF NOT EXISTS `contact_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(50) NULL,
    `subject` VARCHAR(200) NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('unread', 'read', 'archived') DEFAULT 'unread',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabla de Obras / Proyectos
CREATE TABLE IF NOT EXISTS `projects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `category` VARCHAR(100) DEFAULT 'Residencial',
    `location` VARCHAR(200) NULL,
    `year` VARCHAR(10) NULL,
    `surface_area` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `main_image` VARCHAR(255) NULL,
    `featured` TINYINT(1) DEFAULT 0,
    `status` ENUM('draft', 'published') DEFAULT 'published',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Proyectos de ejemplo iniciales
INSERT INTO `projects` (`title`, `slug`, `category`, `location`, `year`, `surface_area`, `description`, `main_image`, `featured`, `status`) VALUES
('Residencia V&M', 'residencia-vm', 'Residencial', 'Punta Chica, Buenos Aires', '2026', '480 m²', 'Vivienda unifamiliar de hormigón visto, amplios ventanales y patios interiores.', 'assets/img/project-1.jpg', 1, 'published'),
('Edificio Nexo', 'edificio-nexo', 'Comercial', 'Distrito Financiero', '2025', '1.200 m²', 'Sede corporativa bioclimática con certificación de sustentabilidad y piel vidriada.', 'assets/img/project-2.jpg', 1, 'published'),
('Loft Histórico', 'loft-historico', 'Remodelación', 'San Telmo', '2025', '210 m²', 'Puesta en valor de edificio patrimonial preservando techos altos y muros de ladrillo visto.', 'assets/img/project-3.jpg', 1, 'published')
ON DUPLICATE KEY UPDATE `slug`=`slug`;
