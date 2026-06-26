<?php
header('Content-Type: text/html; charset=UTF-8');

$app_name = 'SimplyGest Praxis';
$brand_logo_path = 'uploads/global/sgpraxis-logo transparente.png';
$full_logo_path = 'uploads/global/sgpraxis-completo-1-transparente.png';
$hero_image_path = 'uploads/global/landing/praxis-multisector-hero.webp';
$sector_image_paths = [
    'psicologia' => 'uploads/global/landing/sectors/psicologia.webp',
    'fisioterapia' => 'uploads/global/landing/sectors/fisioterapia.webp',
    'fitness' => 'uploads/global/landing/sectors/fitness.webp',
    'nutricion' => 'uploads/global/landing/sectors/nutricion.webp',
    'logopedia' => 'uploads/global/landing/sectors/logopedia.webp',
    'osteopatia' => 'uploads/global/landing/sectors/osteopatia.webp',
    'psicopedagogia' => 'uploads/global/landing/sectors/psicopedagogia.webp',
    'quiropractica' => 'uploads/global/landing/sectors/quiropractica.webp',
    'sexologia' => 'uploads/global/landing/sectors/sexologia.webp',
    'terapia_ocupacional' => 'uploads/global/landing/sectors/terapia_ocupacional.webp',
];
$brand_logo_url = praxis_landing_image_data_uri($brand_logo_path);
$full_logo_url = praxis_landing_image_data_uri($full_logo_path);
$hero_image_url = praxis_landing_image_data_uri($hero_image_path);
$sector_image_urls = [];
foreach ($sector_image_paths as $sector_key => $sector_image_path) {
    $sector_image_urls[$sector_key] = praxis_landing_image_data_uri($sector_image_path);
}
$showcase_cards = [
    [
        'title' => 'Agenda, reservas y Portal de Pacientes',
        'text' => 'Una agenda personalizable para gestionar citas, reservas online, recordatorios y acceso privado para tus pacientes',
        'image_path' => 'uploads/global/landing/showcase/agenda.webp',
        'placeholder_title' => 'Agenda y Portal',
        'placeholder_text' => 'Calendario, reservas y acceso privado',
        'accent' => '#4285f4',
    ],
    [
        'title' => 'Una app con tu identidad',
        'text' => 'Personaliza la interfaz con tus colores, logotipo y módulos visibles para que la plataforma encaje con tu identidad.',
        'image_path' => 'uploads/global/landing/showcase/personalizacion.webp',
        'placeholder_title' => 'Personalización',
        'placeholder_text' => 'Colores, logo y ventanas adaptadas',
        'accent' => '#00b8d9',
    ],
];
$showcase_focus_cards = [
    'default' => [
        'title' => 'Diagnósticos, objetivos, tareas e informes',
        'text' => 'Consulta información pública de apoyo, transforma recomendaciones en tareas y genera informes estructurados a partir de los datos del caso.',
        'image_path' => 'uploads/global/landing/showcase/diagnostico-informes.webp',
        'placeholder_title' => 'Diagnóstico e informes',
        'placeholder_text' => 'Base de conocimiento, tareas e informes',
        'accent' => '#4285f4',
    ],
    'fitness' => [
        'title' => 'Gráficos de evolución y rutinas de ejercicios',
        'text' => 'Busca ejercicios y rutinas por músculo, obtén instrucciones, calorías, imágenes, etc. de cada ejercicio o crea rutinas personalizadas según objetivo, equipamiento disponible, etc.',
        'image_path' => 'uploads/global/landing/showcase/fitness-evolucion.webp',
        'placeholder_title' => 'Fitness y evolución',
        'placeholder_text' => 'Body Muscles, ejercicios y gráficos',
        'accent' => '#16a085',
    ],
];
$showcase_audience_terms = [
    'default' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'psicologia' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'sexologia' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'fisioterapia' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'osteopatia' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'quiropractica' => ['title' => 'Pacientes', 'text' => 'pacientes'],
    'fitness' => ['title' => 'Clientes', 'text' => 'clientes'],
    'nutricion' => ['title' => 'Clientes', 'text' => 'clientes'],
    'coaching' => ['title' => 'Clientes', 'text' => 'clientes'],
    'logopedia' => ['title' => 'Usuarios', 'text' => 'usuarios'],
    'terapia_ocupacional' => ['title' => 'Usuarios', 'text' => 'usuarios'],
    'psicopedagogia' => ['title' => 'Alumnos', 'text' => 'alumnos'],
    'oposiciones' => ['title' => 'Alumnos', 'text' => 'alumnos'],
];
foreach ($showcase_cards as $showcase_index => $showcase_card) {
    $showcase_image_url = praxis_landing_image_data_uri($showcase_card['image_path']);
    if ($showcase_image_url === '') {
        $showcase_image_url = praxis_landing_placeholder_data_uri(
            $showcase_card['placeholder_title'],
            $showcase_card['placeholder_text'],
            $showcase_card['accent']
        );
    }
    $showcase_cards[$showcase_index]['image_url'] = $showcase_image_url;
}
foreach ($showcase_focus_cards as $showcase_key => $showcase_card) {
    $showcase_image_url = praxis_landing_image_data_uri($showcase_card['image_path']);
    if ($showcase_image_url === '') {
        $showcase_image_url = praxis_landing_placeholder_data_uri(
            $showcase_card['placeholder_title'],
            $showcase_card['placeholder_text'],
            $showcase_card['accent']
        );
    }
    $showcase_focus_cards[$showcase_key]['image_url'] = $showcase_image_url;
}
$default_showcase_focus = $showcase_focus_cards['default'];
$year = date('Y');

function praxis_landing_image_data_uri($relative_path)
{
    $local_path = __DIR__ . '/../' . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if (!is_file($local_path)) {
        return '';
    }

    $extension = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $mime = 'image/jpeg';
            break;
        case 'webp':
            $mime = 'image/webp';
            break;
        case 'gif':
            $mime = 'image/gif';
            break;
        default:
            $mime = 'image/png';
            break;
    }

    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($local_path));
}

