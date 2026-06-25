<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Sexologia',
    'title' => 'Manual para sexologia',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas de sexologia que gestionan pacientes, sesiones, objetivos terapeuticos, pautas e informes.',
    'person' => 'paciente',
    'personPlural' => 'pacientes',
    'professional' => 'profesional',
    'problem' => 'motivo, dificultad u objetivo terapeutico',
    'problemTitle' => 'Objetivos terapeuticos',
    'task' => 'pauta',
    'taskPlural' => 'pautas',
    'knowledge' => 'base de conocimiento sexologica',
    'documents' => 'cuestionarios, documentos e informes',
    'extraKnowledge' => '<div class="help-note">La informacion mostrada es apoyo documental. Debe tratarse con especial cuidado, confidencialidad y criterio profesional.</div>',
]);

require __DIR__ . '/sector_help_common.php';
