<?php
$help = [
    'kicker' => 'Ayuda para Fitness',
    'title' => 'Manual para entrenamiento personal',
    'intro' => 'Esta guia resume el uso de SimplyGest Praxis para centros de fitness, entrenadores personales y preparadores que trabajan con clientes, rutinas, medidas corporales y seguimiento del progreso.',
    'sidebar' => [
        ['href' => '#inicio', 'label' => 'Inicio rapido'],
        ['href' => '#agenda', 'label' => 'Agenda y reservas'],
        ['href' => '#clientes', 'label' => 'Clientes'],
        ['href' => '#composicion', 'label' => 'Composicion'],
        ['href' => '#objetivo', 'label' => 'Objetivos y ejercicios'],
        ['href' => '#evolucion', 'label' => 'Evolucion'],
        ['href' => '#portal', 'label' => 'Portal del cliente'],
        ['href' => '#configuracion', 'label' => 'Configuracion'],
        ['href' => '#problemas', 'label' => 'Problemas frecuentes'],
    ],
    'sections' => [
        [
            'id' => 'agenda',
            'title' => 'Agenda y reservas',
            'body' => '
                <p>La agenda permite reservar sesiones presenciales u online, revisar la proxima cita y abrir rapidamente la sesion en curso.</p>
                <ul>
                    <li>Usa los slots disponibles para reservar entrenamientos individuales, sesiones de seguimiento o servicios definidos para el centro.</li>
                    <li>Las citas online pueden guardar un enlace de videollamada para enviarlo al cliente.</li>
                    <li>Desde el detalle de la cita puedes revisar tareas, ejercicios asignados y documentacion vinculada.</li>
                    <li>Si trabajas con varios entrenadores, cada profesional ve sus propias citas en el recordatorio del dashboard.</li>
                </ul>
            ',
        ],
        [
            'id' => 'clientes',
            'title' => 'Clientes',
            'body' => '
                <p>La ficha del cliente centraliza datos de contacto, objetivo, rutinas, documentacion y progresos.</p>
                <ul>
                    <li>Completa email y telefono para usar los accesos rapidos de llamada, WhatsApp e invitaciones al portal.</li>
                    <li>La pestaña <strong>Objetivo</strong> permite consultar ejercicios por musculo o rutinas por objetivo.</li>
                    <li>La pestaña <strong>Plan de trabajo</strong> recoge ejercicios, tareas o pautas asignadas al cliente.</li>
                    <li>La pestaña <strong>Documentacion</strong> sirve para subir cuestionarios, fotos, documentos o archivos de seguimiento.</li>
                </ul>
            ',
        ],
        [
            'id' => 'composicion',
            'title' => 'Composicion corporal',
            'body' => '
                <p>La pestaña <strong>Composicion</strong> registra datos fisicos orientativos para el seguimiento del cliente.</p>
                <ul>
                    <li>Peso, altura y sexo biologico se usan para calcular IMC y estimaciones.</li>
                    <li>Las medidas corporales ayudan a ver cambios en cintura, cadera, pecho, muslo, biceps o gemelo.</li>
                    <li>Los pliegues permiten estimar grasa corporal cuando hay datos suficientes.</li>
                    <li>Al guardar cambios, la app sincroniza estos datos con <strong>Evolucion</strong> para conservar el historial.</li>
                </ul>
                <div class="help-note">Los calculos son orientativos. No sustituyen una valoracion profesional ni una medicion antropometrica formal.</div>
            ',
        ],
        [
            'id' => 'objetivo',
            'title' => 'Objetivos, mapa muscular y ejercicios',
            'body' => '
                <p>En Fitness, la pestaña <strong>Objetivo</strong> incluye herramientas especificas para crear rutinas y asignar ejercicios.</p>
                <ul>
                    <li><strong>Ejercicios por musculo:</strong> selecciona zonas en el mapa corporal para ver ejercicios relacionados.</li>
                    <li><strong>Rutinas por objetivo:</strong> consulta recomendaciones agrupadas por objetivos como perdida de grasa, fuerza o hipertrofia.</li>
                    <li><strong>Rutina personalizada:</strong> genera una propuesta segun objetivo, duracion, nivel y material disponible.</li>
                    <li>Desde cada ejercicio puedes abrir el detalle, revisar imagen, instrucciones y agregarlo al plan del cliente.</li>
                </ul>
                <div class="help-note">Cuando agregas un ejercicio, se guarda como tarea/ejercicio del cliente y puede publicarse o no en su portal.</div>
            ',
        ],
        [
            'id' => 'evolucion',
            'title' => 'Evolucion y graficos',
            'body' => '
                <p>La pestaña <strong>Evolucion</strong> permite registrar seguimientos periodicos del cliente.</p>
                <ul>
                    <li>Crea registros con fecha, nota, descripcion y medidas actualizadas.</li>
                    <li>Si modificas medidas desde Composicion, se crea o actualiza automaticamente la evolucion del dia.</li>
                    <li>La vista de graficos muestra peso, IMC, grasa corporal, medidas y pliegues cuando hay datos historicos.</li>
                    <li>Usa la evolucion para comparar progreso semanal o mensual sin perder los valores anteriores.</li>
                </ul>
            ',
        ],
        [
            'id' => 'portal',
            'title' => 'Portal del cliente',
            'body' => '
                <p>El portal permite al cliente consultar su proxima cita y la informacion que el profesional decida publicar.</p>
                <ul>
                    <li>Puede ver tareas o ejercicios marcados como visibles en el portal.</li>
                    <li>Puede consultar su progreso corporal si el sector lo permite y existen datos registrados.</li>
                    <li>Puede revisar documentos/cuestionarios publicados para el cliente.</li>
                    <li>Las notas internas y datos no publicados siguen siendo privados del profesional.</li>
                </ul>
            ',
        ],
        [
            'id' => 'configuracion',
            'title' => 'Configuracion recomendada',
            'body' => '
                <ul>
                    <li>Revisa servicios y duraciones para que encajen con entrenamientos, valoraciones o seguimientos.</li>
                    <li>Activa el portal si quieres que el cliente consulte rutinas, tareas y progreso.</li>
                    <li>Configura plantillas de tareas como rutinas reutilizables: tren superior, fuerza, perdida de grasa o movilidad.</li>
                    <li>Usa el color e imagen de marca del centro desde la pestaña Interfaz.</li>
                </ul>
            ',
        ],
        [
            'id' => 'problemas',
            'title' => 'Problemas frecuentes',
            'body' => '
                <details><summary>No aparecen ejercicios al seleccionar un musculo</summary><p>Comprueba que el sector Fitness tiene datos importados y que el mapa muscular esta activo en el plan contratado.</p></details>
                <details><summary>No se ve el boton de progreso en el portal</summary><p>Solo aparece en sectores con composicion corporal activa y cuando el cliente tiene acceso al portal.</p></details>
                <details><summary>El calculo de grasa corporal no se completa</summary><p>Revisa peso, altura, fecha de nacimiento, sexo biologico y, si usas pliegues, que esten introducidos los necesarios.</p></details>
            ',
        ],
    ],
];

require __DIR__ . '/sector_help_common.php';