function praxis_landing_placeholder_data_uri($title, $subtitle, $accent)
{
    $safe_title = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
    $safe_subtitle = htmlspecialchars((string) $subtitle, ENT_QUOTES, 'UTF-8');
    $safe_accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $accent) ? (string) $accent : '#4285f4';
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="720" viewBox="0 0 1200 720">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#f7fbff"/>
      <stop offset="1" stop-color="#e8f2ff"/>
    </linearGradient>
    <linearGradient id="accent" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="$safe_accent"/>
      <stop offset="1" stop-color="#00b8d9"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="720" rx="42" fill="url(#bg)"/>
  <rect x="72" y="72" width="1056" height="576" rx="34" fill="#ffffff" stroke="#dbe6f5" stroke-width="3"/>
  <rect x="122" y="122" width="300" height="456" rx="28" fill="#f2f7ff"/>
  <rect x="462" y="122" width="616" height="96" rx="24" fill="url(#accent)" opacity=".92"/>
  <rect x="462" y="254" width="270" height="132" rx="24" fill="#f6faff" stroke="#dbe6f5" stroke-width="2"/>
  <rect x="770" y="254" width="308" height="132" rx="24" fill="#f6faff" stroke="#dbe6f5" stroke-width="2"/>
  <rect x="462" y="422" width="616" height="156" rx="24" fill="#f6faff" stroke="#dbe6f5" stroke-width="2"/>
  <circle cx="208" cy="206" r="38" fill="$safe_accent" opacity=".85"/>
  <rect x="174" y="288" width="196" height="22" rx="11" fill="#c8d9f0"/>
  <rect x="174" y="336" width="152" height="22" rx="11" fill="#d8e6f7"/>
  <rect x="174" y="384" width="176" height="22" rx="11" fill="#d8e6f7"/>
  <text x="504" y="182" font-family="Arial, Helvetica, sans-serif" font-size="36" font-weight="800" fill="#ffffff">$safe_title</text>
  <text x="504" y="506" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700" fill="#243447">$safe_subtitle</text>
</svg>
SVG;
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
}

