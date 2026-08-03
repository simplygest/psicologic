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
    <title>Prueba editor de dibujos - SimplyGest Praxis</title>
    <link rel="stylesheet" href="/js/drawing-editor-test/main.css?v=202607219">
</head>
<body>
    <div id="drawing-editor-test-root"></div>
    <script type="module" src="/js/drawing-editor-test/main.js?v=202607219"></script>
</body>
</html>
