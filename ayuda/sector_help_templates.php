<?php

function sector_help_generic(array $cfg): array
{
    $kicker = $cfg['kicker'] ?? 'Ayuda sectorial';
    $title = $cfg['title'] ?? 'Manual de uso';
    $intro = $cfg['intro'] ?? 'Esta guia resume el uso de SimplyGest Praxis para este sector.';
    $person = $cfg['person'] ?? 'cliente';
    $personPlural = $cfg['personPlural'] ?? ($person . 's');
    $professional = $cfg['professional'] ?? 'profesional';
    $problem = $cfg['problem'] ?? 'diagnostico u objetivo';
    $problemTitle = $cfg['problemTitle'] ?? 'Diagnostico y objetivos';
    $task = $cfg['task'] ?? 'tarea';
    $taskPlural = $cfg['taskPlural'] ?? 'tareas';
    $knowledge = $cfg['knowledge'] ?? 'base de conocimiento';
    $documents = $cfg['documents'] ?? 'documentos y cuestionarios';
    $extraKnowledge = $cfg['extraKnowledge'] ?? '';
    $extraConfig = $cfg['extraConfig'] ?? '';

    return [
        'kicker' => $kicker,
        'title' => $title,
        'intro' => $intro,
        'sidebar' => [
            ['href' => '#inicio', 'label' => 'Inicio rapido'],
            ['href' => '#agenda', 'label' => 'Agenda'],
            ['href' => '#ficha', 'label' => ucfirst($personPlural)],
            ['href' => '#conocimiento', 'label' => $problemTitle],
            ['href' => '#plan', 'label' => 'Plan de trabajo'],
            ['href' => '#evolucion', 'label' => 'Evolucion'],
            ['href' => '#documentacion', 'label' => 'Documentacion'],
            ['href' => '#portal', 'label' => 'Portal'],
            ['href' => '#configuracion', 'label' => 'Configuracion'],
        ],
        'sections' => [
            [
                'id' => 'agenda',
                'title' => 'Agenda y reservas',
                'body' => '
                    <p>La agenda permite organizar citas, sesiones, revisiones y seguimientos sin perder la vision diaria del centro.</p>
                    <ul>
                        <li>Reserva desde huecos disponibles y abre rapidamente la cita en curso.</li>
                        <li>Consulta modalidad, estado de pago, profesional asignado y datos principales del ' . htmlspecialchars($person) . '.</li>
                        <li>Usa citas online con enlace de videollamada cuando el servicio lo necesite.</li>
                        <li>Las citas canceladas se conservan en historial para control interno.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'ficha',
                'title' => 'Ficha de ' . $person,
                'body' => '
                    <p>La ficha centraliza datos personales, historial de citas, plan de trabajo, evolucion, documentacion e informes.</p>
                    <ul>
                        <li>Completa datos de contacto para usar llamadas, WhatsApp, emails e invitaciones al portal.</li>
                        <li>Usa <strong>Mas datos</strong> para informacion complementaria y notas internas.</li>
                        <li>El historial conserva las citas anteriores y el profesional que intervino en cada momento.</li>
                        <li>La pesta&ntilde;a de informes permite generar borradores, subir versiones finales y controlar su visibilidad.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'conocimiento',
                'title' => $problemTitle . ' y ' . $knowledge,
                'body' => '
                    <p>La pesta&ntilde;a de ' . htmlspecialchars(strtolower($problemTitle)) . ' permite consultar material de apoyo segun el ' . htmlspecialchars($problem) . ' seleccionado.</p>
                    <ul>
                        <li>Selecciona el ' . htmlspecialchars($problem) . ' para ver tecnicas, pautas, recomendaciones, documentos o fuentes.</li>
                        <li>Las recomendaciones son material de apoyo y no sustituyen el criterio del ' . htmlspecialchars($professional) . '.</li>
                        <li>Puedes importar ' . htmlspecialchars($taskPlural) . ' una a una, por bloque o desde plantillas reutilizables.</li>
                        <li>Si el plan lo permite, cada profesional puede ampliar su consulta con sectores relacionados.</li>
                    </ul>
                    ' . $extraKnowledge . '
                ',
            ],
            [
                'id' => 'plan',
                'title' => 'Plan de trabajo',
                'body' => '
                    <p>El plan de trabajo reune ' . htmlspecialchars($taskPlural) . ', pautas o actividades asignadas al ' . htmlspecialchars($person) . '.</p>
                    <ul>
                        <li>Crea ' . htmlspecialchars($taskPlural) . ' manualmente o importalas desde plantillas.</li>
                        <li>Usa plantillas para recomendaciones habituales y casos recurrentes.</li>
                        <li>Decide si cada ' . htmlspecialchars($task) . ' estara disponible en el portal.</li>
                        <li>Segun la configuracion, las ' . htmlspecialchars($taskPlural) . ' pueden marcarse como pendientes o completadas.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'evolucion',
                'title' => 'Evolucion',
                'body' => '
                    <p>Evolucion permite registrar el seguimiento entre sesiones con fecha, descripcion, observaciones y archivos.</p>
                    <ul>
                        <li>Crea registros periodicos para documentar progreso, incidencias y proximos pasos.</li>
                        <li>En sectores con medidas o valores numericos, la app puede mostrar graficos de evolucion.</li>
                        <li>Usa esta seccion para preparar informes de evolucion y revisar el historial completo.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'documentacion',
                'title' => 'Documentacion',
                'body' => '
                    <p>Documentacion agrupa archivos, ' . htmlspecialchars($documents) . ' asociados al ' . htmlspecialchars($person) . '.</p>
                    <ul>
                        <li>Sube archivos generales, cuestionarios, documentos de trabajo o material complementario.</li>
                        <li>Indica fecha, titulo, descripcion y estado cuando proceda.</li>
                        <li>Marca solo lo que debe estar disponible en el portal.</li>
                        <li>Las notas internas pueden mantenerse privadas para el ' . htmlspecialchars($professional) . '.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'portal',
                'title' => 'Portal del ' . $person,
                'body' => '
                    <p>El portal muestra al ' . htmlspecialchars($person) . ' solo la informacion publicada por el centro.</p>
                    <ul>
                        <li>Proxima cita, historial y cancelacion cuando este permitido.</li>
                        <li>' . ucfirst(htmlspecialchars($taskPlural)) . ' visibles en portal.</li>
                        <li>Documentos, cuestionarios e informes publicados.</li>
                        <li>Pagos pendientes si la pasarela esta activada.</li>
                    </ul>
                ',
            ],
            [
                'id' => 'configuracion',
                'title' => 'Configuracion recomendada',
                'body' => '
                    <ul>
                        <li>Revisa servicios, duraciones y modalidades segun tu forma de trabajar.</li>
                        <li>Configura profesionales, permisos, plantillas, portal y visibilidad publica.</li>
                        <li>Personaliza colores, logo y textos para adaptar la app al sector.</li>
                        <li>Si una funcion no aparece, revisa primero el plan contratado y despues la configuracion de interfaz.</li>
                    </ul>
                    ' . $extraConfig . '
                ',
            ],
        ],
    ];
}
