-- Esquema sugerido para importar Body Muscles en SimplyGest Praxis
-- CSV separados por punto y coma (;)
-- Licencia del recurso original: Apache 2.0. Mantener atribución/licencia del proyecto original.

CREATE TABLE praxis_bodymuscles_groups (
  group_id VARCHAR(10) PRIMARY KEY,
  group_en VARCHAR(100) NOT NULL,
  group_es VARCHAR(100) NOT NULL,
  region_count INT NOT NULL,
  bodymuscles_ids TEXT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE praxis_bodymuscles_regions (
  region_id VARCHAR(10) PRIMARY KEY,
  bodymuscles_id VARCHAR(100) NOT NULL UNIQUE,
  name_en VARCHAR(150) NOT NULL,
  name_es VARCHAR(200) NOT NULL,
  group_en VARCHAR(100) NOT NULL,
  group_es VARCHAR(100) NOT NULL,
  view ENUM('front','back') NOT NULL,
  view_es VARCHAR(50) NOT NULL,
  side ENUM('left','right','midline') NOT NULL,
  side_es VARCHAR(50) NOT NULL,
  pair_key VARCHAR(100) NOT NULL,
  region_type ENUM('muscle','region') NOT NULL DEFAULT 'muscle',
  active TINYINT(1) NOT NULL DEFAULT 1,
  source_file VARCHAR(100),
  path MEDIUMTEXT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE praxis_bodymuscles_group_regions (
  group_id VARCHAR(10) NOT NULL,
  region_id VARCHAR(10) NOT NULL,
  bodymuscles_id VARCHAR(100) NOT NULL,
  PRIMARY KEY (group_id, region_id),
  INDEX idx_region_id (region_id),
  INDEX idx_bodymuscles_id (bodymuscles_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tabla puente que conectarás después con la base de conocimiento:
CREATE TABLE praxis_problem_body_regions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sector VARCHAR(30) NOT NULL,         -- fisioterapia, osteopatia, quiropractica...
  problem_id VARCHAR(30) NOT NULL,     -- FIS_P001, OST_P001, QUI_P001...
  region_id VARCHAR(10) NOT NULL,      -- BM001...
  bodymuscles_id VARCHAR(100) NOT NULL,
  relevance TINYINT UNSIGNED NOT NULL DEFAULT 100,
  notes VARCHAR(255),
  INDEX idx_problem (sector, problem_id),
  INDEX idx_region (region_id),
  INDEX idx_bodymuscles (bodymuscles_id)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
