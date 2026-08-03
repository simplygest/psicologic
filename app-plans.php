<?php
header('Content-Type: text/html; charset=UTF-8');

$app_name = 'SimplyGest Praxis';
$brand_logo_path = 'uploads/global/sgpraxis-completo-1-transparente.png';
$brand_icon_path = 'uploads/global/sgpraxis-logo transparente.png';
$year = date('Y');

function praxis_plans_asset_data_uri($relative_path)
{
    $local_path = __DIR__ . '/' . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
    if (!is_file($local_path)) {
        return '';
    }

    $extension = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
            $mime = 'image/jpeg';
            break;
        case 'png':
            $mime = 'image/png';
            break;
        case 'webp':
            $mime = 'image/webp';
            break;
        case 'gif':
            $mime = 'image/gif';
            break;
        default:
            $mime = 'application/octet-stream';
            break;
    }

    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local_path));
}

function praxis_plans_read_config($plan_key)
{
    $path = __DIR__ . '/uploads/global/plan-config/' . $plan_key . '.json';
    if (!is_file($path)) {
        return [
            'key' => $plan_key,
            'label' => ucfirst($plan_key),
            'features' => [],
            'limits' => [],
        ];
    }

    $json = json_decode(file_get_contents($path), true);
    $plan = is_array($json) ? ($json['plan'] ?? []) : [];
    return [
        'key' => $plan['key'] ?? $plan_key,
        'label' => $plan['label'] ?? ucfirst($plan_key),
        'features' => is_array($plan['features'] ?? null) ? $plan['features'] : [],
        'limits' => is_array($plan['limits'] ?? null) ? $plan['limits'] : [],
    ];
}

function praxis_plans_feature_enabled($plan, $feature)
{
    $features = $plan['features'] ?? [];
    if (is_array($feature)) {
        foreach ($feature as $candidate) {
            if (!empty($features[$candidate])) {
                return true;
            }
        }
        return false;
    }

    return !empty($features[$feature]);
}

function praxis_plans_limit_value($plan, $limit, $default = null)
{
    return array_key_exists($limit, $plan['limits'] ?? []) ? $plan['limits'][$limit] : $default;
}

function praxis_plans_team_limit_label($plan)
{
    $limit = praxis_plans_limit_value($plan, 'teamMembers', null);
    if ($limit === null || $limit === '' || (int) $limit === 0) {
        return 'Sin limite';
    }
    $limit = max(1, (int) $limit);
    if ($limit === 1) {
        return 'Solo superadmin';
    }
    return 'Hasta ' . $limit . ' miembros';
    if ($limit === null || $limit === '') {
        return 'Sin límite';
    }
    $limit = max(0, (int) $limit);
    if ($limit === 0) {
        return 'Solo superadmin';
    }
    return 'Hasta ' . $limit . ' miembro' . ($limit === 1 ? '' : 's');
}

function praxis_plans_team_limit_badge_value($plan)
{
    $limit = praxis_plans_limit_value($plan, 'teamMembers', null);
    if ($limit === null || $limit === '' || (int) $limit === 0) {
        return null;
    }
    return max(1, (int) $limit);
    if ($limit === null || $limit === '') {
        return null;
    }
    return max(0, (int) $limit) + 1;
}

$brand_logo_url = praxis_plans_asset_data_uri($brand_logo_path);
$brand_icon_url = praxis_plans_asset_data_uri($brand_icon_path);
$plans = [
    'initium' => praxis_plans_read_config('initium'),
    'novus' => praxis_plans_read_config('novus'),
    'magister' => praxis_plans_read_config('magister'),
    'summum' => praxis_plans_read_config('summum'),
];

$common_features = [
    'Agenda visual con vistas mensual y semanal.',
    'Reserva y gestión interna de citas.',
    'Ficha completa de pacientes, clientes o usuarios.',
    'Historial de citas.',
    'Dashboard profesional adaptable a cada sector.',
    'Configuración básica de servicios, horarios y disponibilidad.',
];

