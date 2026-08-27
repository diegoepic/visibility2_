-- ============================================================
-- 26_studio_dashboards.sql
-- Fase 1 de "Visibility Studio": persistencia real de los dashboards
-- creados en portal/modulos/mod_create_dashboard.
--
-- Hoy "Descargar diseño" solo baja un JSON al equipo del usuario; no
-- queda nada guardado en el servidor y el punto de partida son 6
-- plantillas de canal fijas en index.php. Estas tablas permiten:
--   - Guardar un dashboard armado y volver a abrirlo.
--   - Marcarlo como plantilla reutilizable por la división (reemplaza
--     de a poco a las 6 plantillas fijas de canal).
--   - Preparar el terreno para multi-hoja (Fase 4): cada dashboard ya
--     tiene 1..N filas en studio_dashboard_hoja, aunque por ahora el
--     editor solo usa una.
--
-- El contenido del lienzo (elementos, formas, KPIs, gráficos) se guarda
-- tal cual lo arma app.js hoy -- el mismo objeto que ya arma la función
-- "Descargar diseño" ({version, template, scope, canvas, elements}) --
-- en vez de normalizar cada elemento en columnas propias. Es el mismo
-- criterio que ya usa dashboard_items para el HTML del iframe: una
-- columna de contenido serializado, no un esquema nuevo por tipo de
-- elemento.
-- ============================================================

CREATE TABLE IF NOT EXISTS `studio_dashboard` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_empresa` int NOT NULL,
  `id_division` int NOT NULL,
  `id_subdivision` int DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `id_template_origen` varchar(50) DEFAULT NULL COMMENT 'id de la plantilla de canal usada al crear (almacen, supermercado, etc.), solo referencial',
  `es_plantilla` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'visible como punto de partida para toda la división, no solo para su dueño',
  `id_usuario_creador` int NOT NULL,
  `id_usuario_modifico` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL COMMENT 'borrado logico -- se filtra en cada consulta, nunca se hace DELETE fisico desde el modulo',
  PRIMARY KEY (`id`),
  KEY `idx_studio_dashboard_scope` (`id_empresa`,`id_division`),
  KEY `idx_studio_dashboard_creador` (`id_usuario_creador`),
  KEY `idx_studio_dashboard_plantilla` (`id_division`,`es_plantilla`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `studio_dashboard_hoja` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_dashboard` int NOT NULL,
  `orden` int NOT NULL DEFAULT '1',
  `nombre` varchar(100) NOT NULL DEFAULT 'Hoja 1',
  `contenido_json` longtext NOT NULL COMMENT 'JSON completo del lienzo: {version, template, scope, canvas, elements}',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_studio_hoja_dashboard` (`id_dashboard`,`orden`),
  CONSTRAINT `fk_studio_hoja_dashboard` FOREIGN KEY (`id_dashboard`) REFERENCES `studio_dashboard` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- Verificación rápida post-migración
-- ------------------------------------------------------------
-- SHOW TABLES LIKE 'studio_dashboard%';
-- DESCRIBE studio_dashboard;
-- DESCRIBE studio_dashboard_hoja;
