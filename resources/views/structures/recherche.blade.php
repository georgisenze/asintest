@extends('structures.layout')

@section('titre', 'Recherche de structures')

@section('contenu')
    <form class="filtres" method="get" action="{{ route('web.structures.recherche') }}" role="search">
        <div>
            <label for="q">Code, sigle ou libellé</label>
            <input type="search" id="q" name="q" value="{{ is_string($criteres['q'] ?? null) ? $criteres['q'] : '' }}" maxlength="100">
        </div>
        <div>
            <label for="type">Type</label>
            <select id="type" name="type">
                <option value="">Tous</option>
                @foreach ($types as $type)
                    <option value="{{ $type->value }}" @selected(($criteres['type'] ?? '') === $type->value)>{{ $type->libelle() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="statut">Statut</label>
            <select id="statut" name="statut">
                <option value="">Tous</option>
                @foreach ($statuts as $statut)
                    <option value="{{ $statut->value }}" @selected(($criteres['statut'] ?? '') === $statut->value)>{{ ucfirst(strtolower($statut->value)) }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit">Rechercher</button>
    </form>

    @if ($erreurs->any())
        <div class="erreurs" role="alert">
            <ul>
                @foreach ($erreurs->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($resultats)
        <p aria-live="polite">{{ $resultats->total() }} structure(s) trouvée(s).</p>

        <table>
            <caption class="sr-only" style="position:absolute;left:-9999px">Résultats de la recherche</caption>
            <thead>
            <tr>
                <th scope="col">Code</th>
                <th scope="col">Sigle</th>
                <th scope="col">Libellé</th>
                <th scope="col">Type</th>
                <th scope="col">Rattachement</th>
                <th scope="col">Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($resultats as $s)
                <tr>
                    <td>{{ $s->code }}</td>
                    <td>{{ $s->sigle }}</td>
                    <td>{{ $s->libelle }}</td>
                    <td>{{ $s->type->libelle() }}</td>
                    <td>{{ $s->parent?->code ?? '' }}</td>
                    <td class="statut-{{ $s->statut->value }}">{{ ucfirst(strtolower($s->statut->value)) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Aucune structure ne correspond à ces critères.</td></tr>
            @endforelse
            </tbody>
        </table>

        @if ($resultats->hasPages())
            <nav class="pagination" aria-label="Pagination">
                @if ($resultats->previousPageUrl())
                    <a href="{{ $resultats->previousPageUrl() }}" rel="prev">Page précédente</a>
                @endif
                <span>Page {{ $resultats->currentPage() }} sur {{ $resultats->lastPage() }}</span>
                @if ($resultats->nextPageUrl())
                    <a href="{{ $resultats->nextPageUrl() }}" rel="next">Page suivante</a>
                @endif
            </nav>
        @endif
    @endif
@endsection
