<?php
require __DIR__ . '/sector_help_templates.php';

$help = sector_help_generic([
    'kicker' => 'Ayuda para Asesoria',
    'title' => 'Manual para asesoria',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para servicios de asesoria, consultoria o acompanamiento profesional con clientes, citas, documentos y seguimiento.',
    'person' => 'cliente',
    'personPlural' => 'clientes',
    'professional' => 'asesor',
    'problem' => 'necesidad u objetivo de asesoria',
    'problemTitle' => 'Objetivos de asesoria',
    'task' => 'tarea',
    'taskPlural' => 'tareas',
    'knowledge' => 'base de apoyo',
    'documents' => 'documentos y archivos',
    'extraKnowledge' => '<div class="help-note">La app permite ordenar citas, documentos, tareas e informes aunque no se utilice una base de conocimiento clinica.</div>',
]);

require __DIR__ . '/sector_help_common.php';
