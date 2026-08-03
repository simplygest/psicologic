<?php
require_once __DIR__ . '/app_paths.php';
require_once __DIR__ . '/tenant_helpers.php';

if (function_exists('tenant_bootstrap')) {
    tenant_bootstrap();
}

header('X-Robots-Tag: noindex, nofollow', true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Prueba editor DOCX - SimplyGest Praxis</title>
    <link rel="stylesheet" href="/js/docx-editor-test/main.css">
</head>
<body>
    <div id="docx-editor-test-root"></div>
    <script type="module" src="/js/docx-editor-test/main.js"></script>
</body>
</html>