$plan_summaries = [
    'initium' => [
        'tagline' => 'La base de Novus, gratis para siempre, con hasta 50 pacientes y 20 citas por semana.',
        'tone' => 'Gratuito',
        'price' => 'Gratis',
    ],
    'novus' => [
        'tagline' => 'Para empezar con una agenda profesional sencilla.',
        'tone' => 'Base operativa',
        'price' => '9,90 € / mes',
    ],
    'magister' => [
        'tagline' => 'Para centros que necesitan crecer con módulos avanzados.',
        'tone' => 'Crecimiento',
        'price' => '29,90 € / mes',
    ],
    'summum' => [
        'tagline' => 'La experiencia completa, con conocimiento, portal y personalización.',
        'tone' => 'Completo',
        'price' => '59,90 € / mes',
    ],
];

$plan_summaries['novus']['tagline'] = 'Para profesionales que necesitan agenda, pacientes y seguimiento básico sin portal ni módulos avanzados.';
$plan_summaries['magister']['tagline'] = 'Para centros que necesitan portal, equipo, bonos, conocimiento sectorial y sincronización, sin pagos online ni facturación.';
$plan_summaries['summum']['tagline'] = 'La experiencia completa: pagos online, facturación, cuestionarios personalizados, videollamada integrada, base multisectorial y personalización avanzada.';

$feature_labels = [
    'patientPortal.enabled' => 'Portal privado para pacientes o clientes',
    'patientPortal.invitations' => 'Invitaciones de registro al portal',
    'onlineBooking.enabled' => 'Reservas online desde el portal',
    'tasks.enabled' => 'Tareas, pautas o ejercicios',
    'closures.enabled' => 'Vacaciones, cierres y bloqueos de agenda',
    'bonuses.enabled' => 'Bonos y sesiones prepagadas',
    'billing.enabled' => 'Facturación integrada',
    'reports.globalReports' => 'Estadísticas, listados e informes globales',
    'upcomingAppointments.planning' => 'Planning de próximas citas',
    'appointments.effectiveDuration' => 'Duración efectiva visible para el paciente',
    'taskTemplates.enabled' => 'Plantillas reutilizables de tareas',
    'onlinePayments.enabled' => 'Compatibilidad con pago online',
    'payments.online' => 'Cobro online por Redsys',
    'knowledgeBase.enabled' => 'Base de conocimiento por sector',
    'knowledgeBase.importTasks' => 'Importación de recomendaciones al plan de trabajo',
    'knowledgeBase.multiSector' => 'Consulta de bases de conocimiento relacionadas',
    'questionnaires.enabled' => 'Constructor de cuestionarios personalizados',
    'documents.uploads' => 'Subida de archivos y adjuntos',
    'documents.onlineEditor' => 'Editor online de documentos',
    'documents.drawingBoard' => 'Pizarra online de dibujo',
    'digitalSignature.tenant' => 'Firma de documentos con certificado del centro',
    'digitalSignature.professional' => 'Firma de documentos con certificado del profesional',
    'legalConsents.templates' => 'Plantillas de consentimientos personalizables',
    'legalConsents.generatedPdf' => 'Consentimientos PDF autorrellenados',
    'legalConsents.handwrittenSignature' => 'Firma manuscrita presencial de consentimientos',
    'legalConsents.serviceMapping' => 'Consentimientos asignados automáticamente por servicio',
    'legalConsents.portalSignature' => 'Firma remota de consentimientos desde el portal',
    'legalConsents.autofirma' => 'Firma del paciente con certificado mediante AutoFirma',
    'legalConsents.advancedAudit' => 'Auditoría avanzada de consentimientos',
    'reminders.patient24h' => 'Recordatorios de cita por email',
    'reminders.sms' => 'Recordatorios de cita por SMS',
    'calendarSync.enabled' => 'Sincronización con calendarios online',
    'livekit.enabled' => 'Videollamada integrada con grabaci&oacute;n de audio/video opcional',
    'branding.customLogo' => 'Logotipo propio en dashboard y portal',
    'branding.customDomain' => 'Usa tu propio dominio o subdominio para acceder a la aplicación',
    'team.enabled' => 'Equipo de trabajo con varios profesionales',
    'team.memberTypes' => 'Tipos de miembro: recepción, administración y técnico',
    'team.permissions' => 'Permisos personalizados por miembro',
    'timeTracking.enabled' => 'Control horario de entradas, salidas y descansos',
    'catalog.customServices' => 'Servicios ofrecidos personalizables',
    'catalog.customDurations' => 'Duraciones de cita personalizables',
    'catalog.customLocations' => 'Salas y ubicaciones personalizables',
    'appointments.attendanceStatus' => 'Estados de cita y asistencia',
    'billing.fiscalData' => 'Datos fiscales para facturación',
    'ui.customization' => 'Personalización avanzada de opciones visibles',
];

