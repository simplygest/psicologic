<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Nutricion',
    'title' => 'Manual para nutricion',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas de nutricion que gestionan clientes, objetivos nutricionales, pautas, documentacion y seguimiento de evolucion.',
    'person' => 'cliente',
    'personPlural' => 'clientes',
    'professional' => 'nutricionista',
    'problem' => 'objetivo nutricional o situacion de seguimiento',
    'problemTitle' => 'Objetivos nutricionales',
    'task' => 'pauta',
    'taskPlural' => 'pautas',
    'knowledge' => 'base de conocimiento nutricional',
    'documents' => 'registros, cuestionarios y documentos',
    'extraKnowledge' => '<div class="help-note">Las recomendaciones son apoyo documental. Deben adaptarse a historia clinica, analiticas, medicacion, preferencias, contexto y competencias profesionales.</div>',
    'extraConfig' => '<div class="help-note">En nutricion suele ser util activar Composicion, Evolucion y Portal para que el cliente consulte progreso, pautas y documentos publicados.</div>',
]);

require __DIR__ . '/sector_help_common.php';
