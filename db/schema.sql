-- ============================================================
--  Banco de Preguntas – Desarrollo de Software
--  Esquema de base de datos (MySQL / MariaDB - XAMPP)
--  Copiar y pegar completo en phpMyAdmin > Pestaña "SQL" > Ejecutar
-- ============================================================

CREATE DATABASE IF NOT EXISTS `banco_preguntas`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `banco_preguntas`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Áreas temáticas (Redes, Bases de Datos, Programación, Soporte)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `areas` (
  `id`     TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  `slug`   VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_areas_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Preguntas del banco (400)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `preguntas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `area_id`       TINYINT UNSIGNED NOT NULL,
  `numero`        SMALLINT UNSIGNED NOT NULL,
  `enunciado`     TEXT NOT NULL,
  `explicacion`   TEXT NOT NULL,
  `veces_vista`   INT UNSIGNED NOT NULL DEFAULT 0,
  `veces_fallada` INT UNSIGNED NOT NULL DEFAULT 0,
  `activa`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_preguntas_area_numero` (`area_id`, `numero`),
  KEY `idx_preguntas_area` (`area_id`),
  CONSTRAINT `fk_preguntas_area`
    FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Opciones de cada pregunta (a, b, c, d) - una marcada correcta
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `opciones` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pregunta_id` INT UNSIGNED NOT NULL,
  `letra`       CHAR(1) NOT NULL,
  `texto`       TEXT NOT NULL,
  `es_correcta` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opciones_pregunta_letra` (`pregunta_id`, `letra`),
  KEY `idx_opciones_pregunta` (`pregunta_id`),
  CONSTRAINT `fk_opciones_pregunta`
    FOREIGN KEY (`pregunta_id`) REFERENCES `preguntas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Sesiones de estudio/examen (una por ronda jugada)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sesiones` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `modo`          ENUM('estudio','examen','errores') NOT NULL,
  `area_id`       TINYINT UNSIGNED NULL,
  `alias`         VARCHAR(24) NULL,
  `semilla`       INT UNSIGNED NOT NULL DEFAULT 0,
  `jugador`       CHAR(32) NULL,
  `total`         SMALLINT UNSIGNED NOT NULL,
  `posicion`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `respondidas`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `aciertos`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `estado`        ENUM('jugando','finalizada') NOT NULL DEFAULT 'jugando',
  `cola`          TEXT NULL,
  `iniciada_at`   DATETIME NOT NULL,
  `finalizada_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sesiones_estado` (`estado`),
  KEY `idx_sesiones_area` (`area_id`),
  CONSTRAINT `fk_sesiones_area`
    FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Respuestas individuales (historial: qué se contestó y si acertó)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `respuestas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sesion_id`     INT UNSIGNED NOT NULL,
  `pregunta_id`   INT UNSIGNED NOT NULL,
  `letra_elegida` CHAR(1) NULL,
  `correcta`      TINYINT(1) NOT NULL DEFAULT 0,
  `respondida_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_respuestas_sesion_pregunta` (`sesion_id`, `pregunta_id`),
  KEY `idx_respuestas_sesion` (`sesion_id`),
  KEY `idx_respuestas_pregunta` (`pregunta_id`),
  CONSTRAINT `fk_respuestas_sesion`
    FOREIGN KEY (`sesion_id`) REFERENCES `sesiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_respuestas_pregunta`
    FOREIGN KEY (`pregunta_id`) REFERENCES `preguntas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
