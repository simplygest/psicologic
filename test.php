<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Propuestas · Detalle de cita</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --praxis: #927bc2; --praxis-soft: #f5f1fb; --ink: #30343b; }
        body { background: #eef0f4; color: var(--ink); }
        .page-shell { max-width: 1180px; }
        .proposal { border: 0; border-radius: 16px; box-shadow: 0 10px 28px rgba(39, 43, 58, .08); overflow: hidden; }
        .proposal-head { background: #fff; border-bottom: 1px solid #e7e8ec; padding: 18px 22px; }
        .proposal-body { background: #fff; padding: 22px; }
        .eyebrow { color: var(--praxis); font-size: .78rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; }
        .btn-praxis { --bs-btn-color: #fff; --bs-btn-bg: var(--praxis); --bs-btn-border-color: var(--praxis); }
        .btn-outline-praxis { --bs-btn-color: var(--praxis); --bs-btn-border-color: var(--praxis); --bs-btn-hover-color: #fff; --bs-btn-hover-bg: var(--praxis); }
        .compact-label { color: #737982; display: block; font-size: .76rem; margin-bottom: 2px; }
        .status-line { background: #fafafa; border: 1px solid #e7e8ec; border-radius: 10px; padding: 10px 12px; }
        .diagnosis { border-left: 3px solid var(--praxis); padding-left: 12px; }
        .video-line { background: var(--praxis-soft); border-radius: 10px; padding: 11px 13px; }
        .data-table > div { border-bottom: 1px solid #eceef1; padding: 10px 0; }
        .data-table > div:last-child { border-bottom: 0; }
        .icon-cell { align-items: center; background: var(--praxis-soft); border-radius: 10px; color: var(--praxis); display: flex; flex: 0 0 38px; height: 38px; justify-content: center; }
        .summary-strip { background: #f8f9fb; border-radius: 12px; }
        .summary-strip > div { padding: 13px 15px; }
        .summary-strip > div + div { border-left: 1px solid #e2e4e8; }
        .minimal-grid { display: grid; gap: 0; grid-template-columns: 145px 1fr; }
        .minimal-grid > div { border-bottom: 1px solid #eceef1; padding: 9px 4px; }
        .minimal-grid > div:nth-last-child(-n+2) { border-bottom: 0; }
        @media (max-width: 700px) {
            .summary-strip > div + div { border-left: 0; border-top: 1px solid #e2e4e8; }
            .minimal-grid { grid-template-columns: 110px 1fr; }
        }
    </style>
</head>
<body>
<main class="container page-shell py-4 py-lg-5">
    <div class="mb-4">
        <span class="eyebrow">Laboratorio visual</span>
        <h1 class="h3 mt-1">Detalle de la cita · cuatro alternativas</h1>
        <p class="text-secondary mb-0">Todas conservan la misma información y acciones, reduciendo decoración y altura.</p>
    </div>

    <section class="proposal mb-4">
        <header class="proposal-head d-flex justify-content-between align-items-start gap-3">
            <div><span class="eyebrow">Propuesta A</span><h2 class="h5 mb-0 mt-1">Resumen compacto</h2></div>
            <span class="badge text-bg-light border">Equilibrada</span>
        </header>
        <div class="proposal-body">
            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                <div><h3 class="h5 mb-1">Paciente 4</h3><div class="text-secondary small">miraver@gmail.com</div></div>
                <div class="d-flex gap-1 align-items-start"><span class="badge text-bg-warning">Pendiente de pago</span><span class="badge text-bg-success">Reservada</span><span class="badge text-bg-warning">Sin confirmar</span></div>
            </div>
            <div class="row g-2 summary-strip mb-3 mx-0">
                <div class="col-md"><span class="compact-label">Fecha</span><strong>07/09/2026 · 11:00–12:00</strong></div>
                <div class="col-md"><span class="compact-label">Profesional</span><strong>Stephanie</strong></div>
                <div class="col-md"><span class="compact-label">Servicio</span><strong>Individual · 60 min</strong></div>
                <div class="col-md"><span class="compact-label">Diagnóstico</span><strong>Ansiedad</strong></div>
            </div>
            <div class="video-line d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="bi bi-camera-video me-2"></i><strong>Online</strong> · El acceso se genera automáticamente para cada participante.</span>
                <div class="d-flex flex-wrap gap-2"><button class="btn btn-praxis btn-sm"><i class="bi bi-camera-video"></i> Abrir</button><button class="btn btn-outline-secondary btn-sm">Regenerar enlace</button><button class="btn btn-outline-praxis btn-sm">Enviar</button></div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3"><span class="small fw-semibold me-2">Asistencia</span><button class="btn btn-praxis btn-sm">Reservada</button><button class="btn btn-outline-praxis btn-sm">Realizada</button><button class="btn btn-outline-warning btn-sm">No asistió</button><span class="ms-md-auto small"><strong>Pago:</strong> Pendiente</span></div>
        </div>
    </section>

    <section class="proposal mb-4">
        <header class="proposal-head"><span class="eyebrow">Propuesta B</span><h2 class="h5 mb-0 mt-1">Ficha en dos columnas</h2></header>
        <div class="proposal-body">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="d-flex justify-content-between gap-3 mb-2"><div><h3 class="h5 mb-0">Paciente 4</h3><small class="text-secondary">miraver@gmail.com</small></div><span class="badge text-bg-success align-self-start">Reservada</span></div>
                    <div class="data-table">
                        <div class="d-flex gap-3"><span class="icon-cell"><i class="bi bi-calendar3"></i></span><div><span class="compact-label">Fecha y hora</span><strong>7 septiembre 2026 · 11:00–12:00</strong></div></div>
                        <div class="d-flex gap-3"><span class="icon-cell"><i class="bi bi-person-badge"></i></span><div><span class="compact-label">Profesional y servicio</span><strong>Stephanie · Individual (60 min)</strong></div></div>
                        <div class="d-flex gap-3"><span class="icon-cell"><i class="bi bi-activity"></i></span><div><span class="compact-label">Diagnóstico</span><strong>Ansiedad</strong></div></div>
                    </div>
                </div>
                <div class="col-lg-5 border-lg-start">
                    <div class="d-flex justify-content-between mb-2"><strong>Videollamada</strong><button class="btn btn-link btn-sm p-0 text-decoration-none">Cambiar a presencial</button></div>
                    <p class="small text-secondary">El acceso se genera automáticamente para cada participante.</p>
                    <div class="d-grid gap-2"><button class="btn btn-praxis"><i class="bi bi-camera-video"></i> Abrir videollamada</button><div class="btn-group"><button class="btn btn-outline-secondary btn-sm">Regenerar</button><button class="btn btn-outline-praxis btn-sm">Enviar al paciente</button></div></div>
                    <hr><div class="d-flex justify-content-between small"><span>Pago</span><strong class="text-warning-emphasis">Pendiente</strong></div><div class="d-flex justify-content-between small mt-2"><span>Confirmación</span><strong>Sin confirmar</strong></div>
                </div>
            </div>
        </div>
    </section>

    <section class="proposal mb-4">
        <header class="proposal-head"><span class="eyebrow">Propuesta C</span><h2 class="h5 mb-0 mt-1">Barra operativa</h2></header>
        <div class="proposal-body">
            <div class="d-flex flex-wrap align-items-center gap-3 pb-3 border-bottom">
                <div class="me-auto"><h3 class="h5 mb-0">Paciente 4</h3><small class="text-secondary">07/09/2026 · 11:00–12:00 · Stephanie</small></div>
                <span class="badge text-bg-success">Reservada</span><span class="badge text-bg-warning">Pago pendiente</span><span class="badge text-bg-light border">Sin confirmar</span>
            </div>
            <div class="row g-3 py-3">
                <div class="col-md-4"><span class="compact-label">Servicio</span><strong>Individual (60 min)</strong></div>
                <div class="col-md-4"><span class="compact-label">Diagnóstico</span><strong>Ansiedad</strong></div>
                <div class="col-md-4"><span class="compact-label">Modalidad</span><strong><i class="bi bi-camera-video me-1"></i>Online</strong></div>
            </div>
            <div class="status-line d-flex flex-wrap align-items-center gap-2"><span class="small me-auto">El acceso a la videollamada se genera automáticamente para cada participante.</span><button class="btn btn-praxis btn-sm">Abrir videollamada</button><button class="btn btn-outline-secondary btn-sm">Más opciones <i class="bi bi-chevron-down"></i></button></div>
            <div class="d-flex flex-wrap gap-2 mt-3"><span class="small fw-semibold align-self-center me-1">Marcar como:</span><button class="btn btn-praxis btn-sm">Reservada</button><button class="btn btn-outline-praxis btn-sm">Realizada</button><button class="btn btn-outline-warning btn-sm">No asistió</button></div>
        </div>
    </section>

    <section class="proposal mb-4">
        <header class="proposal-head"><span class="eyebrow">Propuesta D</span><h2 class="h5 mb-0 mt-1">Máxima densidad</h2></header>
        <div class="proposal-body">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h3 class="h5 mb-0">Paciente 4 <small class="text-secondary fw-normal">· miraver@gmail.com</small></h3><div><span class="badge text-bg-success">Reservada</span> <span class="badge text-bg-warning">Pendiente</span></div></div>
            <div class="minimal-grid small">
                <div class="text-secondary">Fecha</div><div class="fw-semibold">07/09/2026 · 11:00–12:00</div>
                <div class="text-secondary">Sesión</div><div><strong>Individual (60 min)</strong> con Stephanie</div>
                <div class="text-secondary">Diagnóstico</div><div class="fw-semibold">Ansiedad</div>
                <div class="text-secondary">Videollamada</div><div>Acceso automático para cada participante</div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-praxis btn-sm"><i class="bi bi-camera-video"></i> Abrir videollamada</button><button class="btn btn-outline-secondary btn-sm">Regenerar</button><button class="btn btn-outline-praxis btn-sm">Enviar</button><span class="vr d-none d-md-block mx-1"></span><button class="btn btn-outline-praxis btn-sm">Realizada</button><button class="btn btn-outline-warning btn-sm">No asistió</button></div>
        </div>
    </section>
</main>
</body>
</html>
