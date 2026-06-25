<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Logopedia',
    'title' => 'Manual para logopedia',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas de logopedia que trabajan con pacientes, objetivos de intervencion, ejercicios, cuestionarios y seguimiento.',
    'person' => 'paciente',
    'personPlural' => 'pacientes',
    'professional' => 'logopeda',
    'problem' => 'area, dificultad u objetivo de intervencion',
    'problemTitle' => 'Intervencion logopedica',
    'task' => 'ejercicio',
    'taskPlural' => 'ejercicios',
    'knowledge' => 'base de conocimiento logopedica',
    'documents' => 'cuestionarios, informes y materiales',
    'extraKnowledge' => '<div class="help-note">Usa la base de conocimiento como apoyo para organizar objetivos y ejercicios. La evaluacion y la intervencion dependen siempre del criterio profesional.</div>',
]);

require __DIR__ . '/sector_help_common.php';
