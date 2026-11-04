<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titre', 'Référentiel des structures')</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #1b1b1b; background: #f6f6f6; }
        header { background: #000091; color: #fff; padding: 1rem 2rem; }
        header h1 { margin: 0; font-size: 1.4rem; }
        main { max-width: 1100px; margin: 1.5rem auto; padding: 0 1rem; }
        form.filtres { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; background: #fff; padding: 1rem; border: 1px solid #ddd; }
        form.filtres div { display: flex; flex-direction: column; }
        label { font-weight: 600; margin-bottom: .25rem; }
        input, select, button { font-size: 1rem; padding: .4rem .6rem; }
        button { background: #000091; color: #fff; border: 0; cursor: pointer; }
        button:focus, input:focus, select:focus, a:focus { outline: 3px solid #0a76f6; outline-offset: 2px; }
        .erreurs { background: #ffe9e9; border-left: 4px solid #ce0500; padding: .5rem 1rem; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 1rem; }
        th, td { text-align: left; padding: .5rem; border-bottom: 1px solid #ddd; }
        th { background: #eee; }
        .statut-INACTIVE { color: #ce0500; }
        .statut-ACTIVE { color: #18753c; }
        nav.pagination { margin-top: 1rem; display: flex; gap: 1rem; }
    </style>
</head>
<body>
<header><h1>Référentiel des structures administratives</h1></header>
<main>
    @yield('contenu')
</main>
</body>
</html>
