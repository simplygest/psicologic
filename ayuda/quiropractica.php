<?php
$help = [
    'kicker' => 'Ayuda para Quiropractica',
    'title' => 'Manual para quiropractica',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas quiropracticas que gestionan pacientes, citas, objetivos funcionales, pautas, ejercicios y seguimiento de evolucion.',
    'sidebar' => [
        ['href' => '#inicio', 'label' => 'Inicio rapido'],
        ['href' => '#agenda', 'label' => 'Agenda'],
        ['href' => '#pacientes', 'label' => 'Pacientes'],
        ['href' => '#objetivo', 'label' => 'Objetivos y zonas'],
        ['href' => '#pautas', 'label' => 'Pautas y ejercicios'],
        ['href' => '#evolucion', 'label' => 'Evolucion'],
        ['href' => '#portal', 'label' => 'Portal'],
        ['href' => '#configuracion', 'label' => 'Configuracion'],
        ['href' => '#problemas', 'label' => 'Problemas frecuentes'],
    ],
    'sections' => [
        [
            'id' => 'agenda',
            'title' => 'Agenda',
            'body' => '
                <p>La agenda ayuda a controlar sesiones, revisiones y seguimiento de pacientes sin perder la vision diaria de la consulta.</p>
                <ul>
                    <li>Reserva desde huecos disponibles y consulta rapidamente la cita en curso.</li>
                    <li>El detalle de cita muestra paciente, profesional, modalidad, pago y acceso a tareas/documentos.</li>
                    <li>Las citas canceladas se conservan para historial y control interno.</li>
                    <li>En gabinetes con varios profesionales, cada usuario ve sus propias citas rapidas.</li>
                </ul>
            ',
        ],
        [
            'id' => 'pacientes',
            'title' => 'Pacientes',
            'body' => '
                <p>La ficha del paciente concentra datos de contacto, historial, documentacion, objetivos y plan de trabajo.</p>
                <ul>
                    <li>Registra motivo inicial, observaciones internas y documentacion relevante.</li>
                    <li>Usa el historial para revisar citas anteriores y profesional que atendio cada sesion.</li>
                    <li>Asigna pautas o ejercicios al plan de trabajo para seguimiento entre sesiones.</li>
                    <li>Publica solo las tareas o documentos que quieras que el paciente vea en su portal.</li>
                </ul>
            ',
        ],
        [
            'id' => 'objetivo',
            'title' => 'Objetivos, zonas y base de conocimiento',
            'body' => '
                <p>La pestaña de objetivo/diagnostico ofrece material de apoyo segun el problema funcional o zona de trabajo seleccionada.</p>
                <ul>
                    <li>Selecciona el objetivo o problema para consultar tecnicas, pautas, tareas y fuentes.</li>
                    <li>El mapa muscular permite localizar ejercicios relacionados con una region corporal.</li>
                    <li>Las recomendaciones son apoyo documental y no sustituyen la valoracion profesional.</li>
                    <li>Puedes importar pautas concretas al plan de trabajo del paciente.</li>
                </ul>
            ',
        ],
        [
            'id' => 'pautas',
            'title' => 'Pautas y ejercicios',
            'body' => '
                <p>Las pautas y ejercicios funcionan como tareas asignadas al paciente.</p>
                <ul>
                    <li>Agrega ejercicios desde el mapa muscular o desde recomendaciones por objetivo.</li>
                    <li>Revisa el detalle del ejercicio antes de asignarlo.</li>
                    <li>Marca tareas como pendientes o completadas desde la ficha o desde la cita.</li>
                    <li>Crea plantillas para pautas frecuentes y reutilizalas en otros pacientes.</li>
                </ul>
            ',
        ],
        [
            'id' => 'evolucion',
            'title' => 'Evolucion',
            'body' => '
                <p>La evolucion recoge registros periodicos con notas, observaciones, documentos y valores fisicos si se usan.</p>
                <ul>
                    <li>Crea registros con fecha para dejar constancia del progreso.</li>
                    <li>Adjunta archivos cuando necesites guardar documentos o imagenes de seguimiento.</li>
                    <li>Si usas medidas corporales, la pestaña Composicion se sincroniza con Evolucion.</li>
                    <li>Los graficos aparecen cuando existen varios registros con valores comparables.</li>
                </ul>
            ',
        ],
        [
            'id' => 'portal',
            'title' => 'Portal del paciente',
            'body' => '
                <p>El portal permite al paciente consultar informacion seleccionada por el profesional.</p>
                <ul>
                    <li>Proxima cita y citas registradas.</li>
                    <li>Pautas o ejercicios visibles en portal.</li>
                    <li>Documentos publicados por el profesional.</li>
                    <li>Progreso corporal si el sector y el plan lo permiten.</li>
                </ul>
            ',
        ],
        [
            'id' => 'configuracion',
            'title' => 'Configuracion recomendada',
            'body' => '
                <ul>
                    <li>Configura servicios y duraciones de acuerdo con valoraciones, sesiones y revisiones.</li>
                    <li>Activa recordatorios si quieres avisar al paciente antes de su cita.</li>
                    <li>Usa plantillas para pautas recurrentes.</li>
                    <li>Revisa permisos de equipo si trabajan varios profesionales.</li>
                </ul>
            ',
        ],
        [
            'id' => 'problemas',
            'title' => 'Problemas frecuentes',
            'body' => '
                <details><summary>No se muestran ejercicios por zona</summary><p>Comprueba que la base de conocimiento del sector esta importada y que el plan permite esta funcion.</p></details>
                <details><summary>Una pauta no aparece al paciente</summary><p>Debe estar marcada como visible en portal. Si no, se mantiene como informacion interna.</p></details>
                <details><summary>No puedo generar informes o estadisticas</summary><p>Revisa que el plan contratado tenga habilitada la seccion de informes y estadisticas.</p></details>
            ',
        ],
    ],
];

require __DIR__ . '/sector_help_common.php';
