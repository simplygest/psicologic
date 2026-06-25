<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Preparacion de oposiciones',
    'title' => 'Manual para preparadores de oposiciones',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para preparadores que gestionan alumnos, sesiones, objetivos de estudio, tareas, documentos y seguimiento.',
    'person' => 'alumno',
    'personPlural' => 'alumnos',
    'professional' => 'preparador',
    'problem' => 'objetivo de estudio o area de mejora',
    'problemTitle' => 'Objetivos de preparacion',
    'task' => 'tarea',
    'taskPlural' => 'tareas',
    'knowledge' => 'base de conocimiento de preparacion',
    'documents' => 'documentos, simulacros y materiales',
    'extraKnowledge' => '<div class="help-note">Usa plantillas y tareas para organizar temarios, simulacros, repasos y entregas recurrentes.</div>',
]);

require __DIR__ . '/sector_help_common.php';