$sector_profiles = [
    'psicologia' => [
        'label' => 'Psicología',
        'hero' => 'Gestiona pacientes, citas, evolución clínica e informes desde una sola plataforma.',
        'lead' => 'SimplyGest Praxis ayuda a psicólogos y gabinetes a centralizar agenda, historia del paciente, tareas terapéuticas, cuestionarios, documentación, informes y base de conocimiento clínica.',
        'title' => 'Pensada para el día a día de una consulta de psicología',
        'intro' => 'Desde la primera cita hasta el informe de evolución, cada módulo usa lenguaje y flujos propios del trabajo terapéutico.',
        'features' => [
            ['Agenda clínica', 'Sesiones presenciales u online, próxima cita, sesión en curso, recordatorios y reservas cuando el portal está activo.'],
            ['Ficha del paciente', 'Datos personales, motivo de consulta, diagnóstico, historial de citas, evolución, tareas, documentos y bonos.'],
            ['Base de conocimiento', 'Diagnósticos, técnicas, pautas, tareas sugeridas, cuestionarios y fuentes de apoyo documental.'],
            ['Informes profesionales', 'Informes internos, para pacientes, clínicos y de evolución, con borrador, versión final y control de pago.'],
            ['Portal del paciente', 'Tareas visibles, citas, documentación, informes descargables y acceso privado cuando el gabinete lo permite.'],
            ['Solo o en equipo', 'Adaptado a un solo terapeuta o a equipos de trabajo con especialidades diferentes.'],
        ],
    ],
    'fisioterapia' => [
        'label' => 'Fisioterapia',
        'hero' => 'Gestiona pacientes, sesiones, ejercicios terapéuticos y evolución funcional.',
        'lead' => 'SimplyGest Praxis adapta agenda, ficha, documentación, objetivos, pautas y seguimiento a clínicas de fisioterapia y profesionales de rehabilitación.',
        'title' => 'Seguimiento claro para fisioterapia y rehabilitación',
        'intro' => 'Combina agenda, historial, ejercicios, valoración funcional, documentos e informes de evolución en una sola herramienta.',
        'features' => [
            ['Agenda y sesiones', 'Organiza citas presenciales u online y accede rápido a la sesión que está en curso.'],
            ['Ficha del paciente', 'Datos personales, problema funcional, antecedentes, evolución y documentación asociada.'],
            ['Ejercicios y pautas', 'Consulta ejercicios recomendados, mapa muscular y rutinas relacionadas con el problema tratado.'],
            ['Evolución funcional', 'Registra medidas, notas, progreso y gráficos para valorar la evolución del tratamiento.'],
            ['Informes de evolución', 'Prepara resúmenes para el paciente o para uso profesional con datos estructurados.'],
            ['Portal del paciente', 'Comparte ejercicios, documentos e informes cuando quieras que el paciente los consulte.'],
        ],
    ],
    'fitness' => [
        'label' => 'Fitness',
        'hero' => 'Gestiona clientes, rutinas, ejercicios, medidas corporales y progreso.',
        'lead' => 'SimplyGest Praxis convierte la ficha del cliente en un centro de trabajo para entrenadores: objetivos, rutinas, mapa muscular, ejercicios con GIF, composición corporal y evolución.',
        'title' => 'Una plataforma para entrenadores y centros fitness',
        'intro' => 'El foco está en objetivos, rutinas, ejercicios, medidas y progreso visible para el cliente.',
        'features' => [
            ['Clientes y objetivos', 'Ficha adaptada a clientes fitness con objetivos, composición corporal y seguimiento del progreso.'],
            ['Mapa muscular', 'Selecciona zonas del cuerpo para consultar ejercicios relacionados de forma visual.'],
            ['Rutinas personalizadas', 'Genera o prepara rutinas por objetivo, nivel, duración, material disponible y zonas trabajadas.'],
            ['Ejercicios con detalle', 'Muestra instrucciones, recomendaciones, GIF y datos útiles para ejecutar cada ejercicio.'],
            ['Evolución corporal', 'Peso, altura, IMC, grasa corporal, medidas, pliegues y gráficos de progreso.'],
            ['Portal del cliente', 'Comparte rutinas, ejercicios, informes y progreso cuando quieras dar visibilidad al cliente.'],
        ],
    ],
    'nutricion' => [
        'label' => 'Nutrición',
        'hero' => 'Gestiona clientes, objetivos, documentación y evolución corporal.',
        'lead' => 'SimplyGest Praxis ayuda a nutricionistas y centros de bienestar a registrar objetivos, composición corporal, documentos, informes y evolución del cliente.',
        'title' => 'Seguimiento práctico para nutrición y bienestar',
        'intro' => 'Pensado para consultar rápidamente datos corporales, progreso, documentación, objetivos y recomendaciones.',
        'features' => [
            ['Ficha del cliente', 'Datos personales, objetivos, hábitos, documentos y seguimiento profesional.'],
            ['Composición corporal', 'Peso, altura, IMC, grasa corporal, medidas, pliegues y evolución histórica.'],
            ['Informes de evolución', 'Resúmenes con datos actuales, progreso y gráficos para revisar resultados.'],
            ['Documentación', 'Archivos, cuestionarios, analíticas o informes asociados a cada cliente.'],
            ['Portal del cliente', 'Acceso privado a documentos, informes y progreso cuando el profesional lo autoriza.'],
            ['Personalización', 'Textos, campos y funciones adaptables al tipo de consulta o centro.'],
        ],
    ],
    'logopedia' => [
        'label' => 'Logopedia',
        'hero' => 'Gestiona usuarios, sesiones, pautas, documentos e informes de seguimiento.',
        'lead' => 'SimplyGest Praxis permite a logopedas organizar sesiones, evolución, documentación, pautas de trabajo e informes para familias o centros.',
        'title' => 'Organización clara para logopedia',
        'intro' => 'Una ficha completa para trabajar objetivos, pautas, evolución y documentos de cada usuario.',
        'features' => [
            ['Agenda de sesiones', 'Calendario mensual y semanal con acceso rápido a cada cita.'],
            ['Ficha del usuario', 'Datos, motivo de consulta, objetivos, evolución, documentos y tareas pautadas.'],
            ['Pautas y ejercicios', 'Plantillas reutilizables y recomendaciones adaptadas al trabajo del especialista.'],
            ['Informes y documentos', 'Borradores, versiones finales y archivos disponibles para consulta o descarga.'],
            ['Portal privado', 'Comparte documentos o pautas con familias y usuarios cuando proceda.'],
            ['Solo o en equipo', 'Adaptado a un solo especialista o a equipos de trabajo con especialidades diferentes.'],
        ],
    ],
    'psicopedagogia' => [
        'label' => 'Psicopedagogía',
        'hero' => 'Gestiona alumnos, sesiones, objetivos, pautas e informes de seguimiento.',
        'lead' => 'SimplyGest Praxis ayuda a organizar evaluación, objetivos, pautas, documentación, informes y evolución en contextos psicopedagógicos.',
        'title' => 'Apoyo estructurado para psicopedagogía',
        'intro' => 'Una forma clara de reunir evaluación, seguimiento, documentos, pautas y comunicación con familias o centros.',
        'features' => [
            ['Ficha del alumno', 'Datos, motivo de intervención, objetivos, evolución y documentos relevantes.'],
            ['Sesiones y seguimiento', 'Agenda, historial de citas, notas y evolución del trabajo realizado.'],
            ['Pautas y recomendaciones', 'Plantillas reutilizables y tareas adaptadas a cada objetivo.'],
            ['Informes', 'Borradores, informes finales y documentación preparada para entregar o revisar.'],
            ['Portal privado', 'Comparte documentos, pautas e informes cuando sea conveniente.'],
            ['Solo o en equipo', 'Adaptado a un solo especialista o a equipos de trabajo con especialidades diferentes.'],
        ],
    ],
    'osteopatia' => [
        'label' => 'Osteopatía',
        'hero' => 'Gestiona sesiones, objetivos funcionales, ejercicios y evolución.',
        'lead' => 'SimplyGest Praxis adapta ficha, agenda, documentación, mapa muscular, ejercicios e informes a centros de osteopatía y terapias manuales.',
        'title' => 'Gestión práctica para osteopatía',
        'intro' => 'Consulta historial, pautas, ejercicios, evolución y documentación sin perder el hilo de cada caso.',
        'features' => [
            ['Agenda de sesiones', 'Organiza citas y accede rápido a la sesión actual o próxima.'],
            ['Ficha funcional', 'Datos, motivo de consulta, antecedentes, evolución y documentación.'],
            ['Mapa muscular', 'Consulta zonas corporales y ejercicios o pautas relacionadas.'],
            ['Pautas de trabajo', 'Recomendaciones, ejercicios y plantillas reutilizables.'],
            ['Evolución', 'Notas, medidas y registros para revisar el progreso.'],
            ['Informes', 'Resumen de evolución y documentación preparada para uso profesional.'],
        ],
    ],
    'quiropractica' => [
        'label' => 'Quiropráctica',
        'hero' => 'Gestiona sesiones, seguimiento, pautas y documentación profesional.',
        'lead' => 'SimplyGest Praxis permite organizar agenda, ficha, evolución, ejercicios, mapa muscular, documentación e informes para quiropráctica.',
        'title' => 'Seguimiento claro para quiropráctica',
        'intro' => 'Un entorno de trabajo para registrar evolución, pautas, ejercicios y documentos de cada persona atendida.',
        'features' => [
            ['Agenda y sesiones', 'Calendario, próximas citas y acceso rápido al detalle de cada sesión.'],
            ['Ficha completa', 'Datos, historial, motivo de consulta, evolución y documentación.'],
            ['Mapa muscular', 'Selección visual de zonas y consulta de ejercicios relacionados.'],
            ['Pautas y ejercicios', 'Recomendaciones y plantillas que pueden añadirse al plan de trabajo.'],
            ['Informes', 'Borradores, versiones finales y control de disponibilidad.'],
            ['Portal privado', 'Comparte documentación o pautas cuando quieras dar acceso externo.'],
        ],
    ],
    'terapia_ocupacional' => [
        'label' => 'Terapia ocupacional',
        'hero' => 'Gestiona usuarios, objetivos, actividades, documentos e informes.',
        'lead' => 'SimplyGest Praxis ayuda a estructurar objetivos, seguimiento, actividades, pautas, documentación e informes en terapia ocupacional.',
        'title' => 'Organización para terapia ocupacional',
        'intro' => 'Agrupa objetivos, actividades, evolución y documentación en una ficha clara y reutilizable.',
        'features' => [
            ['Ficha del usuario', 'Datos, objetivos, evolución, actividades y documentación.'],
            ['Plan de trabajo', 'Pautas, tareas, actividades y plantillas reutilizables.'],
            ['Seguimiento', 'Notas de evolución, historial de sesiones y registros de progreso.'],
            ['Informes', 'Resumen estructurado para uso profesional o entrega externa.'],
            ['Documentación', 'Archivos, cuestionarios y documentos vinculados a cada caso.'],
            ['Portal privado', 'Acceso controlado a documentos e informes cuando sea necesario.'],
        ],
    ],
    'sexologia' => [
        'label' => 'Sexología',
        'hero' => 'Gestiona consultas, objetivos, pautas, documentación e informes.',
        'lead' => 'SimplyGest Praxis permite organizar agenda, ficha, objetivos, pautas, documentación e informes para profesionales de sexología.',
        'title' => 'Gestión discreta y estructurada para sexología',
        'intro' => 'Una ficha profesional para seguimiento, objetivos, documentación, pautas e informes con control de acceso.',
        'features' => [
            ['Agenda privada', 'Organiza sesiones, modalidades y recordatorios de forma clara.'],
            ['Ficha profesional', 'Datos, motivo de consulta, objetivos, evolución y documentos.'],
            ['Pautas y recursos', 'Recomendaciones y tareas reutilizables según objetivo.'],
            ['Informes', 'Borradores y documentos finales con control de disponibilidad.'],
            ['Portal privado', 'Comparte únicamente lo que el profesional decida publicar.'],
            ['Personalización', 'Textos, módulos y flujos adaptables al tipo de consulta.'],
        ],
    ],
    'coaching' => [
        'label' => 'Coaching',
        'hero' => 'Gestiona clientes, sesiones, objetivos, tareas y seguimiento.',
        'lead' => 'SimplyGest Praxis permite a coaches y consultores trabajar con objetivos, planes de acción, sesiones, documentación e informes.',
        'title' => 'Seguimiento ordenado para coaching y consultoría',
        'intro' => 'El foco está en objetivos, tareas, documentación, evolución y comunicación con cada cliente.',
        'features' => [
            ['Ficha del cliente', 'Datos, objetivos, sesiones, tareas y documentos en un mismo lugar.'],
            ['Plan de acción', 'Tareas, pautas y plantillas reutilizables para procesos habituales.'],
            ['Sesiones', 'Agenda, historial y notas de evolución para cada reunión.'],
            ['Informes', 'Resúmenes de progreso, documentos finales y control de entrega.'],
            ['Portal del cliente', 'Acceso privado a tareas, documentos e informes visibles.'],
            ['Marca propia', 'Logo, colores y página pública adaptada al servicio.'],
        ],
    ],
    'oposiciones' => [
        'label' => 'Preparación de oposiciones',
        'hero' => 'Gestiona alumnos, sesiones, objetivos, tareas y documentación.',
        'lead' => 'SimplyGest Praxis ayuda a preparadores y academias a organizar alumnos, planificación, tareas, documentación, evolución e informes.',
        'title' => 'Organización para preparación de oposiciones',
        'intro' => 'Planificación, tareas, documentación y seguimiento reunidos en una ficha clara por alumno.',
        'features' => [
            ['Ficha del alumno', 'Datos, objetivos, historial, tareas, documentos y evolución.'],
            ['Plan de trabajo', 'Tareas, pautas, plantillas y seguimiento de objetivos.'],
            ['Agenda', 'Sesiones, tutorías, próximas citas y recordatorios.'],
            ['Documentación', 'Materiales, archivos, cuestionarios o recursos asociados.'],
            ['Informes', 'Resúmenes de progreso y documentación preparada para revisión.'],
            ['Portal privado', 'Acceso a tareas, documentos e informes cuando esté habilitado.'],
        ],
    ],
    'general' => [
        'label' => 'Otros sectores',
        'hero' => 'Gestiona clientes, citas, documentos, informes y seguimiento profesional.',
        'lead' => 'SimplyGest Praxis se adapta a servicios profesionales que necesitan agenda, ficha de cliente, documentación, tareas, informes y una experiencia privada para el cliente.',
        'title' => 'Una base flexible para servicios profesionales',
        'intro' => 'El vocabulario, los módulos visibles y el flujo de trabajo pueden ajustarse al sector y al tipo de servicio.',
        'features' => [
            ['Agenda flexible', 'Calendario, próximas citas, sesiones en curso y vistas de pacientes o citas según la forma de trabajar.'],
            ['Ficha completa', 'Datos, historial, documentos, tareas, informes y seguimiento en un único lugar.'],
            ['Plantillas reutilizables', 'Tareas, pautas o rutinas que el profesional usa habitualmente con sus clientes.'],
            ['Informes y archivos', 'Borradores, documentos finales, costes, pagos y disponibilidad en portal.'],
            ['Portal del cliente', 'Acceso privado a citas, tareas visibles, documentos e informes cuando esté habilitado.'],
            ['Marca propia', 'Logo, colores, textos, landing pública y módulos adaptados a cada centro.'],
        ],
    ],
];