$plan_highlight_features = [
    'initium' => [
        'tasks.enabled',
        'taskTemplates.enabled',
    ],
    'novus' => [
        'tasks.enabled',
        'taskTemplates.enabled',
    ],
    'magister' => [
        'patientPortal.enabled',
        'onlineBooking.enabled',
        'team.enabled',
        'bonuses.enabled',
        'knowledgeBase.enabled',
        'calendarSync.enabled',
        'reminders.sms',
        'catalog.customServices',
        'digitalSignature.tenant',
        'legalConsents.handwrittenSignature',
    ],
    'summum' => [
        'questionnaires.enabled',
        'billing.enabled',
        'billing.fiscalData',
        ['onlinePayments.enabled', 'payments.online'],
        'livekit.enabled',
        'documents.onlineEditor',
        'documents.drawingBoard',
        'digitalSignature.professional',
        'legalConsents.portalSignature',
        'legalConsents.autofirma',
        'team.permissions',
        'timeTracking.enabled',
        'branding.customDomain',
        'knowledgeBase.multiSector',
        'ui.customization',
    ],
];

$comparison_groups = [
    'Incluido en todos los planes' => [
        ['label' => 'Agenda visual con vistas mensual y semanal', 'common' => true],
        ['label' => 'Reserva y gestión interna de citas', 'common' => true],
        ['label' => 'Ficha completa de pacientes, clientes o usuarios', 'common' => true],
        ['label' => 'Historial de citas', 'common' => true],
        ['label' => 'Dashboard profesional adaptable a cada sector', 'common' => true],
        ['label' => 'Configuración básica de servicios, horarios y disponibilidad', 'common' => true],
        ['label' => 'Estados de cita: reservada, realizada, no asistió, pagada o pendiente', 'feature' => 'appointments.attendanceStatus'],
    ],
    'Capacidad del plan' => [
        ['label' => 'Pacientes o clientes', 'limit' => 'maxPatients'],
        ['label' => 'Citas por semana', 'limit' => 'maxAppointmentsPerWeek'],
        ['label' => 'Subida de archivos y adjuntos', 'feature' => 'documents.uploads'],
    ],
    'Portal y reservas online' => [
        ['label' => 'Portal privado para pacientes o clientes', 'feature' => 'patientPortal.enabled'],
        ['label' => 'Generación de invitaciones de registro', 'feature' => 'patientPortal.invitations'],
        ['label' => 'Reservas online desde el portal', 'feature' => 'onlineBooking.enabled'],
        ['label' => 'Recordatorios de cita por email', 'feature' => 'reminders.patient24h'],
        ['label' => 'Recordatorios de cita por SMS', 'feature' => 'reminders.sms'],
    ],
    'Agenda y gestión diaria' => [
        ['label' => 'Vacaciones, cierres y bloqueos de agenda', 'feature' => 'closures.enabled'],
        ['label' => 'Planning de próximas citas', 'feature' => 'upcomingAppointments.planning'],
        ['label' => 'Duración efectiva visible para el paciente', 'feature' => 'appointments.effectiveDuration'],
        ['label' => 'Servicios ofrecidos personalizables', 'feature' => 'catalog.customServices'],
        ['label' => 'Duraciones de cita personalizables', 'feature' => 'catalog.customDurations'],
        ['label' => 'Salas y ubicaciones personalizables', 'feature' => 'catalog.customLocations'],
        ['label' => 'Sincronización con calendarios online', 'feature' => 'calendarSync.enabled'],
        ['label' => 'Videollamada integrada con grabaci&oacute;n de audio/video opcional', 'feature' => 'livekit.enabled'],
    ],
    'Seguimiento profesional' => [
        ['label' => 'Tareas, pautas o ejercicios asignables', 'feature' => 'tasks.enabled'],
        ['label' => 'Plantillas reutilizables de tareas o rutinas', 'feature' => 'taskTemplates.enabled'],
        ['label' => 'Creaci&oacute;n de cuestionarios personalizados', 'feature' => 'questionnaires.enabled'],
        ['label' => 'Bonos y sesiones prepagadas', 'feature' => 'bonuses.enabled'],
    ],
    'Base de conocimiento e informes' => [
        ['label' => 'Editor online de documentos', 'feature' => 'documents.onlineEditor'],
        ['label' => 'Pizarra online de dibujo', 'feature' => 'documents.drawingBoard'],
        ['label' => 'Firma de documentos con certificado del centro', 'feature' => 'digitalSignature.tenant'],
        ['label' => 'Firma de documentos con certificado del profesional', 'feature' => 'digitalSignature.professional'],
        ['label' => 'Base de conocimiento por sector', 'feature' => 'knowledgeBase.enabled'],
        ['label' => 'Importar recomendaciones al plan de trabajo', 'feature' => 'knowledgeBase.importTasks'],
        ['label' => 'Consulta de bases de conocimiento relacionadas', 'feature' => 'knowledgeBase.multiSector'],
        ['label' => 'Estadísticas, listados e informes globales', 'feature' => 'reports.globalReports'],
    ],
    'Consentimientos y cumplimiento' => [
        ['label' => 'Registrar aceptación externa y subir PDF firmado', 'common' => true],
        ['label' => 'Plantillas de consentimientos personalizables', 'feature' => 'legalConsents.templates'],
        ['label' => 'Generación de consentimientos PDF autorrellenados', 'feature' => 'legalConsents.generatedPdf'],
        ['label' => 'Firma manuscrita presencial', 'feature' => 'legalConsents.handwrittenSignature'],
        ['label' => 'Consentimientos asignados automáticamente por servicio', 'feature' => 'legalConsents.serviceMapping'],
        ['label' => 'Firma remota desde el Portal de pacientes/clientes', 'feature' => 'legalConsents.portalSignature'],
        ['label' => 'Firma con certificado del paciente mediante AutoFirma', 'feature' => 'legalConsents.autofirma'],
        ['label' => 'Auditoría avanzada de consentimientos', 'feature' => 'legalConsents.advancedAudit'],
    ],
    'Pagos y facturación' => [
        ['label' => 'Compatibilidad con pago online', 'feature' => ['onlinePayments.enabled', 'payments.online']],
        ['label' => 'Datos fiscales para facturación', 'feature' => 'billing.fiscalData'],
        ['label' => 'Facturación integrada', 'feature' => 'billing.enabled'],
    ],
    'Equipo, marca y personalización' => [
        ['label' => 'Equipo de trabajo con varios profesionales', 'feature' => 'team.enabled'],
        ['label' => 'Miembros del equipo', 'limit' => 'teamMembers'],
        ['label' => 'Tipos de miembro no profesional: recepción, administración y técnico', 'feature' => 'team.memberTypes'],
        ['label' => 'Permisos personalizados para miembros del equipo', 'feature' => 'team.permissions'],
        ['label' => 'Control horario de entradas, salidas y descansos', 'feature' => 'timeTracking.enabled'],
        ['label' => 'Logotipo propio en dashboard y portal', 'feature' => 'branding.customLogo'],
        ['label' => 'Usa tu propio dominio o subdominio para acceder a la aplicación', 'feature' => 'branding.customDomain'],
        ['label' => 'Personalización avanzada de opciones visibles', 'feature' => 'ui.customization'],
    ],
];

