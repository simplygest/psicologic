CREATE TABLE nutricion_v2_fuentes (
  fuente_id VARCHAR(20) PRIMARY KEY,
  organismo VARCHAR(180),
  titulo VARCHAR(255),
  url VARCHAR(500),
  notas TEXT
);

CREATE TABLE nutricion_v2_areas (
  area_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(200),
  descripcion TEXT
);

CREATE TABLE nutricion_v2_problemas_objetivos (
  problema_id VARCHAR(20) PRIMARY KEY,
  area_id VARCHAR(20),
  nombre VARCHAR(240),
  descripcion TEXT,
  poblacion VARCHAR(120),
  tipo_intervencion VARCHAR(120),
  nivel_riesgo VARCHAR(30),
  fuente_ids VARCHAR(255),
  INDEX(area_id),
  INDEX(tipo_intervencion),
  INDEX(nivel_riesgo)
);

CREATE TABLE nutricion_v2_tecnicas_metodos (
  tecnica_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(220),
  descripcion TEXT,
  nivel_riesgo VARCHAR(30),
  tipo_intervencion VARCHAR(120),
  fuente_ids VARCHAR(255),
  INDEX(nivel_riesgo),
  INDEX(tipo_intervencion)
);

CREATE TABLE nutricion_v2_tareas_pautas (
  tarea_id VARCHAR(20) PRIMARY KEY,
  titulo VARCHAR(220),
  descripcion TEXT,
  objetivo TEXT,
  nivel_riesgo VARCHAR(30),
  duracion_estimada VARCHAR(80),
  tipo_intervencion VARCHAR(120),
  tecnica_id VARCHAR(20),
  INDEX(tecnica_id),
  INDEX(nivel_riesgo),
  INDEX(tipo_intervencion)
);

CREATE TABLE nutricion_v2_problema_tecnica (
  problema_id VARCHAR(20),
  tecnica_id VARCHAR(20),
  PRIMARY KEY(problema_id, tecnica_id)
);

CREATE TABLE nutricion_v2_recomendaciones_tareas (
  recomendacion_id VARCHAR(20) PRIMARY KEY,
  problema_id VARCHAR(20),
  tecnica_id VARCHAR(20),
  tarea_id VARCHAR(20),
  prioridad VARCHAR(30),
  nota_profesional TEXT,
  INDEX(problema_id),
  INDEX(tecnica_id),
  INDEX(tarea_id)
);

CREATE TABLE nutricion_v2_evaluaciones_indicadores (
  evaluacion_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(220),
  area_uso VARCHAR(150),
  tipo VARCHAR(120),
  notas TEXT,
  problema_ids VARCHAR(500),
  nivel_riesgo VARCHAR(30),
  INDEX(nivel_riesgo)
);

CREATE TABLE nutricion_v2_documentos_informes (
  documento_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(220),
  tipo VARCHAR(120),
  descripcion TEXT,
  problema_ids VARCHAR(500),
  nivel_riesgo VARCHAR(30),
  INDEX(nivel_riesgo)
);

CREATE TABLE nutricion_v2_plantillas_dietas (
  plantilla_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(220),
  tipo VARCHAR(120),
  descripcion TEXT
);