$default_profile = $sector_profiles['psicologia'];
$visible_sector_keys = [
    'psicologia',
    'psicopedagogia',
    'sexologia',
    'logopedia',
    'terapia_ocupacional',
    'fisioterapia',
    'fitness',
    'nutricion',
    'osteopatia',
    'quiropractica',
    'general',
];

function praxis_detail_groups($person_plural, $professional_plural, $knowledge_label, $options = [])
{
    $include_composition = !empty($options['composition']);
    $include_exercises = !empty($options['exercises']);

    $groups = [
        'Agenda y reservas' => [
            'Calendario mensual y semanal para organizar citas, sesiones y disponibilidad.',
            'Vistas de agenda, ' . $person_plural . ' y próximas citas para trabajar según la rutina de cada centro.',
            'Recordatorios de próxima cita y sesión en curso con acciones rápidas.',
            'Modalidad presencial u online, enlace de videollamada, emails e invitación de calendario.',
            'Reservas online opcionales y portal privado cuando el centro quiera ofrecerlo.',
            'Recordatorios de citas por email y sincronización con Google Calendar o iCloud Calendar.',
        ],
        ucfirst($person_plural) . ' y seguimiento' => [
            'Ficha adaptada a cada caso según el sector.',
            'Plan de trabajo con tareas, pautas, ejercicios o actividades y plantillas reutilizables.',
            'Evolución con notas, registros y gráficos para datos relevantes del seguimiento.',
        ],
        'Documentación e informes' => [
            'Documentos, cuestionarios y archivos en una sola gestión con filtros.',
            'Disponibilidad individual en portal privado y control de nota/resultado visible.',
            'Informes generados por la app y plantillas propias subidas por el especialista.',
            'Coste gratuito, incluido o de pago, con estado pendiente/pagado.',
            'Exportación e impresión de listados respetando filtros y orden actual.',
        ],
        'Base de conocimiento' => [
            $knowledge_label . ' según el sector.',
            'Importación de tareas, pautas o recomendaciones de forma global, por técnica o una a una.',
        ],
        'Gestión del centro' => [
            'Personaliza tu app y el portal con tu logo y color preferido.',
            'Equipo de ' . $professional_plural . ' configurable, asignación de ' . $person_plural . ' y permisos.',
            'Bonos, pagos online, invitaciones con QR y recordatorios.',
        ],
    ];

    if ($include_composition) {
        $groups[ucfirst($person_plural) . ' y seguimiento'][] = 'Composición corporal, medidas, pliegues, IMC, grasa corporal y gráficos de evolución.';
    }

    if ($include_exercises) {
        $groups['Base de conocimiento'][] = 'Mapas musculares interactivos para consultar ejercicios y rutinas por zona corporal.';
        $groups['Base de conocimiento'][] = 'Ejercicios con detalle, instrucciones, recomendaciones y GIF cuando están disponibles.';
    }

    return $groups;
}

