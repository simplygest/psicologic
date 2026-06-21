SimplyGest Praxis - Fitness / Entrenamiento personal v1

Contenido:
- 55 ejercicios
- 352 relaciones ejercicio -> región Body Muscles
- 5 rutinas base
- 96 ejercicios dentro de rutinas
- 10 tipos de equipamiento
- 6 objetivos

Notas:
- No se copia contenido literal de bases externas; se ha creado una semilla propia basada en patrones estándar de entrenamiento.
- Los IDs bodymuscles_id están pensados para enlazar con la tabla ya creada desde Body Muscles.
- La tabla fitness_05_ejercicio_musculos_bodymuscles.csv permite que al pulsar una región del mapa se muestren ejercicios filtrados.
- role: primary, secondary o stabilizer.
- intensity: peso relativo de relevancia para ordenación visual o filtros.

Ejemplo de consulta:
SELECT e.*
FROM fitness_exercises e
JOIN fitness_exercise_regions er ON er.exercise_id = e.exercise_id
WHERE er.bodymuscles_id = 'quads-left'
ORDER BY er.role, er.intensity DESC;