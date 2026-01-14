-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 14-01-2026 a las 13:22:23
-- Versión del servidor: 8.0.44
-- Versión de PHP: 8.1.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `visibility_visibility2`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cadena`
--

CREATE TABLE `cadena` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_cuenta` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `canal`
--

CREATE TABLE `canal` (
  `id` int NOT NULL,
  `nombre_canal` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `client_devices`
--

CREATE TABLE `client_devices` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `device_id` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL,
  `app_version` varchar(32) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `platform` varchar(16) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `last_seen_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_sync_full_at` datetime DEFAULT NULL,
  `last_manifest_etag` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comuna`
--

CREATE TABLE `comuna` (
  `id` int NOT NULL,
  `comuna` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_region` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cuenta`
--

CREATE TABLE `cuenta` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `dashboard_items`
--

CREATE TABLE `dashboard_items` (
  `id` int NOT NULL,
  `image_url` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `target_url` varchar(355) COLLATE utf8mb3_unicode_ci NOT NULL,
  `main_label` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `sub_label` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `icon_class` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_empresa` int DEFAULT NULL,
  `id_division` int DEFAULT NULL,
  `orden` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `distrito`
--

CREATE TABLE `distrito` (
  `id` int NOT NULL,
  `nombre_distrito` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_zona` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `division_empresa`
--

CREATE TABLE `division_empresa` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_empresa` int DEFAULT NULL,
  `estado` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empresa`
--

CREATE TABLE `empresa` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `activo` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `encuesta`
--

CREATE TABLE `encuesta` (
  `id` int NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT NULL,
  `fecha_termino` datetime DEFAULT NULL,
  `id_local` int DEFAULT NULL,
  `id_usuario` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `export_jobs`
--

CREATE TABLE `export_jobs` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL,
  `params` json NOT NULL,
  `status` enum('pending','running','done','error') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'pending',
  `file_path` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `error_message` text COLLATE utf8mb3_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `formulario`
--

CREATE TABLE `formulario` (
  `id` int NOT NULL,
  `nombre` varchar(200) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fechaInicio` datetime DEFAULT NULL,
  `fechaTermino` datetime DEFAULT NULL,
  `estado` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `tipo` int DEFAULT NULL,
  `iw_requiere_local` tinyint(1) NOT NULL DEFAULT '0',
  `id_empresa` int DEFAULT '0',
  `id_division` int DEFAULT '0',
  `id_subdivision` int DEFAULT '0',
  `url_bi` varchar(355) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `reference_image` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `modalidad` enum('implementacion_auditoria','solo_implementacion','solo_auditoria','complementaria','retiro','entrega') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'implementacion_auditoria',
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `formularioQuestion`
--

CREATE TABLE `formularioQuestion` (
  `id` int NOT NULL,
  `pregunta` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `motivo` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `material` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `valor` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `valor_propuesto` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fechaVisita` datetime DEFAULT NULL,
  `countVisita` int DEFAULT '0',
  `observacion` varchar(360) COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_formulario` int DEFAULT NULL,
  `id_local` int DEFAULT NULL,
  `id_usuario` int DEFAULT NULL,
  `estado` int DEFAULT '0',
  `is_priority` tinyint(1) DEFAULT '0',
  `latGestion` decimal(9,6) DEFAULT NULL,
  `lngGestion` decimal(9,6) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fechaPropuesta` date DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_questions`
--

CREATE TABLE `form_questions` (
  `id` int NOT NULL,
  `id_formulario` int NOT NULL,
  `question_text` varchar(400) COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_question_type` int NOT NULL,
  `id_question_set_question` int DEFAULT NULL,
  `id_dependency_option` int DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_required` tinyint DEFAULT '0',
  `is_valued` tinyint(1) DEFAULT '0',
  `question_text_norm` varchar(400) COLLATE utf8mb3_unicode_ci GENERATED ALWAYS AS (lower(trim(`question_text`))) STORED,
  `v_signature` char(32) COLLATE utf8mb3_unicode_ci GENERATED ALWAYS AS (md5(concat(`question_text_norm`,_utf8mb3'|',`id_question_type`))) STORED,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_question_options`
--

CREATE TABLE `form_question_options` (
  `id` int NOT NULL,
  `id_form_question` int NOT NULL,
  `id_question_set_option` int DEFAULT NULL,
  `option_text` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `sort_order` int DEFAULT '0',
  `reference_image` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_question_photo_meta`
--

CREATE TABLE `form_question_photo_meta` (
  `id` int NOT NULL,
  `resp_id` int NOT NULL,
  `visita_id` int NOT NULL,
  `id_local` int NOT NULL,
  `id_usuario` int NOT NULL,
  `foto_url` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `exif_datetime` datetime DEFAULT NULL,
  `exif_lat` decimal(10,7) DEFAULT NULL,
  `exif_lng` decimal(10,7) DEFAULT NULL,
  `exif_altitude` decimal(8,2) DEFAULT NULL,
  `exif_img_direction` decimal(6,2) DEFAULT NULL,
  `exif_make` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exif_model` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exif_software` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exif_lens_model` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exif_fnumber` decimal(4,2) DEFAULT NULL,
  `exif_exposure_time` varchar(16) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `exif_iso` int UNSIGNED DEFAULT NULL,
  `exif_focal_length` decimal(5,1) DEFAULT NULL,
  `exif_orientation` tinyint UNSIGNED DEFAULT NULL,
  `capture_source` enum('camera','gallery','unknown') COLLATE utf8mb4_general_ci DEFAULT 'unknown',
  `meta_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `form_question_responses`
--

CREATE TABLE `form_question_responses` (
  `id` int NOT NULL,
  `visita_id` int NOT NULL,
  `id_form_question` int NOT NULL,
  `id_local` int NOT NULL,
  `id_usuario` int NOT NULL,
  `answer_text` varchar(400) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `foto_visita_id` int DEFAULT NULL,
  `id_option` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `valor` decimal(10,2) DEFAULT NULL,
  `answer_text_norm` varchar(400) COLLATE utf8mb3_unicode_ci GENERATED ALWAYS AS (lower(trim(`answer_text`))) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fotoVisita`
--

CREATE TABLE `fotoVisita` (
  `id` int NOT NULL,
  `visita_id` int NOT NULL,
  `url` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `exif_datetime` datetime DEFAULT NULL,
  `id_usuario` int NOT NULL,
  `id_formulario` int NOT NULL,
  `id_local` int NOT NULL,
  `id_material` int DEFAULT NULL,
  `id_formularioQuestion` int DEFAULT NULL,
  `kind` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fotoLat` decimal(9,6) DEFAULT NULL,
  `fotoLng` decimal(9,6) DEFAULT NULL,
  `exif_lat` decimal(10,7) DEFAULT NULL,
  `exif_lng` decimal(10,7) DEFAULT NULL,
  `exif_altitude` decimal(8,2) DEFAULT NULL,
  `exif_img_direction` decimal(6,2) DEFAULT NULL,
  `exif_make` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exif_model` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exif_software` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exif_lens_model` varchar(128) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exif_fnumber` decimal(4,2) DEFAULT NULL,
  `exif_exposure_time` varchar(16) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exif_iso` int UNSIGNED DEFAULT NULL,
  `exif_focal_length` decimal(5,1) DEFAULT NULL,
  `exif_orientation` tinyint UNSIGNED DEFAULT NULL COMMENT '1..8 según EXIF',
  `capture_source` enum('camera','gallery','unknown') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'unknown',
  `meta_json` json DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gestion_visita`
--

CREATE TABLE `gestion_visita` (
  `id` int NOT NULL,
  `id_usuario` int NOT NULL,
  `id_formulario` int NOT NULL,
  `id_local` int NOT NULL,
  `id_formularioQuestion` int NOT NULL,
  `id_material` int NOT NULL,
  `fecha_visita` datetime NOT NULL,
  `estado_gestion` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `observacion` text COLLATE utf8mb4_general_ci,
  `valor_real` int DEFAULT NULL,
  `motivo_no_implementacion` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `foto_url` text COLLATE utf8mb4_general_ci,
  `lat_foto` decimal(10,6) DEFAULT NULL,
  `lng_foto` decimal(10,6) DEFAULT NULL,
  `latitud` decimal(10,6) DEFAULT NULL,
  `longitud` decimal(10,6) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `visita_id` int DEFAULT NULL,
  `foto_visita_id_estado` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `imagenes`
--

CREATE TABLE `imagenes` (
  `id` int NOT NULL,
  `url` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_pregunta` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `jefe_venta`
--

CREATE TABLE `jefe_venta` (
  `id` int NOT NULL,
  `nombre` varchar(200) COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `journal_event`
--

CREATE TABLE `journal_event` (
  `id` bigint UNSIGNED NOT NULL,
  `event_id` varchar(128) NOT NULL,
  `job_id` varchar(128) NOT NULL,
  `created_at` datetime NOT NULL,
  `type` varchar(64) NOT NULL,
  `status` varchar(64) DEFAULT NULL,
  `http_status` int DEFAULT NULL,
  `error_code` varchar(255) DEFAULT NULL,
  `message` text,
  `attempts` int DEFAULT NULL,
  `url` text,
  `payload` json DEFAULT NULL,
  `user_id` int NOT NULL,
  `empresa_id` int NOT NULL,
  `created_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `local`
--

CREATE TABLE `local` (
  `id` int NOT NULL,
  `codigo` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `direccion` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_cuenta` int DEFAULT NULL,
  `id_cadena` int DEFAULT NULL,
  `id_comuna` int DEFAULT '0',
  `id_empresa` int DEFAULT '0',
  `id_canal` int DEFAULT NULL,
  `id_subcanal` int DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `relevancia` int DEFAULT NULL,
  `id_zona` int DEFAULT NULL,
  `id_distrito` int DEFAULT NULL,
  `id_jefe_venta` int DEFAULT NULL,
  `id_vendedor` int DEFAULT NULL,
  `id_division` int DEFAULT '0',
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `local_priority`
--

CREATE TABLE `local_priority` (
  `id` int NOT NULL,
  `id_ejecutor` int DEFAULT NULL,
  `id_local` int DEFAULT NULL,
  `is_priority` tinyint(1) DEFAULT NULL,
  `fecha` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int NOT NULL,
  `usuario` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `intento_at` datetime NOT NULL,
  `user_id` int DEFAULT NULL,
  `outcome` enum('success','failure') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'failure',
  `reason` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `material`
--

CREATE TABLE `material` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `ref_image` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_division` int NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `opciones_pregunta`
--

CREATE TABLE `opciones_pregunta` (
  `id` int NOT NULL,
  `texto_opcion` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_pregunta` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pais`
--

CREATE TABLE `pais` (
  `id` int NOT NULL,
  `pais` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `panel_encuesta_log`
--

CREATE TABLE `panel_encuesta_log` (
  `id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `empresa_id` int NOT NULL,
  `accion` varchar(20) NOT NULL,
  `duracion_ms` int NOT NULL,
  `filas` int NOT NULL,
  `has_qfilters` tinyint(1) NOT NULL DEFAULT '0',
  `applied_30d_default` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_desde` varchar(20) DEFAULT NULL,
  `fecha_hasta` varchar(20) DEFAULT NULL,
  `creado_en` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `selector` char(16) COLLATE utf8mb3_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb3_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip` varbinary(16) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_reset_requests`
--

CREATE TABLE `password_reset_requests` (
  `id` int NOT NULL,
  `email` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `ip` varbinary(16) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `perfil`
--

CREATE TABLE `perfil` (
  `id` int NOT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pregunta`
--

CREATE TABLE `pregunta` (
  `id` int NOT NULL,
  `nombre_pregunta` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `tipo_pregunta` enum('seleccion_unica','seleccion_multiple','texto') COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_formulario` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `question_set`
--

CREATE TABLE `question_set` (
  `id` int NOT NULL,
  `nombre_set` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `question_set_options`
--

CREATE TABLE `question_set_options` (
  `id` int NOT NULL,
  `id_question_set_question` int NOT NULL,
  `option_text` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `reference_image` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `question_set_questions`
--

CREATE TABLE `question_set_questions` (
  `id` int NOT NULL,
  `id_question_set` int NOT NULL,
  `question_text` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_question_type` int NOT NULL,
  `sort_order` int DEFAULT '1',
  `is_required` tinyint(1) DEFAULT '0',
  `id_dependency_option` int DEFAULT NULL,
  `is_valued` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `question_type`
--

CREATE TABLE `question_type` (
  `id` int NOT NULL,
  `name` varchar(45) COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `region`
--

CREATE TABLE `region` (
  `id` int NOT NULL,
  `region` varchar(80) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `repo_archivo`
--

CREATE TABLE `repo_archivo` (
  `id` int NOT NULL,
  `id_usuario` int NOT NULL,
  `nombre_archivo` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `carpeta` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `ruta_relativa` text COLLATE utf8mb4_general_ci NOT NULL,
  `ruta_url` text COLLATE utf8mb4_general_ci NOT NULL,
  `tipo_archivo` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `tamano_bytes` bigint UNSIGNED NOT NULL,
  `estado` int NOT NULL,
  `observacion` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_actualizado` datetime DEFAULT NULL,
  `fecha_gestion` datetime DEFAULT NULL,
  `usuario_gestion` int DEFAULT NULL,
  `comentario_gestion` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `repo_carpeta`
--

CREATE TABLE `repo_carpeta` (
  `id` int NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `usuario_creador` int NOT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `request_log`
--

CREATE TABLE `request_log` (
  `id` bigint UNSIGNED NOT NULL,
  `idempotency_key` varchar(128) COLLATE utf8mb4_general_ci NOT NULL,
  `endpoint` varchar(191) COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `status_code` int DEFAULT NULL,
  `response_json` mediumtext COLLATE utf8mb4_general_ci,
  `created_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `respuesta`
--

CREATE TABLE `respuesta` (
  `id` int NOT NULL,
  `respuesta_texto` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `respuesta_opcion` int DEFAULT NULL,
  `respuesta_opciones` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_encuesta` int DEFAULT NULL,
  `id_pregunta` int DEFAULT NULL,
  `id_usuario` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ruta`
--

CREATE TABLE `ruta` (
  `id` bigint UNSIGNED NOT NULL,
  `usuario_id` bigint UNSIGNED NOT NULL,
  `fecha` date NOT NULL,
  `origen_lat` decimal(9,6) DEFAULT NULL,
  `origen_lng` decimal(9,6) DEFAULT NULL,
  `destino_lat` decimal(9,6) DEFAULT NULL,
  `destino_lng` decimal(9,6) DEFAULT NULL,
  `estado` enum('planeada','en_progreso','finalizada','cancelada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planeada',
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duracion_total_seg` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ruta_parada`
--

CREATE TABLE `ruta_parada` (
  `id` bigint UNSIGNED NOT NULL,
  `ruta_id` bigint UNSIGNED NOT NULL,
  `local_id` bigint UNSIGNED NOT NULL,
  `seq` int UNSIGNED NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `priority` enum('normal','alta') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `service_min` smallint UNSIGNED NOT NULL DEFAULT '0',
  `ventana_ini` datetime DEFAULT NULL,
  `ventana_fin` datetime DEFAULT NULL,
  `eta` datetime DEFAULT NULL,
  `visited_at` datetime DEFAULT NULL,
  `status` enum('pendiente','en_camino','visitado','omitido','cancelado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `nota` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `security_events`
--

CREATE TABLE `security_events` (
  `id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `type` varchar(40) COLLATE utf8mb3_unicode_ci NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `subcanal`
--

CREATE TABLE `subcanal` (
  `id` int NOT NULL,
  `nombre_subcanal` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_canal` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `subdivision`
--

CREATE TABLE `subdivision` (
  `id` int NOT NULL,
  `nombre` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `id_division` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipo`
--

CREATE TABLE `tipo` (
  `id` int NOT NULL,
  `descripcion` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ubicacion_ejecutor`
--

CREATE TABLE `ubicacion_ejecutor` (
  `id` int NOT NULL,
  `id_ejecutor` int NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `fecha_actualizacion` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_remember_tokens`
--

CREATE TABLE `user_remember_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `selector` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb3_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_security`
--

CREATE TABLE `user_security` (
  `user_id` int NOT NULL,
  `failed_count` int NOT NULL DEFAULT '0',
  `lock_until` datetime DEFAULT NULL,
  `consecutive_locks` int NOT NULL DEFAULT '0',
  `last_failed_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_success_ip` varbinary(16) DEFAULT NULL,
  `last_success_ua` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `notify_email` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` bigint NOT NULL,
  `user_id` int NOT NULL,
  `session_fpr` binary(32) NOT NULL,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `ip` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id` int NOT NULL,
  `rut` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `nombre` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `apellido` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `telefono` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `email` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `usuario` varchar(45) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fotoPerfil` varchar(500) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `clave` varchar(100) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fechaCreacion` datetime DEFAULT NULL,
  `activo` int DEFAULT '1',
  `id_empresa` int DEFAULT NULL,
  `id_division` int DEFAULT NULL,
  `login_count` int DEFAULT '0',
  `last_login` datetime DEFAULT NULL,
  `id_perfil` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `vendedor`
--

CREATE TABLE `vendedor` (
  `id` int NOT NULL,
  `id_vendedor` int NOT NULL,
  `nombre_vendedor` varchar(200) COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `visita`
--

CREATE TABLE `visita` (
  `id` int NOT NULL,
  `id_usuario` int NOT NULL,
  `id_formulario` int NOT NULL,
  `id_local` int NOT NULL,
  `client_guid` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_fin` datetime DEFAULT NULL,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_encuesta_respuestas_detalle`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_encuesta_respuestas_detalle` (
`response_id` int
,`visita_id` int
,`formulario_id` int
,`formulario_nombre` varchar(200)
,`id_empresa` int
,`id_division` int
,`id_subdivision` int
,`formulario_tipo` int
,`fechaInicio` datetime
,`fechaTermino` datetime
,`form_question_id` int
,`set_qid` int
,`signature` char(32)
,`qtype` int
,`question_text` varchar(400)
,`option_id` int
,`option_text` varchar(100)
,`answer_text` varchar(400)
,`valor` decimal(10,2)
,`created_at` datetime
,`local_id` int
,`local_codigo` varchar(45)
,`local_nombre` varchar(45)
,`id_distrito` int
,`distrito_nombre` varchar(100)
,`id_jefe_venta` int
,`jefe_venta_nombre` varchar(200)
,`usuario_id` int
,`usuario_nombre` varchar(45)
,`usuario_apellido` varchar(45)
,`semantic_key` varchar(36)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_lookup_preguntas_set`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_lookup_preguntas_set` (
`set_qid` int
,`sample_text` varchar(400)
,`qtype` int
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `vw_lookup_preguntas_signature`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `vw_lookup_preguntas_signature` (
`signature` char(32)
,`sample_text` varchar(400)
,`qtype` int
);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `zona`
--

CREATE TABLE `zona` (
  `id` int NOT NULL,
  `nombre_zona` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_encuesta_respuestas_detalle`
--
DROP TABLE IF EXISTS `vw_encuesta_respuestas_detalle`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY INVOKER VIEW `vw_encuesta_respuestas_detalle`  AS SELECT `fqr`.`id` AS `response_id`, `fqr`.`visita_id` AS `visita_id`, `v`.`id_formulario` AS `formulario_id`, `f`.`nombre` AS `formulario_nombre`, `f`.`id_empresa` AS `id_empresa`, `f`.`id_division` AS `id_division`, `f`.`id_subdivision` AS `id_subdivision`, `f`.`tipo` AS `formulario_tipo`, `f`.`fechaInicio` AS `fechaInicio`, `f`.`fechaTermino` AS `fechaTermino`, `fq`.`id` AS `form_question_id`, `fq`.`id_question_set_question` AS `set_qid`, `fq`.`v_signature` AS `signature`, `fq`.`id_question_type` AS `qtype`, `fq`.`question_text` AS `question_text`, `fqo`.`id` AS `option_id`, `fqo`.`option_text` AS `option_text`, `fqr`.`answer_text` AS `answer_text`, `fqr`.`valor` AS `valor`, `fqr`.`created_at` AS `created_at`, `l`.`id` AS `local_id`, `l`.`codigo` AS `local_codigo`, `l`.`nombre` AS `local_nombre`, `l`.`id_distrito` AS `id_distrito`, `dstr`.`nombre_distrito` AS `distrito_nombre`, `l`.`id_jefe_venta` AS `id_jefe_venta`, `jv`.`nombre` AS `jefe_venta_nombre`, `u`.`id` AS `usuario_id`, `u`.`nombre` AS `usuario_nombre`, `u`.`apellido` AS `usuario_apellido`, coalesce(convert(concat('set:',`fq`.`id_question_set_question`) using utf8mb3),concat('sig:',`fq`.`v_signature`)) AS `semantic_key` FROM ((((((((`form_question_responses` `fqr` join `form_questions` `fq` on((`fq`.`id` = `fqr`.`id_form_question`))) left join `form_question_options` `fqo` on((`fqo`.`id` = `fqr`.`id_option`))) join `visita` `v` on((`v`.`id` = `fqr`.`visita_id`))) join `formulario` `f` on((`f`.`id` = `v`.`id_formulario`))) join `local` `l` on((`l`.`id` = `v`.`id_local`))) left join `distrito` `dstr` on((`dstr`.`id` = `l`.`id_distrito`))) left join `jefe_venta` `jv` on((`jv`.`id` = `l`.`id_jefe_venta`))) join `usuario` `u` on((`u`.`id` = `v`.`id_usuario`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_lookup_preguntas_set`
--
DROP TABLE IF EXISTS `vw_lookup_preguntas_set`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY INVOKER VIEW `vw_lookup_preguntas_set`  AS SELECT `fq`.`id_question_set_question` AS `set_qid`, min(`fq`.`question_text`) AS `sample_text`, min(`fq`.`id_question_type`) AS `qtype` FROM `form_questions` AS `fq` WHERE (`fq`.`id_question_set_question` is not null) GROUP BY `fq`.`id_question_set_question` ;

-- --------------------------------------------------------

--
-- Estructura para la vista `vw_lookup_preguntas_signature`
--
DROP TABLE IF EXISTS `vw_lookup_preguntas_signature`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY INVOKER VIEW `vw_lookup_preguntas_signature`  AS SELECT `fq`.`v_signature` AS `signature`, min(`fq`.`question_text`) AS `sample_text`, min(`fq`.`id_question_type`) AS `qtype` FROM `form_questions` AS `fq` WHERE (`fq`.`id_question_set_question` is null) GROUP BY `fq`.`v_signature` ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `cadena`
--
ALTER TABLE `cadena`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cadena_cuenta` (`id_cuenta`);

--
-- Indices de la tabla `canal`
--
ALTER TABLE `canal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_canal` (`nombre_canal`);

--
-- Indices de la tabla `client_devices`
--
ALTER TABLE `client_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_device` (`user_id`,`device_id`);

--
-- Indices de la tabla `comuna`
--
ALTER TABLE `comuna`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_region` (`id_region`),
  ADD KEY `idx_comuna_region` (`id_region`);

--
-- Indices de la tabla `cuenta`
--
ALTER TABLE `cuenta`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `dashboard_items`
--
ALTER TABLE `dashboard_items`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `distrito`
--
ALTER TABLE `distrito`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_zona` (`id_zona`),
  ADD KEY `idx_distrito_nombre` (`nombre_distrito`),
  ADD KEY `idx_nombre_distrito` (`nombre_distrito`);

--
-- Indices de la tabla `division_empresa`
--
ALTER TABLE `division_empresa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_division_empresa_empresa_estado` (`id_empresa`,`estado`);

--
-- Indices de la tabla `empresa`
--
ALTER TABLE `empresa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_empresa_activo` (`activo`);

--
-- Indices de la tabla `encuesta`
--
ALTER TABLE `encuesta`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `export_jobs`
--
ALTER TABLE `export_jobs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `formulario`
--
ALTER TABLE `formulario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fechaInicio` (`fechaInicio`),
  ADD KEY `idx_fechaTermino` (`fechaTermino`),
  ADD KEY `idx_id_division` (`id_division`),
  ADD KEY `idx_formulario_emp_estado_tipo_div_sub` (`id_empresa`,`estado`,`tipo`,`id_division`,`id_subdivision`),
  ADD KEY `idx_formulario_div_estado_tipo_sub` (`id_division`,`estado`,`tipo`,`id_subdivision`),
  ADD KEY `idx_form_tipo_estado_subdiv` (`id_empresa`,`id_division`,`id_subdivision`,`tipo`,`estado`),
  ADD KEY `f_idx_empresa_tipo_estado` (`id_empresa`,`tipo`,`estado`,`id_division`),
  ADD KEY `idx_form_div_sub` (`id_division`,`id_subdivision`),
  ADD KEY `idx_form_empresa_div_sub_fecha` (`id_empresa`,`id_division`,`id_subdivision`,`fechaInicio`,`id`),
  ADD KEY `idx_form_empresa_div_sub_id` (`id_empresa`,`id_division`,`id_subdivision`,`id`),
  ADD KEY `idx_form_scope` (`id_empresa`,`id_division`,`id_subdivision`,`tipo`),
  ADD KEY `idx_form_empresa_tipo_estado` (`id_empresa`,`tipo`,`estado`),
  ADD KEY `idx_form_fecha` (`fechaInicio`,`fechaTermino`),
  ADD KEY `idx_form_empresa_div_tipo` (`id_empresa`,`id_division`,`id_subdivision`,`tipo`,`id`),
  ADD KEY `idx_formulario_scope` (`id_empresa`,`id_division`,`id_subdivision`,`tipo`,`deleted_at`);

--
-- Indices de la tabla `formularioQuestion`
--
ALTER TABLE `formularioQuestion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_formulario` (`id_formulario`),
  ADD KEY `idx_id_local` (`id_local`),
  ADD KEY `idx_fechaVisita` (`fechaVisita`),
  ADD KEY `idx_formulario_fecha` (`id_formulario`,`fechaVisita`),
  ADD KEY `idx_lat_lng` (`latGestion`,`lngGestion`),
  ADD KEY `idx_id_usuario` (`id_usuario`),
  ADD KEY `id_formulario` (`id_formulario`),
  ADD KEY `fq_form_local_fecha` (`id_formulario`,`id_local`,`fechaVisita`),
  ADD KEY `idx_fq_user_emp` (`id_usuario`,`id_local`,`id_formulario`),
  ADD KEY `idx_fq_count_user` (`countVisita`,`id_usuario`),
  ADD KEY `idx_fq_pregunta_user` (`pregunta`,`id_usuario`),
  ADD KEY `idx_fq_fecha_user` (`fechaPropuesta`,`id_usuario`),
  ADD KEY `fq_usuario_form_local_fecha` (`id_usuario`,`id_formulario`,`id_local`,`fechaPropuesta`),
  ADD KEY `fq_usuario_count_fecha` (`id_usuario`,`countVisita`,`fechaPropuesta`),
  ADD KEY `ix_fq_form_local_user_fecha` (`id_formulario`,`id_local`,`id_usuario`,`fechaVisita`),
  ADD KEY `idx_formularioQuestion_formulario` (`id_formulario`),
  ADD KEY `idx_formularioQuestion_local` (`id_local`),
  ADD KEY `idx_formularioQuestion_usuario` (`id_usuario`),
  ADD KEY `idx_fq_form_local_pregunta_priority` (`id_formulario`,`id_local`,`pregunta`,`is_priority`),
  ADD KEY `idx_fq_usuario_form_local` (`id_usuario`,`id_formulario`,`id_local`),
  ADD KEY `idx_fq_formulario_fecha` (`id_formulario`,`fechaPropuesta`),
  ADD KEY `fq_idx_usuario_local` (`id_usuario`,`id_local`),
  ADD KEY `fq_idx_usuario_empresa` (`id_usuario`,`id_formulario`),
  ADD KEY `fq_idx_form_estado` (`countVisita`,`is_priority`,`fechaPropuesta`),
  ADD KEY `fq_idx_pregunta` (`pregunta`),
  ADD KEY `fq_idx_fecha` (`fechaPropuesta`),
  ADD KEY `fq_form_local` (`id_formulario`,`id_local`),
  ADD KEY `idx_fq_usuario_form` (`id_usuario`,`id_formulario`),
  ADD KEY `idx_fq_estado_visita` (`estado`,`countVisita`),
  ADD KEY `idx_fq_fecha` (`fechaPropuesta`),
  ADD KEY `idx_fq_pregunta` (`pregunta`);

--
-- Indices de la tabla `form_questions`
--
ALTER TABLE `form_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_id_dependency_option` (`id_dependency_option`),
  ADD KEY `fq_form_sort` (`id_formulario`,`id_question_type`,`sort_order`),
  ADD KEY `ix_fq_form_tipo` (`id_formulario`,`id_question_type`),
  ADD KEY `idx_fq_formulario_sort` (`id_formulario`,`sort_order`),
  ADD KEY `idx_fq_formulario_qsetq` (`id_formulario`,`id_question_set_question`),
  ADD KEY `idx_fq_formulario` (`id_formulario`),
  ADD KEY `idx_fq_form_sort` (`id_formulario`,`sort_order`),
  ADD KEY `idx_fq_form_required` (`id_formulario`,`is_required`),
  ADD KEY `idx_fq_dependency_opt` (`id_dependency_option`),
  ADD KEY `idx_fq_form` (`id_formulario`,`sort_order`),
  ADD KEY `fq_form` (`id_formulario`,`sort_order`),
  ADD KEY `idx_fq_form_qtype` (`id_formulario`,`id_question_type`),
  ADD KEY `idx_fq_qset` (`id_question_set_question`),
  ADD KEY `idx_fq_set` (`id_question_set_question`,`id_question_type`),
  ADD KEY `idx_fq_qtype` (`id_question_type`),
  ADD KEY `idx_fq_vsig` (`v_signature`),
  ADD KEY `idx_fq_qtext_norm` (`question_text_norm`),
  ADD KEY `idx_fq_vsignature` (`v_signature`),
  ADD KEY `idx_fq_form_type_deleted` (`id_formulario`,`id_question_type`,`deleted_at`),
  ADD KEY `idx_fq_formulario_activo` (`id_formulario`,`deleted_at`);

--
-- Indices de la tabla `form_question_options`
--
ALTER TABLE `form_question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fqo_formq_qseto` (`id_form_question`,`id_question_set_option`),
  ADD KEY `idx_fqo_formq_sort` (`id_form_question`,`sort_order`),
  ADD KEY `idx_fqo_question_sort` (`id_form_question`,`sort_order`),
  ADD KEY `idx_fqo_pregunta` (`id_form_question`),
  ADD KEY `idx_fqo_question` (`id_form_question`);

--
-- Indices de la tabla `form_question_photo_meta`
--
ALTER TABLE `form_question_photo_meta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `resp_id` (`resp_id`),
  ADD KEY `idx_fqpm_resp` (`resp_id`),
  ADD KEY `idx_fqpm_visita_usuario` (`visita_id`,`id_usuario`),
  ADD KEY `idx_photo_resp` (`resp_id`);

--
-- Indices de la tabla `form_question_responses`
--
ALTER TABLE `form_question_responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_form_question` (`id_form_question`),
  ADD KEY `idx_fqr_visita_id` (`visita_id`),
  ADD KEY `fqr_form_q_local_dt` (`id_form_question`,`id_local`,`id_usuario`,`created_at`),
  ADD KEY `idx_fqr_form_local_visita_created` (`id_form_question`,`id_local`,`visita_id`,`created_at`),
  ADD KEY `idx_fqr_vis_usr_q` (`visita_id`,`id_usuario`,`id_form_question`),
  ADD KEY `idx_fqr_vis_q_usr_opt` (`visita_id`,`id_form_question`,`id_usuario`,`id_option`),
  ADD KEY `idx_fqr_q_usr_time` (`id_form_question`,`id_usuario`,`created_at`),
  ADD KEY `idx_fqr_vis_q_usr_opt_id` (`visita_id`,`id_form_question`,`id_usuario`,`id_option`,`id`),
  ADD KEY `idx_fqr_foto` (`foto_visita_id`),
  ADD KEY `idx_fqr_visita_pregunta` (`visita_id`,`id_form_question`),
  ADD KEY `idx_fqr_visita_usuario` (`visita_id`,`id_usuario`),
  ADD KEY `fqr_form_local_visita` (`id_form_question`,`visita_id`,`created_at`),
  ADD KEY `idx_fqr_qdate_local_user` (`id_form_question`,`created_at`,`id_local`,`id_usuario`),
  ADD KEY `idx_fqr_created_at` (`created_at`),
  ADD KEY `idx_fqr_id_option` (`id_option`),
  ADD KEY `idx_fqr_created_at_id` (`created_at`,`id`),
  ADD KEY `idx_fqr_usuario_created` (`id_usuario`,`created_at`),
  ADD KEY `idx_fqr_local_created` (`id_local`,`created_at`),
  ADD KEY `idx_fqr_formq` (`id_form_question`),
  ADD KEY `idx_fqr_option` (`id_option`),
  ADD KEY `idx_fqr_visit_q_opt` (`visita_id`,`id_form_question`,`id_option`),
  ADD KEY `idx_fqr_q_opt_created` (`id_form_question`,`id_option`,`created_at`,`id`),
  ADD KEY `idx_fqr_valor` (`valor`),
  ADD KEY `idx_fqr_answer_text` (`answer_text`(100)),
  ADD KEY `idx_fqr_q` (`id_form_question`),
  ADD KEY `idx_fqr_created` (`created_at`),
  ADD KEY `idx_fqr_user` (`id_usuario`),
  ADD KEY `idx_fqr_local` (`id_local`),
  ADD KEY `idx_fqr_visit_local_q` (`visita_id`,`id_local`,`id_form_question`),
  ADD KEY `idx_fqr_answer50` (`answer_text`(50)),
  ADD KEY `idx_fqr_answer_text_norm50` (`answer_text_norm`(50)),
  ADD KEY `idx_fqr_visita` (`visita_id`),
  ADD KEY `idx_fqr_q_opt` (`id_form_question`,`id_option`),
  ADD KEY `idx_fqr_form_fecha` (`id_form_question`,`created_at`),
  ADD KEY `idx_fqr_local_visita` (`id_local`,`visita_id`,`id_form_question`),
  ADD KEY `idx_fqr_form_valor` (`id_form_question`,`valor`),
  ADD KEY `idx_fqr_created_local` (`created_at`,`id_local`,`visita_id`),
  ADD KEY `idx_fqr_form_question` (`id_form_question`,`created_at`),
  ADD KEY `idx_fqr_visita_local_pregunta` (`visita_id`,`id_local`,`id_form_question`),
  ADD KEY `idx_fqr_pregunta_fecha` (`id_form_question`,`created_at`),
  ADD KEY `idx_fqr_usuario` (`id_usuario`),
  ADD KEY `idx_fqr_local_form` (`id_local`,`id_form_question`);

--
-- Indices de la tabla `fotoVisita`
--
ALTER TABLE `fotoVisita`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fotoVisita_visita_id` (`visita_id`),
  ADD KEY `fv_form_visita_mat` (`id_formulario`,`visita_id`,`id_material`,`id_formularioQuestion`,`id_usuario`),
  ADD KEY `fv_visita_only` (`visita_id`),
  ADD KEY `idx_fv_form_local_visita_fq` (`id_formulario`,`id_local`,`visita_id`,`id_formularioQuestion`,`id`),
  ADD KEY `idx_fv_visita` (`visita_id`),
  ADD KEY `idx_fv_llave` (`id_formulario`,`id_local`,`id_material`,`id_formularioQuestion`),
  ADD KEY `idx_fv_exifdate` (`exif_datetime`),
  ADD KEY `fv_form_local_visita` (`id_formulario`,`id_local`,`visita_id`,`id`),
  ADD KEY `fv_form_local_fq` (`id_formulario`,`id_local`,`id_formularioQuestion`,`id`),
  ADD KEY `fv_form_local_material` (`id_formulario`,`id_local`,`id_material`,`id`),
  ADD KEY `idx_fv_form_question` (`id_formularioQuestion`),
  ADD KEY `idx_fotov_visita` (`visita_id`),
  ADD KEY `idx_fotov_fq` (`id_formularioQuestion`),
  ADD KEY `idx_fv_visita_fq_form` (`visita_id`,`id_formularioQuestion`,`id_formulario`);

--
-- Indices de la tabla `gestion_visita`
--
ALTER TABLE `gestion_visita`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_uvl` (`id_usuario`,`id_formulario`,`id_local`),
  ADD KEY `idx_fq` (`id_formularioQuestion`),
  ADD KEY `idx_material` (`id_material`),
  ADD KEY `gv_form_local_fecha` (`id_formulario`,`id_local`,`fecha_visita`,`id_usuario`),
  ADD KEY `gv_visita_material` (`visita_id`,`id_material`,`id_formularioQuestion`),
  ADD KEY `idx_gv_form_local_fecha_id` (`id_formulario`,`id_local`,`fecha_visita`,`id`),
  ADD KEY `gv_form_local_fecha_id` (`id_formulario`,`id_local`,`fecha_visita`,`id`),
  ADD KEY `gv_form_local_usuario` (`id_formulario`,`id_local`,`id_usuario`),
  ADD KEY `gv_form_visita` (`id_formulario`,`visita_id`),
  ADD KEY `idx_gestion_visita_foto_visita_id_estado` (`foto_visita_id_estado`),
  ADD KEY `idx_gv_formulario_fecha_local` (`id_formulario`,`fecha_visita`,`id_local`,`id`),
  ADD KEY `idx_gv_form_local_fecha_desc` (`id_formulario`,`id_local`,`fecha_visita` DESC,`id` DESC),
  ADD KEY `idx_gv_form_local_visita_cnt` (`id_formulario`,`id_local`,`visita_id`),
  ADD KEY `idx_gv_visita_fq_estado` (`visita_id`,`id_formularioQuestion`,`fecha_visita`),
  ADD KEY `idx_gv_formulario_usuario` (`id_formulario`,`id_usuario`),
  ADD KEY `idx_gv_form_estado` (`id_formulario`,`estado_gestion`);

--
-- Indices de la tabla `imagenes`
--
ALTER TABLE `imagenes`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `jefe_venta`
--
ALTER TABLE `jefe_venta`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD UNIQUE KEY `nombre_2` (`nombre`),
  ADD KEY `idx_jefe_venta_nombre` (`nombre`);

--
-- Indices de la tabla `journal_event`
--
ALTER TABLE `journal_event`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_event_id` (`event_id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_empresa_created` (`empresa_id`,`created_at`),
  ADD KEY `idx_job_id` (`job_id`);

--
-- Indices de la tabla `local`
--
ALTER TABLE `local`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_local_codigo` (`codigo`),
  ADD KEY `idx_id_local` (`id`),
  ADD KEY `idx_id_comuna` (`id_comuna`),
  ADD KEY `idx_id_distrito` (`id_distrito`),
  ADD KEY `idx_id_jefe_venta` (`id_jefe_venta`),
  ADD KEY `idx_id_vendedor` (`id_vendedor`),
  ADD KEY `id` (`id`),
  ADD KEY `idx_l_rel` (`id_cadena`,`id_vendedor`,`id_comuna`),
  ADD KEY `ix_local_comuna_cadena_cuenta` (`id_comuna`,`id_cadena`,`id_cuenta`),
  ADD KEY `idx_local_codigo_empresa` (`codigo`,`id_empresa`),
  ADD KEY `idx_local_id_empresa` (`id_empresa`),
  ADD KEY `idx_local_id_cuenta` (`id_cuenta`),
  ADD KEY `idx_local_id_cadena` (`id_cadena`),
  ADD KEY `idx_local_id_canal` (`id_canal`),
  ADD KEY `idx_local_id_subcanal` (`id_subcanal`),
  ADD KEY `idx_local_id_zona` (`id_zona`),
  ADD KEY `idx_local_codigo` (`codigo`),
  ADD KEY `l_idx_cadena` (`id_cadena`),
  ADD KEY `l_idx_vendedor` (`id_vendedor`),
  ADD KEY `l_idx_comuna` (`id_comuna`),
  ADD KEY `idx_local_div_distr_jv` (`id_division`,`id_distrito`,`id_jefe_venta`,`codigo`),
  ADD KEY `idx_local_emp_div_distr_jv` (`id_empresa`,`id_division`,`id_distrito`,`id_jefe_venta`),
  ADD KEY `idx_local_emp_div` (`id_empresa`,`id_division`),
  ADD KEY `idx_local_filt` (`id_empresa`,`id_division`,`id_distrito`,`id_jefe_venta`,`codigo`),
  ADD KEY `idx_local_cadena` (`id_cadena`),
  ADD KEY `idx_local_comuna` (`id_comuna`),
  ADD KEY `idx_local_vend` (`id_vendedor`),
  ADD KEY `idx_local_empresa_div` (`id_empresa`,`id_division`),
  ADD KEY `idx_local_distrito` (`id_distrito`),
  ADD KEY `idx_local_jv` (`id_jefe_venta`),
  ADD KEY `idx_local_scope` (`id_empresa`,`id_division`,`id_distrito`,`id_jefe_venta`);

--
-- Indices de la tabla `local_priority`
--
ALTER TABLE `local_priority`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario` (`usuario`),
  ADD KEY `intento_at` (`intento_at`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `outcome` (`outcome`),
  ADD KEY `idx_user_time` (`user_id`,`intento_at`),
  ADD KEY `idx_login_attempts_user_time` (`user_id`,`intento_at`),
  ADD KEY `idx_login_attempts_outcome_time` (`outcome`,`intento_at`),
  ADD KEY `idx_userid_time` (`user_id`,`intento_at`),
  ADD KEY `idx_outcome` (`outcome`);

--
-- Indices de la tabla `material`
--
ALTER TABLE `material`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_mat_nombre_div` (`nombre`,`id_division`),
  ADD UNIQUE KEY `ux_material_nombre_division` (`nombre`,`id_division`),
  ADD KEY `idx_nombre` (`nombre`);

--
-- Indices de la tabla `opciones_pregunta`
--
ALTER TABLE `opciones_pregunta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_id_pregunta` (`id_pregunta`);

--
-- Indices de la tabla `pais`
--
ALTER TABLE `pais`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `panel_encuesta_log`
--
ALTER TABLE `panel_encuesta_log`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `selector` (`selector`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);

--
-- Indices de la tabla `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `created_at` (`created_at`);

--
-- Indices de la tabla `perfil`
--
ALTER TABLE `perfil`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `pregunta`
--
ALTER TABLE `pregunta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_formulario` (`id_formulario`);

--
-- Indices de la tabla `question_set`
--
ALTER TABLE `question_set`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `question_set_options`
--
ALTER TABLE `question_set_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qso_qsq_sort` (`id_question_set_question`,`sort_order`),
  ADD KEY `idx_qso_question_sort` (`id_question_set_question`,`sort_order`);

--
-- Indices de la tabla `question_set_questions`
--
ALTER TABLE `question_set_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qsq_set_sort` (`id_question_set`,`sort_order`),
  ADD KEY `idx_qsq_set_dep` (`id_question_set`,`id_dependency_option`),
  ADD KEY `idx_qsq_dep_only` (`id_dependency_option`);

--
-- Indices de la tabla `question_type`
--
ALTER TABLE `question_type`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `region`
--
ALTER TABLE `region`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `repo_archivo`
--
ALTER TABLE `repo_archivo`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_idx` (`id_usuario`);

--
-- Indices de la tabla `repo_carpeta`
--
ALTER TABLE `repo_carpeta`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `request_log`
--
ALTER TABLE `request_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_req` (`idempotency_key`,`endpoint`,`user_id`),
  ADD KEY `by_user` (`user_id`,`created_at`);

--
-- Indices de la tabla `respuesta`
--
ALTER TABLE `respuesta`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ruta`
--
ALTER TABLE `ruta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ruta_usuario_fecha` (`usuario_id`,`fecha`),
  ADD KEY `idx_ruta_usuario_estado` (`usuario_id`,`estado`),
  ADD KEY `idx_ruta_estado_fecha` (`estado`,`fecha`);

--
-- Indices de la tabla `ruta_parada`
--
ALTER TABLE `ruta_parada`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parada_ruta_seq` (`ruta_id`,`seq`),
  ADD KEY `idx_parada_ruta_status` (`ruta_id`,`status`),
  ADD KEY `idx_parada_ruta_local` (`ruta_id`,`local_id`),
  ADD KEY `idx_parada_local` (`local_id`);

--
-- Indices de la tabla `security_events`
--
ALTER TABLE `security_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `type` (`type`),
  ADD KEY `idx_user_type_time` (`user_id`,`type`,`created_at`),
  ADD KEY `idx_security_events_user_type_time` (`user_id`,`type`,`created_at`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_type` (`type`);

--
-- Indices de la tabla `subcanal`
--
ALTER TABLE `subcanal`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_subcanal` (`nombre_subcanal`,`id_canal`);

--
-- Indices de la tabla `subdivision`
--
ALTER TABLE `subdivision`
  ADD UNIQUE KEY `id` (`id`),
  ADD KEY `idx_subdivision_division_nombre` (`id_division`,`nombre`),
  ADD KEY `idx_subdivision_division` (`id_division`),
  ADD KEY `idx_subdivision_div` (`id_division`);

--
-- Indices de la tabla `tipo`
--
ALTER TABLE `tipo`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ubicacion_ejecutor`
--
ALTER TABLE `ubicacion_ejecutor`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_ejecutor` (`id_ejecutor`);

--
-- Indices de la tabla `user_remember_tokens`
--
ALTER TABLE `user_remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_selector` (`selector`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_revoked` (`revoked_at`);

--
-- Indices de la tabla `user_security`
--
ALTER TABLE `user_security`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_user_security` (`user_id`);

--
-- Indices de la tabla `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_fpr` (`user_id`,`session_fpr`),
  ADD UNIQUE KEY `uq_session_fpr` (`session_fpr`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `session_fpr` (`session_fpr`),
  ADD KEY `idx_user_active` (`user_id`,`revoked_at`),
  ADD KEY `idx_last_seen` (`last_seen_at`),
  ADD KEY `idx_user_sessions_user` (`user_id`),
  ADD KEY `idx_user_sessions_revoked` (`revoked_at`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_user_fpr` (`user_id`,`session_fpr`),
  ADD KEY `idx_seen` (`last_seen_at`),
  ADD KEY `idx_revoked` (`revoked_at`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuario_email` (`email`),
  ADD UNIQUE KEY `uq_usuario_rut` (`rut`),
  ADD KEY `idx_id_division` (`id_division`),
  ADD KEY `id` (`id`),
  ADD KEY `idx_usuario_usuario_empresa` (`usuario`,`id_empresa`),
  ADD KEY `idx_usuario_empresa_perfil_div_activo` (`id_empresa`,`id_perfil`,`id_division`,`activo`),
  ADD KEY `idx_usuario_usuario` (`usuario`),
  ADD KEY `idx_usuario_div` (`id_division`),
  ADD KEY `idx_usuario_emp_div` (`id_empresa`,`id_division`),
  ADD KEY `idx_usuario_activo_emp_div` (`activo`,`id_empresa`,`id_division`);

--
-- Indices de la tabla `vendedor`
--
ALTER TABLE `vendedor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vendedor_ext` (`id_vendedor`);

--
-- Indices de la tabla `visita`
--
ALTER TABLE `visita`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_visita_client_guid` (`client_guid`),
  ADD UNIQUE KEY `uniq_visita_client_guid` (`client_guid`),
  ADD UNIQUE KEY `ux_visita_guid_user_form_local` (`client_guid`,`id_usuario`,`id_formulario`,`id_local`),
  ADD KEY `id_usuario` (`id_usuario`,`id_formulario`,`id_local`),
  ADD KEY `idx_visita_usuario_form_local` (`id_usuario`,`id_formulario`,`id_local`),
  ADD KEY `idx_visita_form_fin` (`id_formulario`,`fecha_fin`),
  ADD KEY `idx_visita_open` (`id_usuario`,`id_formulario`,`id_local`,`fecha_fin`),
  ADD KEY `idx_visita_form_local_usuario` (`id_formulario`,`id_local`,`id_usuario`),
  ADD KEY `idx_open_visit_lookup` (`id_usuario`,`id_formulario`,`id_local`,`client_guid`(36),`fecha_fin`),
  ADD KEY `idx_client_guid` (`client_guid`(36));

--
-- Indices de la tabla `zona`
--
ALTER TABLE `zona`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_zona` (`nombre_zona`),
  ADD UNIQUE KEY `nombre_zona_2` (`nombre_zona`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `cadena`
--
ALTER TABLE `cadena`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `canal`
--
ALTER TABLE `canal`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `client_devices`
--
ALTER TABLE `client_devices`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comuna`
--
ALTER TABLE `comuna`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cuenta`
--
ALTER TABLE `cuenta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `dashboard_items`
--
ALTER TABLE `dashboard_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `distrito`
--
ALTER TABLE `distrito`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `division_empresa`
--
ALTER TABLE `division_empresa`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `empresa`
--
ALTER TABLE `empresa`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `encuesta`
--
ALTER TABLE `encuesta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `export_jobs`
--
ALTER TABLE `export_jobs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `formulario`
--
ALTER TABLE `formulario`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `formularioQuestion`
--
ALTER TABLE `formularioQuestion`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `form_questions`
--
ALTER TABLE `form_questions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `form_question_options`
--
ALTER TABLE `form_question_options`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `form_question_photo_meta`
--
ALTER TABLE `form_question_photo_meta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `form_question_responses`
--
ALTER TABLE `form_question_responses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fotoVisita`
--
ALTER TABLE `fotoVisita`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `gestion_visita`
--
ALTER TABLE `gestion_visita`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `imagenes`
--
ALTER TABLE `imagenes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `jefe_venta`
--
ALTER TABLE `jefe_venta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `journal_event`
--
ALTER TABLE `journal_event`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `local`
--
ALTER TABLE `local`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `local_priority`
--
ALTER TABLE `local_priority`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `material`
--
ALTER TABLE `material`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `opciones_pregunta`
--
ALTER TABLE `opciones_pregunta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `panel_encuesta_log`
--
ALTER TABLE `panel_encuesta_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `perfil`
--
ALTER TABLE `perfil`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pregunta`
--
ALTER TABLE `pregunta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `question_set`
--
ALTER TABLE `question_set`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `question_set_options`
--
ALTER TABLE `question_set_options`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `question_set_questions`
--
ALTER TABLE `question_set_questions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `question_type`
--
ALTER TABLE `question_type`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `region`
--
ALTER TABLE `region`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `repo_archivo`
--
ALTER TABLE `repo_archivo`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `repo_carpeta`
--
ALTER TABLE `repo_carpeta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `request_log`
--
ALTER TABLE `request_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `respuesta`
--
ALTER TABLE `respuesta`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ruta`
--
ALTER TABLE `ruta`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ruta_parada`
--
ALTER TABLE `ruta_parada`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `security_events`
--
ALTER TABLE `security_events`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `subcanal`
--
ALTER TABLE `subcanal`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `subdivision`
--
ALTER TABLE `subdivision`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `tipo`
--
ALTER TABLE `tipo`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ubicacion_ejecutor`
--
ALTER TABLE `ubicacion_ejecutor`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_remember_tokens`
--
ALTER TABLE `user_remember_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `vendedor`
--
ALTER TABLE `vendedor`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `visita`
--
ALTER TABLE `visita`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `zona`
--
ALTER TABLE `zona`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `form_question_photo_meta`
--
ALTER TABLE `form_question_photo_meta`
  ADD CONSTRAINT `fk_fqpm_resp` FOREIGN KEY (`resp_id`) REFERENCES `form_question_responses` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT;

--
-- Filtros para la tabla `form_question_responses`
--
ALTER TABLE `form_question_responses`
  ADD CONSTRAINT `fk_fqr_fotovisita` FOREIGN KEY (`foto_visita_id`) REFERENCES `fotoVisita` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT;

--
-- Filtros para la tabla `gestion_visita`
--
ALTER TABLE `gestion_visita`
  ADD CONSTRAINT `gestion_visita_ibfk_1` FOREIGN KEY (`visita_id`) REFERENCES `visita` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Filtros para la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `usuario` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Filtros para la tabla `repo_archivo`
--
ALTER TABLE `repo_archivo`
  ADD CONSTRAINT `id_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id`);

--
-- Filtros para la tabla `ruta_parada`
--
ALTER TABLE `ruta_parada`
  ADD CONSTRAINT `fk_parada_ruta` FOREIGN KEY (`ruta_id`) REFERENCES `ruta` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `user_security`
--
ALTER TABLE `user_security`
  ADD CONSTRAINT `user_security_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuario` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Filtros para la tabla `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuario` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