$detail_profiles = [
    'psicologia' => praxis_detail_groups('pacientes', 'terapeutas', 'Diagnósticos, técnicas, tareas, pautas, cuestionarios y fuentes'),
    'fisioterapia' => praxis_detail_groups('pacientes', 'fisioterapeutas', 'Problemas funcionales, técnicas, ejercicios, pautas, documentos y fuentes', ['composition' => true, 'exercises' => true]),
    'fitness' => praxis_detail_groups('clientes', 'entrenadores', 'Objetivos, rutinas, ejercicios, pautas y recomendaciones', ['composition' => true, 'exercises' => true]),
    'nutricion' => praxis_detail_groups('clientes', 'especialistas', 'Objetivos, pautas, documentos, cuestionarios y fuentes', ['composition' => true]),
    'logopedia' => praxis_detail_groups('usuarios', 'logopedas', 'Objetivos, técnicas, pautas, documentos, cuestionarios y fuentes'),
    'psicopedagogia' => praxis_detail_groups('alumnos', 'especialistas', 'Objetivos, pautas, documentos, cuestionarios y fuentes'),
    'osteopatia' => praxis_detail_groups('pacientes', 'especialistas', 'Objetivos funcionales, técnicas, ejercicios, pautas y fuentes', ['composition' => true, 'exercises' => true]),
    'quiropractica' => praxis_detail_groups('pacientes', 'especialistas', 'Objetivos funcionales, técnicas, ejercicios, pautas y fuentes', ['composition' => true, 'exercises' => true]),
    'terapia_ocupacional' => praxis_detail_groups('usuarios', 'especialistas', 'Objetivos, actividades, pautas, documentos y fuentes'),
    'sexologia' => praxis_detail_groups('pacientes', 'especialistas', 'Objetivos, pautas, documentos, cuestionarios y fuentes'),
    'coaching' => praxis_detail_groups('clientes', 'especialistas', 'Objetivos, tareas, pautas, documentos y fuentes'),
    'oposiciones' => praxis_detail_groups('alumnos', 'preparadores', 'Objetivos, técnicas de estudio, tareas, documentos y fuentes'),
    'general' => praxis_detail_groups('personas atendidas', 'especialistas', 'Objetivos, tareas, pautas, documentos y fuentes'),
];