$comparison_group_order = [
    'Incluido en todos los planes',
    'Capacidad del plan',
    'Seguimiento profesional',
    'Portal y reservas online',
    'Agenda y gestión diaria',
    'Base de conocimiento e informes',
    'Consentimientos y cumplimiento',
    'Equipo, marca y personalización',
    'Pagos y facturación',
];
$ordered_comparison_groups = [];
foreach ($comparison_group_order as $group_title) {
    if (isset($comparison_groups[$group_title])) {
        $ordered_comparison_groups[$group_title] = $comparison_groups[$group_title];
    }
}
foreach ($comparison_groups as $group_title => $features) {
    if (!isset($ordered_comparison_groups[$group_title])) {
        $ordered_comparison_groups[$group_title] = $features;
    }
}
$comparison_groups = $ordered_comparison_groups;
$payment_group_title = null;
foreach (array_keys($comparison_groups) as $group_title) {
    if (strpos($group_title, 'Pagos y facturaci') === 0) {
        $payment_group_title = $group_title;
        break;
    }
}
if ($payment_group_title !== null) {
    $payment_group_features = $comparison_groups[$payment_group_title];
    unset($comparison_groups[$payment_group_title]);
    $comparison_groups[$payment_group_title] = $payment_group_features;
}

foreach ($comparison_groups as $group_title => $features) {
    foreach ($features as $index => $feature) {
        $enabled_count = 0;
        foreach ($plans as $plan) {
            if (isset($feature['limit']) || !empty($feature['common']) || praxis_plans_feature_enabled($plan, $feature['feature'] ?? '')) {
                $enabled_count++;
            }
        }
        $comparison_groups[$group_title][$index]['enabled_count'] = $enabled_count;
        $comparison_groups[$group_title][$index]['sort_index'] = $index;
    }
    usort($comparison_groups[$group_title], function ($a, $b) {
        $by_enabled = ($b['enabled_count'] ?? 0) <=> ($a['enabled_count'] ?? 0);
        if ($by_enabled !== 0) {
            return $by_enabled;
        }
        return ($a['sort_index'] ?? 0) <=> ($b['sort_index'] ?? 0);
    });
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="description" content="Planes de SimplyGest Praxis para servicios profesionales multisectoriales.">
    <title>Planes y precios | SimplyGest Praxis</title>
    <link rel="icon" href="<?= htmlspecialchars($brand_icon_url, ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --sg-primary: #4285f4;
            --sg-primary-dark: #0b57d0;
            --sg-primary-soft: #e8f1ff;
            --sg-ink: #152033;
            --sg-muted: #627184;
            --sg-border: #dbe6f5;
            --sg-page: #f4f8ff;
            --sg-ok: #14a46c;
            --sg-no: #c5cfdb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--sg-ink);
            background:
                radial-gradient(circle at 8% 0%, rgba(66, 133, 244, .18), transparent 28rem),
                radial-gradient(circle at 86% 4%, rgba(6, 182, 212, .15), transparent 26rem),
                var(--sg-page);
        }

        .sg-nav {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(255, 255, 255, .88);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(219, 230, 245, .92);
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

        .sg-nav-link:hover,
        .sg-nav-link.is-active {
            color: var(--sg-primary-dark);
        }

        .sg-hero {
            padding: 4.8rem 0 2.6rem;
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
            margin-bottom: 1rem;
        }

        .sg-hero h1 {
            font-size: clamp(2.4rem, 5vw, 4.7rem);
            line-height: 1.02;
            font-weight: 800;
            margin-bottom: 1rem;
            letter-spacing: -.03em;
        }

        .sg-hero p {
            color: var(--sg-muted);
            font-size: 1.16rem;
            line-height: 1.68;
            max-width: 760px;
        }

        .sg-hero-panel {
            border-radius: 28px;
            background: linear-gradient(135deg, #0b57d0, #4285f4 56%, #04b7d9);
            color: #fff;
            padding: 2rem;
            box-shadow: 0 26px 70px rgba(18, 89, 203, .27);
            min-height: 100%;
        }

        .sg-hero-panel h2 {
            font-size: 1.55rem;
            font-weight: 800;
            margin-bottom: .8rem;
        }

        .sg-hero-panel p {
            color: rgba(255, 255, 255, .86);
            margin-bottom: 1.2rem;
        }

        .sg-mini-matrix {
            display: grid;
            gap: .7rem;
        }

        .sg-mini-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .8rem .95rem;
            border-radius: 16px;
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .24);
            font-weight: 700;
        }

        .sg-section {
            padding: 2.8rem 0;
        }

        .sg-section-title {
            max-width: 820px;
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

        .sg-plan-card {
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            border-radius: 24px;
            background: rgba(255, 255, 255, .9);
            border: 1px solid var(--sg-border);
            box-shadow: 0 20px 55px rgba(31, 70, 121, .09);
            padding: 1.5rem;
            overflow: hidden;
        }

        .sg-plan-card.is-featured {
            border-color: rgba(66, 133, 244, .44);
            box-shadow: 0 26px 70px rgba(18, 89, 203, .18);
        }

        .sg-plan-card.is-featured::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 6px;
            background: linear-gradient(90deg, var(--sg-primary), #00b8d9);
        }

        .sg-plan-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .sg-plan-label h3 {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 800;
        }

        .sg-plan-badge {
            border-radius: 999px;
            padding: .36rem .68rem;
            background: var(--sg-primary-soft);
            color: var(--sg-primary-dark);
            font-size: .78rem;
            font-weight: 800;
        }

        .sg-plan-price {
            padding: 1rem 0;
            border-top: 1px solid var(--sg-border);
            border-bottom: 1px solid var(--sg-border);
            margin: 1rem 0;
        }

        .sg-plan-price strong {
            display: block;
            font-size: 1.55rem;
            font-weight: 800;
        }

        .sg-plan-price span,
        .sg-plan-card p {
            color: var(--sg-muted);
        }

        .sg-plan-list {
            display: grid;
            gap: .65rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .sg-plan-list li,
        .sg-common-item {
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            color: #334155;
        }

        .sg-plan-list i,
        .sg-common-item i {
            color: var(--sg-ok);
            margin-top: .12rem;
        }

        .sg-common-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .sg-common-item {
            border-radius: 18px;
            background: rgba(255, 255, 255, .88);
            border: 1px solid var(--sg-border);
            padding: 1rem;
            box-shadow: 0 16px 38px rgba(31, 70, 121, .06);
            font-weight: 700;
        }

        .sg-table-card {
            background: rgba(255, 255, 255, .93);
            border: 1px solid var(--sg-border);
            border-radius: 26px;
            box-shadow: 0 20px 60px rgba(31, 70, 121, .09);
            overflow: hidden;
        }

        .sg-comparison-table {
            margin: 0;
            min-width: 840px;
        }

        .sg-comparison-table th {
            color: var(--sg-primary-dark);
            font-size: .86rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            background: #f8fbff;
            border-bottom-color: var(--sg-border);
            padding: 1rem;
            vertical-align: middle;
        }

        .sg-comparison-table td {
            padding: 1rem;
            vertical-align: middle;
            border-color: var(--sg-border);
        }

        .sg-feature-name {
            font-weight: 700;
            color: #26364a;
        }

        .sg-group-row td {
            background: linear-gradient(90deg, rgba(66, 133, 244, .1), rgba(6, 182, 212, .08));
            color: var(--sg-primary-dark);
            font-weight: 800;
            text-transform: uppercase;
            font-size: .82rem;
            letter-spacing: .04em;
        }

        .sg-check,
        .sg-cross {
            width: 30px;
            height: 30px;
            display: inline-grid;
            place-items: center;
            border-radius: 999px;
            font-size: 1rem;
            font-weight: 800;
        }

        .sg-check {
            color: #fff;
            background: var(--sg-ok);
            box-shadow: 0 8px 20px rgba(20, 164, 108, .2);
        }

        .sg-cross {
            color: #738196;
            background: #edf2f8;
        }

        .sg-footer {
            padding: 2rem 0 2.4rem;
            color: var(--sg-muted);
            text-align: center;
            font-size: .94rem;
        }

        .sg-footer img {
            height: 20px;
            width: 20px;
            object-fit: contain;
            vertical-align: -5px;
            margin-right: .45rem;
        }

        @media (max-width: 991.98px) {
            .sg-common-grid {
                grid-template-columns: 1fr;
            }

            .sg-hero {
                padding-top: 3rem;
            }
        }
    </style>
</head>

<body>
    <nav class="sg-nav">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="./" class="d-inline-flex align-items-center text-decoration-none">
                <img src="<?= htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="SimplyGest Praxis" class="sg-brand-logo">
            </a>
            <div class="d-none d-md-flex align-items-center gap-4">
                <a class="sg-nav-link" href="./">Inicio</a>
                <a class="sg-nav-link" href="./#sectores">Sectores</a>
                <a class="sg-nav-link is-active" href="app-plans.php">Planes</a>
            </div>
        </div>
    </nav>

    <main>
        <section class="sg-hero">
            <div class="container">
                <div class="row align-items-center g-4">
                    <div class="col-lg-7">
                        <span class="sg-pill"><i class="bi bi-layers"></i> Planes de SimplyGest Praxis</span>
                        <h1>Elige el nivel de gestión que necesita tu centro.</h1>
                        <p>
                            Los planes están pensados para acompañar distintas formas de trabajar: desde una agenda profesional
                            sencilla hasta un entorno completo con portal, conocimiento sectorial, informes, equipo y personalización avanzada.
                        </p>
                    </div>
                    <div class="col-lg-5">
                        <div class="sg-hero-panel">
                            <h2>Elige tu Plan</h2>
                            <div class="sg-mini-matrix">
                                <?php foreach ($plans as $plan): ?>
                                    <div class="sg-mini-row">
                                        <span><?= htmlspecialchars($plan['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <i class="bi bi-check2-circle"></i>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="sg-section pt-2">
            <div class="container">
                <div class="sg-section-title">
                    <h2>Incluido en todos los planes</h2>
                    <p>La base de la aplicación cubre el trabajo diario esencial sin obligar a empezar con módulos avanzados.</p>
                </div>
                <div class="sg-common-grid">
                    <?php foreach ($common_features as $common_feature): ?>
                        <div class="sg-common-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span><?= htmlspecialchars($common_feature, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="sg-section">
            <div class="container">
                <div class="sg-section-title">
                    <h2>Cuatro planes para crecer</h2>
                    <p>Empieza por lo básico y cambia de plan cuando necesites más opciones o personalización avanzada.</p>
                </div>
                <div class="row g-4">
                    <?php foreach ($plans as $plan_key => $plan): ?>
                        <?php
                        $enabled_count = count(array_filter($plan['features'] ?? []));
                        $summary = $plan_summaries[$plan_key] ?? ['tagline' => 'Plan configurable.', 'tone' => 'Plan'];
                        $highlight_features = [];
                        foreach (($plan_highlight_features[$plan_key] ?? []) as $highlight_feature) {
                            if (praxis_plans_feature_enabled($plan, $highlight_feature)) {
                                if (is_array($highlight_feature)) {
                                    $highlight_features[] = 'Pago online por Redsys';
                                } else {
                                    $highlight_features[] = $feature_labels[$highlight_feature] ?? $highlight_feature;
                                }
                            }
                        }
                        ?>
                        <div class="col-xl-3 col-md-6">
                            <article class="sg-plan-card <?= $plan_key === 'summum' ? 'is-featured' : '' ?>">
                                <div class="sg-plan-label">
                                    <h3><?= htmlspecialchars($plan['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <span class="sg-plan-badge"><?= htmlspecialchars($summary['tone'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <p><?= htmlspecialchars($summary['tagline'], ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="sg-plan-price">
                                    <strong><?= htmlspecialchars($summary['price'] ?? 'Precio a definir', ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= $plan_key === 'initium' ? 'Sin tarjeta y sin caducidad' : 'Impuestos no incluidos' ?></span>
                                </div>
                                <ul class="sg-plan-list">
                                    <li><i class="bi bi-check-circle-fill"></i><span>Base común de agenda y gestión diaria.</span></li>
                                    <?php foreach ($highlight_features as $highlight): ?>
                                        <li><i class="bi bi-check-circle-fill"></i><span><?= htmlspecialchars($highlight, ENT_QUOTES, 'UTF-8') ?></span></li>
                                    <?php endforeach; ?>
                                    <li><i class="bi bi-check-circle-fill"></i><span>Miembros del equipo: <?= htmlspecialchars(praxis_plans_team_limit_label($plan), ENT_QUOTES, 'UTF-8') ?>.</span></li>
                                    <li><i class="bi bi-check-circle-fill"></i><span><?= (int) $enabled_count ?> funciones configurables incluidas.</span></li>
                                    <li><i class="bi bi-check-circle-fill"></i><span>Duraciones disponibles: <?= htmlspecialchars(implode(', ', $plan['limits']['appointmentDurations'] ?? [60, 90, 120]), ENT_QUOTES, 'UTF-8') ?> min.</span></li>
                                </ul>
                                <div class="mt-auto pt-4">
                                    <a class="btn btn-primary w-100" href="signup.php">Probar Summum 15 d&iacute;as</a>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="sg-section" id="comparativa">
            <div class="container">
                <div class="sg-section-title">
                    <h2>Tabla de diferencias</h2>
                </div>
                <div class="sg-table-card">
                    <div class="table-responsive">
                        <table class="table sg-comparison-table">
                            <thead>
                                <tr>
                                    <th>Característica</th>
                                    <?php foreach ($plans as $plan): ?>
                                        <th class="text-center"><?= htmlspecialchars($plan['label'], ENT_QUOTES, 'UTF-8') ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparison_groups as $group_title => $features): ?>
                                    <tr class="sg-group-row">
                                        <td colspan="<?= count($plans) + 1 ?>"><?= htmlspecialchars($group_title, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                    <?php foreach ($features as $feature): ?>
                                        <tr>
                                            <td class="sg-feature-name"><?= htmlspecialchars($feature['label'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <?php foreach ($plans as $plan): ?>
                                                <?php $enabled = !empty($feature['common']) || praxis_plans_feature_enabled($plan, $feature['feature'] ?? ''); ?>
                                                <td class="text-center">
                                                    <?php if (isset($feature['limit'])): ?>
                                                        <?php
                                                        $limit_value = praxis_plans_limit_value($plan, $feature['limit'], null);
                                                        $limit_badge = ($limit_value === null || $limit_value === '' || (int) $limit_value === 0)
                                                            ? null
                                                            : max(1, (int) $limit_value);
                                                        ?>
                                                        <span class="sg-check" title="<?= $limit_badge === null ? 'Sin límite' : ((string) $limit_badge) ?>">
                                                            <?php if ($limit_badge === null): ?>
                                                                <i class="bi bi-check-lg"></i>
                                                            <?php else: ?>
                                                                <?= (int) $limit_badge ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="<?= $enabled ? 'sg-check' : 'sg-cross' ?>" title="<?= $enabled ? 'Incluido' : 'No incluido' ?>">
                                                            <i class="bi <?= $enabled ? 'bi-check-lg' : 'bi-dash-lg' ?>"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="sg-footer">
        <div class="container">
            <?php if ($brand_icon_url !== ''): ?>
                <img src="<?= htmlspecialchars($brand_icon_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php endif; ?>
            <?= htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') ?> - <?= (int) $year ?>
        </div>
    </footer>
</body>

</html>
