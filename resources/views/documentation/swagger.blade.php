<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Référentiel des structures : documentation</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; }
        .aide { font-family: system-ui, sans-serif; background: #f4f6f8; border-bottom: 1px solid #d9dee3; padding: 12px 24px; font-size: 14px; }
        .aide code { background: #e6eaee; padding: 1px 4px; border-radius: 3px; }
    </style>
</head>
<body>
<div class="aide">
    Pour tester : ouvrez <code>POST /auth/login</code>, cliquez sur "Try it out" avec
    <code>admin@referentiel.local</code> / <code>password</code>, copiez le <code>jeton</code>,
    puis cliquez sur <strong>Authorize</strong> et collez-le.
</div>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
<script>
    window.ui = SwaggerUIBundle({
        url: @json(route('web.documentation.contrat')),
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: true,
        displayRequestDuration: true,
        tryItOutEnabled: true,
        // Toujours appeler l'API du serveur qui sert la page, quel que soit le port.
        requestInterceptor: (requete) => {
            requete.url = requete.url.replace(/^https?:\/\/localhost:8000/, window.location.origin);
            return requete;
        },
    });
</script>
</body>
</html>
