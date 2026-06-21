SimplyGest Praxis - Body Muscles import

Archivos generados:
- praxis_bodymuscles_regions.csv: 89 regiones anatómicas con ID original, nombre EN, traducción ES, grupo, vista, lateralidad y path SVG.
- praxis_bodymuscles_groups.csv: 8 grupos anatómicos.
- praxis_bodymuscles_group_regions.csv: relación grupo-región.
- praxis_bodymuscles_regions.json: el mismo contenido en JSON.
- praxis_bodymuscles_mysql_schema.sql: esquema sugerido para MySQL.

Notas:
- Los IDs de Body Muscles se conservan en bodymuscles_id.
- region_id es un ID propio estable para Praxis.
- path se incluye por si quieres independizarte de la librería o generar SVG propio más adelante.
- pair_key permite agrupar izquierda/derecha: por ejemplo quads-left y quads-right comparten pair_key=quads.
- region_type distingue músculo de zona/articulación general.
- Revisa manualmente las traducciones antes de publicar; están preparadas para una primera importación razonable.
