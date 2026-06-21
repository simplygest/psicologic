-- SimplyGest Praxis Fitness - esquema base MySQL
-- CSV separados por punto y coma (;). Usar utf8mb4.

CREATE TABLE fitness_sources (
  source_id VARCHAR(20) PRIMARY KEY,
  nombre VARCHAR(100),
  titulo VARCHAR(200),
  url VARCHAR(500),
  notas TEXT
);

CREATE TABLE fitness_equipment (
  equipment_id VARCHAR(30) PRIMARY KEY,
  nombre_es VARCHAR(100),
  name_en VARCHAR(100),
  descripcion TEXT
);

CREATE TABLE fitness_goals (
  goal_id VARCHAR(30) PRIMARY KEY,
  nombre VARCHAR(100),
  descripcion TEXT
);

CREATE TABLE fitness_exercises (
  exercise_id VARCHAR(30) PRIMARY KEY,
  name_en VARCHAR(150),
  name_es VARCHAR(150),
  equipment_id VARCHAR(30),
  category VARCHAR(80),
  difficulty VARCHAR(50),
  mechanics VARCHAR(50),
  movement_pattern VARCHAR(80),
  description_es TEXT,
  cues_es TEXT,
  source_ids VARCHAR(255),
  INDEX (equipment_id),
  INDEX (category),
  INDEX (difficulty)
);

CREATE TABLE fitness_exercise_regions (
  exercise_id VARCHAR(30),
  bodymuscles_id VARCHAR(100),
  role ENUM('primary','secondary','stabilizer') DEFAULT 'primary',
  intensity TINYINT DEFAULT 100,
  PRIMARY KEY (exercise_id, bodymuscles_id, role),
  INDEX (bodymuscles_id),
  INDEX (role)
);

CREATE TABLE fitness_routines (
  routine_id VARCHAR(30) PRIMARY KEY,
  name_es VARCHAR(150),
  goal_id VARCHAR(30),
  level VARCHAR(80),
  days_per_week TINYINT,
  description_es TEXT,
  INDEX (goal_id),
  INDEX (level)
);

CREATE TABLE fitness_routine_exercises (
  routine_id VARCHAR(30),
  day_label VARCHAR(80),
  order_num INT,
  exercise_id VARCHAR(30),
  sets VARCHAR(20),
  reps VARCHAR(50),
  rest_seconds INT,
  notes TEXT,
  PRIMARY KEY (routine_id, day_label, order_num),
  INDEX (exercise_id)
);