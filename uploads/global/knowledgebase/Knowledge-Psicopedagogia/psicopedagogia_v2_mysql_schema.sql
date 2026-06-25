CREATE TABLE psicopedagogia_v2_fuentes (
  fuente_id VARCHAR(20) PRIMARY KEY,
  organismo VARCHAR(150),
  titulo VARCHAR(255),
  url VARCHAR(500),
  notas TEXT
);

CREATE TABLE psicopedagogia_v2_areas (
  area_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(180),
  descripcion TEXT
);

CREATE TABLE psicopedagogia_v2_problemas_objetivos (
  problema_id VARCHAR(20) PRIMARY KEY,
  area_id VARCHAR(20),
  nombre VARCHAR(220),
  descripcion TEXT,
  poblacion VARCHAR(100),
  nivel_riesgo VARCHAR(30),
  fuente_ids VARCHAR(255),
  INDEX(area_id),
  INDEX(nivel_riesgo)
);

CREATE TABLE psicopedagogia_v2_tecnicas_metodos (
  tecnica_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(180),
  descripcion TEXT,
  nivel_riesgo VARCHAR(30)
);

CREATE TABLE psicopedagogia_v2_tareas_pautas (
  tarea_id VARCHAR(20) PRIMARY KEY,
  titulo VARCHAR(180),
  descripcion TEXT,
  objetivo TEXT,
  nivel_riesgo VARCHAR(30),
  duracion_estimada VARCHAR(80),
  tecnica_id VARCHAR(20),
  INDEX(tecnica_id),
  INDEX(nivel_riesgo)
);

CREATE TABLE psicopedagogia_v2_problema_tecnica (
  problema_id VARCHAR(20),
  tecnica_id VARCHAR(20),
  PRIMARY KEY(problema_id, tecnica_id)
);

CREATE TABLE psicopedagogia_v2_recomendaciones_tareas (
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

CREATE TABLE psicopedagogia_v2_evaluaciones_indicadores (
  evaluacion_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(180),
  area_uso VARCHAR(100),
  tipo VARCHAR(100),
  notas TEXT,
  problema_ids VARCHAR(255)
);

CREATE TABLE psicopedagogia_v2_documentos_informes (
  documento_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(180),
  tipo VARCHAR(100),
  descripcion TEXT,
  problema_ids VARCHAR(255),
  nivel_riesgo VARCHAR(30)
);
