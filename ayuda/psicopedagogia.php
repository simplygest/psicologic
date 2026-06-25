<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Psicopedagogia',
    'title' => 'Manual para psicopedagogia',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para gabinetes psicopedagogicos que gestionan alumnos, familias, objetivos educativos, tareas y documentacion.',
    'person' => 'alumno',
    'personPlural' => 'alumnos',
    'professional' => 'especialista',
    'problem' => 'necesidad, dificultad u objetivo educativo',
    'problemTitle' => 'Necesidades y objetivos',
    'task' => 'actividad',
    'taskPlural' => 'actividades',
    'knowledge' => 'base de conocimiento psicopedagogica',
    'documents' => 'cuestionarios, informes y documentos educativos',
    'extraKnowledge' => '<div class="help-note">Las recomendaciones ayudan a estructurar apoyos, actividades e informes, pero deben ajustarse al contexto familiar, escolar y evolutivo.</div>',
]);

require __DIR__ . '/sector_help_common.php';
