<?php
$help = [
    'kicker' => 'Ayuda para Osteopatia',
    'title' => 'Manual para osteopatia',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas de osteopatia que gestionan pacientes, sesiones, objetivos funcionales, pautas, ejercicios, documentos y evolucion.',
    'sidebar' => [
        ['href' => '#inicio', 'label' => 'Inicio rapido'],
        ['href' => '#agenda', 'label' => 'Agenda'],
        ['href' => '#pacientes', 'label' => 'Pacientes'],
        ['href' => '#valoracion', 'label' => 'Valoracion'],
        ['href' => '#pautas', 'label' => 'Pautas'],
        ['href' => '#evolucion', 'label' => 'Evolucion'],
        ['href' => '#documentacion', 'label' => 'Documentacion'],
        ['href' => '#portal', 'label' => 'Portal'],
        ['href' => '#configuracion', 'label' => 'Configuracion'],
    ],
    'sections' => [
        [
            'id' => 'agenda',
            'title' => 'Agenda',
            'body' => '
                <p>La agenda centraliza reservas, sesiones en curso, proximas citas y cancelaciones.</p>
                <ul>
                    <li>Reserva sesiones desde slots libres y abre el detalle de una cita desde el calendario.</li>
                    <li>El card de cita en curso permite acceder rapido a la sesion del paciente.</li>
                    <li>El profesional puede cambiar una cita de presencial a online cuando el servicio lo permita.</li>
                    <li>Los recordatorios por email se configuran desde el panel de administracion.</li>
                </ul>
            ',
        ],
        [
            'id' => 'pacientes',
            'title' => 'Pacientes',
            'body' => '
                <p>La ficha del paciente reune datos, historial, plan de trabajo, evolucion y documentacion.</p>
                <ul>
                    <li>Usa las notas internas para informacion que no debe mostrarse al paciente.</li>
                    <li>El historial de citas conserva fecha, profesional, modalidad y estado de pago.</li>
                    <li>El plan de trabajo permite asignar pautas, ejercicios y tareas de seguimiento.</li>
                    <li>Los informes internos o para paciente pueden generarse desde la ficha cuando esten disponibles en el plan.</li>
                </ul>
            ',
        ],
        [
            'id' => 'valoracion',
            'title' => 'Valoracion, objetivo y mapa corporal',
            'body' => '
                <p>La pestaña de valoracion/objetivo ayuda a consultar material de apoyo segun problema, objetivo o region corporal.</p>
                <ul>
                    <li>Selecciona un problema u objetivo para ver tecnicas, pautas, cuestionarios o fuentes sugeridas.</li>
                    <li>El mapa muscular permite consultar ejercicios relacionados con zonas concretas.</li>
                    <li>Las recomendaciones son material de apoyo y no sustituyen el criterio profesional.</li>
                    <li>Puedes importar tareas una a una, por tecnica o en bloque cuando proceda.</li>
                </ul>
            ',
        ],
        [
            'id' => 'pautas',
            'title' => 'Pautas y ejercicios',
            'body' => '
                <p>Las pautas y ejercicios asignados se gestionan desde el plan de trabajo del paciente.</p>
                <ul>
                    <li>Crea pautas manuales o importa plantillas ya preparadas.</li>
                    <li>Agrega ejercicios desde el mapa muscular o desde la base de conocimiento.</li>
                    <li>Marca cada tarea como pendiente o completada.</li>
                    <li>Decide si el paciente puede verla desde su portal.</li>
                </ul>
            ',
        ],
        [
            'id' => 'evolucion',
            'title' => 'Evolucion',
            'body' => '
                <p>La evolucion permite registrar cambios entre sesiones con fecha, descripcion, observaciones y archivos.</p>
                <ul>
                    <li>Usa registros periodicos para documentar progreso, respuesta al tratamiento y proximos pasos.</li>
                    <li>Si usas medidas corporales, Composicion y Evolucion se sincronizan automaticamente.</li>
                    <li>Los graficos ayudan a revisar cambios cuando hay varios valores registrados.</li>
                    <li>Adjunta documentos cuando necesites conservar informes, imagenes o pruebas.</li>
                </ul>
            ',
        ],
        [
            'id' => 'documentacion',
            'title' => 'Documentacion',
            'body' => '
                <p>Documentacion agrupa archivos y cuestionarios del paciente.</p>
                <ul>
                    <li>Sube archivos generales como informes, documentos o imagenes.</li>
                    <li>Usa cuestionarios para registros repetibles con fecha, nota o resultado.</li>
                    <li>Publica solo los documentos que el paciente deba ver en su portal.</li>
                    <li>Mantén privadas las observaciones internas cuando no sean adecuadas para el portal.</li>
                </ul>
            ',
        ],
        [
            'id' => 'portal',
            'title' => 'Portal del paciente',
            'body' => '
                <p>El portal muestra al paciente su proxima cita y los elementos publicados por el profesional.</p>
                <ul>
                    <li>Citas y proxima reserva.</li>
                    <li>Pautas o ejercicios visibles.</li>
                    <li>Documentos publicados.</li>
                    <li>Progreso corporal si esta disponible para el sector.</li>
                </ul>
            ',
        ],
        [
            'id' => 'configuracion',
            'title' => 'Configuracion recomendada',
            'body' => '
                <ul>
                    <li>Ajusta servicios, modalidades y duraciones a tu forma de trabajar.</li>
                    <li>Configura equipo profesional si hay varios especialistas.</li>
                    <li>Usa plantillas para pautas habituales y recomendaciones recurrentes.</li>
                    <li>Revisa portal, emails, recordatorios y visibilidad publica segun el plan contratado.</li>
                </ul>
                <div class="help-note">Si una funcion no aparece, revisa primero el plan contratado y despues la configuracion de interfaz.</div>
            ',
        ],
    ],
];

require __DIR__ . '/sector_help_common.php';