$default_detail_groups = $detail_profiles['psicologia'];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SimplyGest Praxis es una plataforma multisectorial para gestionar agenda, seguimiento, documentación, informes, portal privado y bases de conocimiento profesionales.">
    <title>SimplyGest Praxis | Software multisectorial para servicios profesionales</title>
    <link rel="icon" href="<?= htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sg-primary: #4285f4;
            --sg-primary-dark: #0b57d0;
            --sg-primary-soft: #e8f1ff;
            --sg-ink: #152033;
            --sg-muted: #627184;
            --sg-border: #dbe6f5;
            --sg-surface: #ffffff;
            --sg-page: #f4f8ff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--sg-ink);
            background:
                radial-gradient(circle at 10% 0%, rgba(66, 133, 244, .16), transparent 26rem),
                radial-gradient(circle at 85% 10%, rgba(6, 182, 212, .14), transparent 24rem),
                var(--sg-page);
        }

        a {
            color: inherit;
        }

        .sg-nav {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(255, 255, 255, .86);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(219, 230, 245, .9);
        }

        .sg-brand-logo {
            height: 42px;
            width: auto;
            display: block;
        }

        .sg-nav-link {
            color: #4c5b70;
            font-weight: 700;
            text-decoration: none;
            font-size: .95rem;
        }

        .sg-nav-link:hover {
            color: var(--sg-primary-dark);
        }

        .sg-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .46rem .82rem;
            border-radius: 999px;
            background: rgba(66, 133, 244, .12);
            color: var(--sg-primary-dark);
            font-weight: 800;
            font-size: .86rem;
        }

        .sg-hero {
            padding: 5.4rem 0 3.6rem;
        }

        .sg-hero h2 {
            margin: 1.1rem 0 1rem;
            font-size: clamp(2rem, 4vw, 3.5rem);
            line-height: 1.08;
            letter-spacing: 0;
            font-weight: 800;
        }

        .sg-hero-lead {
            color: var(--sg-muted);
            font-size: clamp(1.05rem, 2vw, 1.28rem);
            line-height: 1.65;
        }

        .sg-universal-card {
            border-radius: 28px;
            padding: 1.65rem;
            border: 1px solid rgba(219, 230, 245, .9);
            background: rgba(255, 255, 255, .78);
            box-shadow: 0 20px 60px rgba(31, 70, 121, .1);
        }

        .sg-hero-panel {
            min-height: 460px;
            border-radius: 28px;
            padding: .75rem;
            border: 1px solid rgba(255, 255, 255, .68);
            background:
                linear-gradient(135deg, rgba(255, 255, 255, .78), rgba(232, 241, 255, .72)),
                radial-gradient(circle at 25% 20%, rgba(66, 133, 244, .24), transparent 16rem);
            box-shadow: 0 24px 80px rgba(20, 68, 136, .15);
            overflow: hidden;
        }

        .sg-hero-image {
            width: 100%;
            height: 100%;
            min-height: 445px;
            display: block;
            object-fit: cover;
            border-radius: 22px;
            border: 1px solid rgba(219, 230, 245, .85);
        }

        .sg-dashboard-mock {
            height: 100%;
            min-height: 430px;
            border-radius: 20px;
            background: #fff;
            border: 1px solid var(--sg-border);
            box-shadow: 0 18px 50px rgba(41, 72, 115, .12);
            display: grid;
            grid-template-columns: 180px 1fr;
            overflow: hidden;
        }

        .sg-mock-side {
            padding: 1.2rem;
            background: linear-gradient(180deg, #eef5ff, #f8fbff);
            border-right: 1px solid var(--sg-border);
        }

        .sg-mock-side img {
            width: 64px;
            display: block;
            margin-bottom: 1.4rem;
        }

        .sg-mock-menu {
            display: grid;
            gap: .7rem;
        }

        .sg-mock-menu span {
            height: 30px;
            border-radius: 9px;
            background: #dceaff;
        }

        .sg-mock-menu span:first-child {
            background: var(--sg-primary);
        }

        .sg-mock-main {
            padding: 1.4rem;
            display: grid;
            gap: 1rem;
            align-content: start;
        }

        .sg-mock-card {
            border-radius: 16px;
            background: #f7fbff;
            border: 1px solid var(--sg-border);
            padding: 1rem;
        }

        .sg-mock-card strong {
            display: block;
            margin-bottom: .45rem;
        }

        .sg-mock-lines {
            display: grid;
            gap: .48rem;
        }

        .sg-mock-lines i {
            display: block;
            height: 9px;
            border-radius: 999px;
            background: #d5e4f8;
        }

        .sg-mock-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .8rem;
        }

        .sg-mock-tile {
            aspect-ratio: 1;
            border-radius: 14px;
            background: linear-gradient(135deg, #ddecff, #ffffff);
            border: 1px solid var(--sg-border);
        }

        .sg-section {
            padding: 3.6rem 0;
        }

        .sg-section-title {
            max-width: 780px;
            margin: 0 auto 2rem;
            text-align: center;
        }

        .sg-section-title h2 {
            font-size: clamp(1.85rem, 3vw, 3rem);
            font-weight: 800;
            margin-bottom: .7rem;
        }

        .sg-section-title p {
            color: var(--sg-muted);
            font-size: 1.08rem;
            line-height: 1.65;
            margin: 0;
        }

        .sg-sector-switch {
            border-radius: 26px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, .32);
            background:
                linear-gradient(135deg, #0b57d0, #4285f4 55%, #02b7d8);
            box-shadow: 0 24px 80px rgba(18, 89, 203, .24);
            color: #fff;
        }

        .sg-sector-switch h2 {
            font-size: clamp(1.45rem, 2.2vw, 2.1rem);
            font-weight: 800;
            margin: 0 0 .45rem;
        }

        .sg-sector-switch p {
            color: rgba(255, 255, 255, .84);
            line-height: 1.62;
            margin: 0;
        }

        .sg-sector-option {
            border: 1px solid rgba(255, 255, 255, .34);
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            padding: .62rem .95rem;
            font-weight: 800;
            transition: all .16s ease;
            white-space: nowrap;
        }

        .sg-sector-option:hover,
        .sg-sector-option.is-active {
            border-color: #fff;
            background: #fff;
            color: var(--sg-primary-dark);
            box-shadow: 0 10px 24px rgba(15, 62, 130, .2);
        }

        .sg-feature-card {
            height: 100%;
            border: 1px solid var(--sg-border);
            background: rgba(255, 255, 255, .88);
            border-radius: 18px;
            padding: 1.35rem;
            box-shadow: 0 18px 45px rgba(31, 70, 121, .08);
        }

        .sg-feature-icon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            color: #fff;
            background: linear-gradient(135deg, var(--sg-primary), #00b8d9);
            margin-bottom: 1rem;
            font-weight: 800;
        }

        .sg-feature-card h3 {
            font-size: 1.08rem;
            font-weight: 800;
            margin-bottom: .55rem;
        }

        .sg-feature-card p {
            margin: 0;
            color: var(--sg-muted);
            line-height: 1.58;
        }

        .sg-sector-image-card {
            border-radius: 24px;
            padding: .65rem;
            background: rgba(255, 255, 255, .82);
            border: 1px solid rgba(219, 230, 245, .9);
            box-shadow: 0 18px 48px rgba(31, 70, 121, .08);
        }

        .sg-sector-image {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 18px;
        }

        .sg-sector-band {
            border-radius: 28px;
            background:
                linear-gradient(135deg, #0b57d0, #4285f4 55%, #02b7d8);
            color: #fff;
            padding: 2rem;
            box-shadow: 0 24px 80px rgba(18, 89, 203, .24);
        }

        .sg-sector-band h2 {
            font-weight: 800;
            margin-bottom: .4rem;
        }

        .sg-sector-band p {
            color: rgba(255, 255, 255, .84);
            margin-bottom: 1.3rem;
        }

        .sg-sector-chip {
            display: inline-flex;
            align-items: center;
            padding: .58rem .85rem;
            margin: .28rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .24);
            color: #fff;
            font-weight: 700;
        }

        .sg-visual-card {
            height: 100%;
            min-height: 300px;
            border-radius: 24px;
            background:
                linear-gradient(145deg, rgba(255,255,255,.82), rgba(234,244,255,.84)),
                radial-gradient(circle at 72% 22%, rgba(66,133,244,.22), transparent 9rem);
            border: 1px solid rgba(219, 230, 245, .9);
            padding: 1.4rem;
            position: relative;
            overflow: hidden;
        }

        .sg-visual-card:before,
        .sg-visual-card:after {
            content: "";
            position: absolute;
            border-radius: 999px;
            background: rgba(66, 133, 244, .16);
        }

        .sg-visual-card:before {
            width: 180px;
            height: 180px;
            right: -40px;
            top: -50px;
        }

        .sg-visual-card:after {
            width: 120px;
            height: 120px;
            left: -35px;
            bottom: -35px;
            background: rgba(0, 184, 217, .16);
        }

        .sg-visual-stack {
            position: relative;
            z-index: 1;
            display: grid;
            gap: .9rem;
        }

        .sg-visual-row {
            display: flex;
            align-items: center;
            gap: .8rem;
            padding: .9rem;
            border-radius: 16px;
            background: rgba(255,255,255,.78);
            border: 1px solid rgba(219, 230, 245, .9);
        }

        .sg-visual-badge {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            background: var(--sg-primary);
            flex: 0 0 auto;
            font-weight: 800;
        }

        .sg-showcase-grid {
            display: grid;
            gap: 1.2rem;
        }

        .sg-showcase-card {
            display: grid;
            grid-template-columns: minmax(0, 1.14fr) minmax(260px, .86fr);
            gap: 1.4rem;
            align-items: center;
            border: 1px solid rgba(219, 230, 245, .95);
            border-radius: 26px;
            background:
                linear-gradient(135deg, rgba(255,255,255,.94), rgba(247,251,255,.86)),
                radial-gradient(circle at 100% 0%, rgba(66,133,244,.13), transparent 14rem);
            box-shadow: 0 22px 60px rgba(31, 70, 121, .09);
            padding: 1rem;
            overflow: hidden;
        }

        .sg-showcase-card:nth-child(even) {
            grid-template-columns: minmax(260px, .86fr) minmax(0, 1.14fr);
        }

        .sg-showcase-card:nth-child(even) .sg-showcase-media {
            order: 2;
        }

        .sg-showcase-media {
            border-radius: 20px;
            padding: .5rem;
            background: #fff;
            border: 1px solid rgba(219, 230, 245, .9);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.8);
        }

        .sg-showcase-media img {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            border-radius: 16px;
        }

        .sg-showcase-copy {
            padding: 1.1rem clamp(1rem, 2vw, 1.8rem);
        }

        .sg-showcase-copy h3 {
            font-size: clamp(1.35rem, 2.2vw, 2rem);
            font-weight: 850;
            margin-bottom: .7rem;
        }

        .sg-showcase-copy p {
            color: var(--sg-muted);
            font-size: 1.03rem;
            line-height: 1.66;
            margin: 0;
        }

        .sg-detail-table {
            overflow: hidden;
            border-radius: 20px;
            border: 1px solid var(--sg-border);
            background: #fff;
            box-shadow: 0 18px 48px rgba(31, 70, 121, .08);
        }

        .sg-detail-table table {
            margin: 0;
        }

        .sg-detail-table th {
            width: 240px;
            color: var(--sg-primary-dark);
            background: #f4f8ff;
            font-weight: 800;
        }

        .sg-detail-table td,
        .sg-detail-table th {
            padding: 1rem 1.1rem;
            vertical-align: top;
        }

        .sg-detail-table li {
            margin-bottom: .34rem;
            color: #536174;
        }

        .sg-footer {
            padding: 2rem 0;
            border-top: 1px solid var(--sg-border);
            color: #66768a;
            background: rgba(255,255,255,.72);
        }

        .sg-footer img {
            height: 18px;
            width: auto;
            margin-right: .45rem;
        }

        @media (max-width: 991.98px) {
            .sg-hero {
                padding-top: 3.6rem;
            }

            .sg-dashboard-mock {
                grid-template-columns: 1fr;
            }

            .sg-mock-side {
                display: none;
            }

            .sg-showcase-card,
            .sg-showcase-card:nth-child(even) {
                grid-template-columns: 1fr;
            }

            .sg-showcase-card:nth-child(even) .sg-showcase-media {
                order: 0;
            }
        }

        @media (max-width: 575.98px) {
            .sg-nav .container {
                gap: 1rem;
            }

            .sg-brand-logo {
                height: 36px;
            }

            .sg-detail-table th {
                width: auto;
            }
        }
    </style>
