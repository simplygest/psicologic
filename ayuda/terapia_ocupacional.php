<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Terapia Ocupacional',
    'title' => 'Manual para terapia ocupacional',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para terapeutas ocupacionales que trabajan con pacientes, autonomia, actividades, objetivos funcionales y seguimiento.',
    'person' => 'paciente',
    'personPlural' => 'pacientes',
    'professional' => 'terapeuta ocupacional',
    'problem' => 'objetivo funcional o necesidad ocupacional',
    'problemTitle' => 'Objetivos funcionales',
    'task' => 'actividad',
    'taskPlural' => 'actividades',
    'knowledge' => 'base de conocimiento ocupacional',
    'documents' => 'valoraciones, informes y documentos',
    'extraKnowledge' => '<div class="help-note">Las actividades sugeridas son apoyo para la planificacion. Deben adaptarse al entorno, seguridad, autonomia y preferencias de la persona.</div>',
]);

require __DIR__ . '/sector_help_common.php';
