<?php
$help = [
    'kicker' => 'Ayuda para Fisioterapia',
    'title' => 'Manual para fisioterapia',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para consultas y centros de fisioterapia que gestionan pacientes, sesiones, valoraciones funcionales, ejercicios terapeuticos y seguimiento de evolucion.',
    'sidebar' => [
        ['href' => '#inicio', 'label' => 'Inicio rapido'],
        ['href' => '#agenda', 'label' => 'Agenda y sesiones'],
        ['href' => '#pacientes', 'label' => 'Pacientes'],
        ['href' => '#diagnostico', 'label' => 'Valoracion funcional'],
        ['href' => '#ejercicios', 'label' => 'Ejercicios y pautas'],
        ['href' => '#composicion', 'label' => 'Medidas y progreso'],
        ['href' => '#documentacion', 'label' => 'Documentacion'],
        ['href' => '#configuracion', 'label' => 'Configuracion'],
        ['href' => '#problemas', 'label' => 'Problemas frecuentes'],
    ],
    'sections' => [
        [
            'id' => 'agenda',
            'title' => 'Agenda y sesiones',
            'body' => '
                <p>La agenda permite organizar sesiones, reservar huecos, controlar pagos y abrir rapidamente la cita en curso.</p>
                <ul>
                    <li>Usa el detalle de la cita para revisar modalidad, paciente, profesional y estado de pago.</li>
                    <li>Desde la sesion puedes consultar tareas, ejercicios o documentos asociados al paciente.</li>
                    <li>Las citas online pueden incluir enlace de videollamada cuando el servicio lo requiera.</li>
                    <li>El planning de proximas citas ayuda a detectar huecos libres y carga diaria.</li>
                </ul>
            ',
        ],
        [
            'id' => 'pacientes',
            'title' => 'Pacientes',
            'body' => '
                <p>La ficha del paciente centraliza datos de contacto, historial de citas, documentacion y seguimiento.</p>
                <ul>
                    <li>Usa <strong>Mas datos</strong> para anotar motivo inicial, datos de contacto alternativos y notas internas.</li>
                    <li>El historial mantiene el profesional que atendio cada cita en su momento.</li>
                    <li>El plan de trabajo permite asignar ejercicios terapeuticos, pautas o tareas para casa.</li>
                    <li>Las tareas publicadas en portal pueden ser consultadas por el paciente desde su area privada.</li>
                </ul>
            ',
        ],
        [
            'id' => 'diagnostico',
            'title' => 'Valoracion funcional y base de conocimiento',
            'body' => '
                <p>La pestaña de valoracion/diagnostico muestra material de apoyo documental segun el problema funcional seleccionado.</p>
                <ul>
                    <li>Selecciona un problema funcional para consultar tecnicas, pautas, ejercicios y fuentes.</li>
                    <li>Las recomendaciones no sustituyen la valoracion clinica ni el criterio profesional.</li>
                    <li>Las tecnicas se despliegan para ver las tareas o ejercicios recomendados.</li>
                    <li>Puedes importar tareas concretas, todas las de una tecnica o todas las recomendadas.</li>
                </ul>
            ',
        ],
        [
            'id' => 'ejercicios',
            'title' => 'Mapa muscular, ejercicios y pautas',
            'body' => '
                <p>En fisioterapia, el mapa corporal ayuda a localizar ejercicios relacionados con zonas musculares o regiones de trabajo.</p>
                <ul>
                    <li>Selecciona uno o varios musculos para ver ejercicios relacionados.</li>
                    <li>Abre el detalle del ejercicio para revisar instrucciones, imagen y datos disponibles.</li>
                    <li>Agrega ejercicios al plan del paciente como tareas de seguimiento.</li>
                    <li>Marca cada tarea como visible en el portal solo si quieres que el paciente pueda verla.</li>
                </ul>
                <div class="help-note">El mapa muscular es una herramienta de consulta y organizacion. No sustituye exploracion, razonamiento clinico ni consentimiento informado.</div>
            ',
        ],
        [
            'id' => 'composicion',
            'title' => 'Medidas y progreso',
            'body' => '
                <p>Si el centro usa medidas corporales o datos fisicos, la pestaña <strong>Composicion</strong> permite registrar valores orientativos.</p>
                <ul>
                    <li>Peso, altura, IMC y medidas pueden servir para seguimiento global del paciente.</li>
                    <li>Los cambios se sincronizan con la pestaña Evolucion para conservar historial.</li>
                    <li>En Evolucion se pueden crear registros periodicos con nuevas medidas y observaciones.</li>
                    <li>Los graficos ayudan a visualizar cambios cuando hay varios registros.</li>
                </ul>
            ',
        ],
        [
            'id' => 'documentacion',
            'title' => 'Documentacion y cuestionarios',
            'body' => '
                <p>La pestaña <strong>Documentacion</strong> permite subir archivos, cuestionarios o documentos asociados al paciente.</p>
                <ul>
                    <li>Usa tipo Archivo para informes, imagenes, consentimientos o documentos sueltos.</li>
                    <li>Usa tipo Cuestionario para pruebas repetibles con fecha, resultado y observaciones.</li>
                    <li>Decide si cada documento esta disponible o no en el portal del paciente.</li>
                    <li>La nota o resultado del cuestionario puede mantenerse interno si no debe verlo el paciente.</li>
                </ul>
            ',
        ],
        [
            'id' => 'configuracion',
            'title' => 'Configuracion recomendada',
            'body' => '
                <ul>
                    <li>Ajusta servicios y duraciones a valoraciones, sesiones de tratamiento, revisiones o bonos.</li>
                    <li>Configura profesionales y permisos si el centro trabaja con varios fisioterapeutas.</li>
                    <li>Crea plantillas de ejercicios/pautas frecuentes para ahorrar tiempo.</li>
                    <li>Revisa textos, imagen de marca y colores desde Interfaz.</li>
                </ul>
            ',
        ],
        [
            'id' => 'problemas',
            'title' => 'Problemas frecuentes',
            'body' => '
                <details><summary>No aparece la pestaña de valoracion o mapa muscular</summary><p>Comprueba que el plan permite base de conocimiento y que el sector tiene datos importados.</p></details>
                <details><summary>Un paciente no ve sus ejercicios en el portal</summary><p>La tarea debe estar marcada como visible para el portal y el paciente debe tener cuenta activa.</p></details>
                <details><summary>Las medidas no aparecen en graficos</summary><p>Los graficos necesitan al menos registros de evolucion con fecha y valores numericos.</p></details>
            ',
        ],
    ],
];

require __DIR__ . '/sector_help_common.php';
