-- ============================================================
-- 27_studio_dashboard_hoja_unique.sql
-- Fase 3 de "Visibility Studio": multi-hoja.
--
-- scripts/26_studio_dashboards.sql ya dejó studio_dashboard_hoja lista para
-- guardar varias filas por dashboard, pero el índice (id_dashboard, orden) no
-- era UNIQUE -- nada impedía guardar dos hojas con el mismo "orden" para el
-- mismo dashboard. Ahora que el editor realmente escribe más de una hoja por
-- dashboard, conviene que la base lo garantice en vez de confiar sólo en el
-- código de save_dashboard.
-- ============================================================

ALTER TABLE `studio_dashboard_hoja`
  DROP INDEX `idx_studio_hoja_dashboard`,
  ADD UNIQUE KEY `uk_studio_hoja_dashboard_orden` (`id_dashboard`, `orden`);

-- ------------------------------------------------------------
-- Verificación rápida post-migración
-- ------------------------------------------------------------
-- SHOW INDEX FROM studio_dashboard_hoja;
