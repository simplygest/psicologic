<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Coaching',
    'title' => 'Manual para coaching',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para profesionales de coaching que gestionan clientes, sesiones, objetivos, tareas y seguimiento.',
    'person' => 'cliente',
    'personPlural' => 'clientes',
    'professional' => 'coach',
    'problem' => 'objetivo de trabajo',
    'problemTitle' => 'Objetivos',
    'task' => 'accion',
    'taskPlural' => 'acciones',
    'knowledge' => 'base de apoyo',
    'documents' => 'documentos, cuestionarios y materiales',
    'extraKnowledge' => '<div class="help-note">Usa objetivos, acciones y evolucion para mantener trazabilidad del proceso y preparar siguientes sesiones.</div>',
]);

require __DIR__ . '/sector_help_common.php';