</head>

<body>
    <nav class="sg-nav py-3">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="./" class="d-inline-flex align-items-center text-decoration-none">
                <img src="<?= htmlspecialchars($full_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="sg-brand-logo">
            </a>
            <div class="d-none d-md-flex align-items-center gap-4">
                <a class="sg-nav-link" href="#caracteristicas">Características</a>
                <a class="sg-nav-link" href="#sectores">Sectores</a>
                <a class="sg-nav-link" href="#detalle">Detalle</a>
                <a class="sg-nav-link" href="app-plans.php">Planes</a>
            </div>
        </div>
    </nav>

    <main>
        <section class="sg-hero">
            <div class="container">
                <div class="row align-items-center g-4">
                    <div class="col-12">
                        <div class="sg-universal-card">
                            <span class="sg-pill">Software multisectorial para servicios profesionales</span>
                            <h2>Una plataforma flexible para organizar agenda, seguimiento, documentación e informes.</h2>
                            <p class="sg-hero-lead">
                                SimplyGest Praxis reúne en un solo entorno la gestión diaria de centros y profesionales:
                                calendario, fichas, documentos, tareas, informes, evolución, portal privado y módulos
                                adaptables a cada actividad.
                            </p>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="sg-hero-panel">
                            <?php if ($hero_image_url !== ''): ?>
                                <img class="sg-hero-image" src="<?= htmlspecialchars($hero_image_url, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis para servicios profesionales">
                            <?php else: ?>
                                <div class="sg-dashboard-mock" aria-hidden="true">
                                    <aside class="sg-mock-side">
                                        <img src="<?= htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                        <div class="sg-mock-menu">
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                        </div>
                                    </aside>
                                    <div class="sg-mock-main">
                                        <div class="sg-mock-card">
                                            <strong>Próxima cita</strong>
                                            <div class="sg-mock-lines">
                                                <i style="width:72%"></i>
                                                <i style="width:52%"></i>
                                            </div>
                                        </div>
                                        <div class="sg-mock-grid">
                                            <div class="sg-mock-tile"></div>
                                            <div class="sg-mock-tile"></div>
                                            <div class="sg-mock-tile"></div>
                                        </div>
                                        <div class="sg-mock-card">
                                            <strong>Base de conocimiento</strong>
                                            <div class="sg-mock-lines">
                                                <i style="width:86%"></i>
                                                <i style="width:64%"></i>
                                                <i style="width:76%"></i>
                                            </div>
                                        </div>
                                        <div class="sg-mock-card">
                                            <strong>Informes y evolución</strong>
                                            <div class="sg-mock-lines">
                                                <i style="width:92%"></i>
                                                <i style="width:58%"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sg-section pb-0" id="sectores">
            <div class="container">
                <div class="sg-sector-switch">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-4">
                            <h2>Multisector desde la base</h2>
                            <p>Selecciona un sector para mostrar las características detalladas y adaptadas a cada uno.</p>
                        </div>
                        <div class="col-lg-8">
                            <div class="d-flex flex-wrap gap-2 justify-content-lg-end" role="group" aria-label="Selector de sector">
                                <?php foreach ($visible_sector_keys as $key): ?>
                                    <?php $profile = $sector_profiles[$key]; ?>
                                    <button type="button" class="sg-sector-option<?= $key === 'psicologia' ? ' is-active' : '' ?>" data-sector="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($profile['label'], ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sg-section" id="caracteristicas">
            <div class="container">
                <div class="row align-items-center g-4 mb-4">
                    <div class="col-lg-6">
                        <div class="sg-sector-image-card">
                            <img
                                class="sg-sector-image"
                                id="sgSectorImage"
                                src="<?= htmlspecialchars($sector_image_urls['psicologia'] ?: $hero_image_url, ENT_QUOTES, 'UTF-8') ?>"
                                alt="SimplyGest Praxis para el sector seleccionado"
                            >
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="sg-section-title text-lg-start mx-0 mb-0">
                            <h2 id="sgSectorTitle"><?= htmlspecialchars($default_profile['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p id="sgSectorIntro"><?= htmlspecialchars($default_profile['intro'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
                <div class="row g-4">
                    <?php foreach ($default_profile['features'] as $index => $feature): ?>
                        <div class="col-md-6 col-xl-4">
                            <article class="sg-feature-card" data-feature-card="<?= $index ?>">
                                <div class="sg-feature-icon"><?= $index + 1 ?></div>
                                <h3 data-feature-title><?= htmlspecialchars($feature[0], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p data-feature-text><?= htmlspecialchars($feature[1], ENT_QUOTES, 'UTF-8') ?></p>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="sg-section">
            <div class="container">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="sg-visual-card">
                            <div class="sg-visual-stack">
                                <div class="sg-visual-row">
                                    <span class="sg-visual-badge">01</span>
                                    <div>
                                        <strong>Centros y profesionales</strong>
                                        <div class="text-muted small">Agenda, equipo, portal e informes.</div>
                                    </div>
                                </div>
                                <div class="sg-visual-row">
                                    <span class="sg-visual-badge">02</span>
                                    <div>
                                        <strong>Salud y bienestar</strong>
                                        <div class="text-muted small">Evolución, medidas, documentación y pautas.</div>
                                    </div>
                                </div>
                                <div class="sg-visual-row">
                                    <span class="sg-visual-badge">03</span>
                                    <div>
                                        <strong>Adaptación por sector</strong>
                                        <div class="text-muted small">Textos, módulos y flujos ajustados a cada actividad.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="sg-section-title text-lg-start mx-0">
                            <h2>Diferencias que se notan</h2>
                            <p>
                                SimplyGest Praxis une áreas que normalmente viven separadas: agenda, portal privado,
                                informes, documentación, seguimiento, conocimiento de apoyo y personalización por sector.
                                El resultado es una herramienta que sirve tanto para profesionales independientes como para centros con equipo.
                            </p>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="sg-feature-card">
                                    <h3>Menos cambios de herramienta</h3>
                                    <p>El profesional puede preparar una sesión, añadir tareas o pautas, revisar archivos y generar informes sin saltar entre aplicaciones.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="sg-feature-card">
                                    <h3>Más adaptable que una agenda clásica</h3>
                                    <p>Puede funcionar como calendario de reservas, gestor de personas atendidas, gestor documental, portal privado y soporte de conocimiento técnico.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sg-section pt-0" id="capturas">
            <div class="container">
                <div class="sg-section-title">
                    <h2>También entra por los ojos</h2>
                    <p>Una interfaz pensada para trabajar a diario: visual, personalizable y adaptada a tu sector.</p>
                </div>
                <div class="sg-showcase-grid">
                    <?php foreach ($showcase_cards as $showcase_index => $showcase_card): ?>
                        <article class="sg-showcase-card">
                            <div class="sg-showcase-media">
                                <img src="<?= htmlspecialchars($showcase_card['image_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($showcase_card['title'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="sg-showcase-copy">
                                <h3<?= $showcase_index === 0 ? ' id="sgAgendaShowcaseTitle"' : '' ?>><?= htmlspecialchars($showcase_card['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p<?= $showcase_index === 0 ? ' id="sgAgendaShowcaseText"' : '' ?>><?= htmlspecialchars($showcase_card['text'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <article class="sg-showcase-card" id="sgSectorShowcaseCard">
                        <div class="sg-showcase-media">
                            <img
                                id="sgSectorShowcaseImage"
                                src="<?= htmlspecialchars($default_showcase_focus['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                                alt="<?= htmlspecialchars($default_showcase_focus['title'], ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                        <div class="sg-showcase-copy">
                            <h3 id="sgSectorShowcaseTitle"><?= htmlspecialchars($default_showcase_focus['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p id="sgSectorShowcaseText"><?= htmlspecialchars($default_showcase_focus['text'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="sg-section" id="detalle">
            <div class="container">
                <div class="sg-section-title">
                    <h2>Características detalladas</h2>
                    <p>Resumen agrupado de los módulos principales que puede cubrir la plataforma.</p>
                </div>
                <div class="sg-detail-table table-responsive">
                    <table class="table table-hover align-middle">
                        <tbody id="sgDetailTableBody">
                            <?php foreach ($default_detail_groups as $group => $items): ?>
                                <tr>
                                    <th><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></th>
                                    <td>
                                        <ul class="mb-0">
                                            <?php foreach ($items as $item): ?>
                                                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8') ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <footer class="sg-footer">
        <div class="container d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">
            <span class="d-inline-flex align-items-center">
                <img src="<?= htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
                SimplyGest Praxis - <?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span>Software multisectorial para gestión profesional.</span>
        </div>
    </footer>
    <script>
        const praxisSectorProfiles = <?= json_encode($sector_profiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const praxisDetailProfiles = <?= json_encode($detail_profiles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const praxisSectorImages = <?= json_encode($sector_image_urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const praxisShowcaseFocus = <?= json_encode($showcase_focus_cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const praxisShowcaseAudienceTerms = <?= json_encode($showcase_audience_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const praxisFallbackSectorImage = <?= json_encode($hero_image_url, JSON_UNESCAPED_SLASHES) ?>;
        const sectorTitle = document.getElementById('sgSectorTitle');
        const sectorIntro = document.getElementById('sgSectorIntro');
        const sectorImage = document.getElementById('sgSectorImage');
        const agendaShowcaseTitle = document.getElementById('sgAgendaShowcaseTitle');
        const agendaShowcaseText = document.getElementById('sgAgendaShowcaseText');
        const sectorShowcaseImage = document.getElementById('sgSectorShowcaseImage');
        const sectorShowcaseTitle = document.getElementById('sgSectorShowcaseTitle');
        const sectorShowcaseText = document.getElementById('sgSectorShowcaseText');
        const detailTableBody = document.getElementById('sgDetailTableBody');
        const featureCards = Array.from(document.querySelectorAll('[data-feature-card]'));
        const sectorButtons = Array.from(document.querySelectorAll('[data-sector]'));

        function escapePraxisHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        function renderPraxisDetails(key) {
            const groups = praxisDetailProfiles[key] || praxisDetailProfiles.psicologia;
            detailTableBody.innerHTML = Object.entries(groups).map(([group, items]) => `
                <tr>
                    <th>${escapePraxisHtml(group)}</th>
                    <td>
                        <ul class="mb-0">
                            ${items.map((item) => `<li>${escapePraxisHtml(item)}</li>`).join('')}
                        </ul>
                    </td>
                </tr>
            `).join('');
        }

        function setPraxisSector(key) {
            const profile = praxisSectorProfiles[key] || praxisSectorProfiles.psicologia;
            sectorTitle.textContent = profile.title;
            sectorIntro.textContent = profile.intro;
            sectorImage.src = praxisSectorImages[key] || praxisFallbackSectorImage;
            sectorImage.alt = `SimplyGest Praxis para ${profile.label || 'el sector seleccionado'}`;
            featureCards.forEach((card, index) => {
                const feature = profile.features[index] || ['', ''];
                card.querySelector('[data-feature-title]').textContent = feature[0];
                card.querySelector('[data-feature-text]').textContent = feature[1];
            });
            sectorButtons.forEach((button) => {
                button.classList.toggle('is-active', button.dataset.sector === key);
            });
            const showcase = praxisShowcaseFocus[key] || praxisShowcaseFocus.default;
            if (showcase && sectorShowcaseImage && sectorShowcaseTitle && sectorShowcaseText) {
                sectorShowcaseImage.src = showcase.image_url;
                sectorShowcaseImage.alt = showcase.title;
                sectorShowcaseTitle.textContent = showcase.title;
                sectorShowcaseText.textContent = showcase.text;
            }
            const audienceTerms = praxisShowcaseAudienceTerms[key] || praxisShowcaseAudienceTerms.default;
            if (audienceTerms && agendaShowcaseTitle && agendaShowcaseText) {
                agendaShowcaseTitle.textContent = `Agenda, reservas y Portal de ${audienceTerms.title}`;
                agendaShowcaseText.textContent = `Una agenda personalizable para gestionar citas, reservas online, recordatorios y acceso privado para tus ${audienceTerms.text}`;
            }
            renderPraxisDetails(key);
        }

        sectorButtons.forEach((button) => {
            button.addEventListener('click', () => setPraxisSector(button.dataset.sector));
        });
    </script>
</body>

</html>
