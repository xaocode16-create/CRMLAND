<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->type->label() }} | CRMLAND</title>
    <style>
        @page { margin: 38px 36px 50px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; line-height: 1.5; color: #152b45; }
        header { border-bottom: 3px solid #0068cc; padding-bottom: 16px; margin-bottom: 20px; }
        .brand { font-size: 20px; font-weight: bold; color: #0068cc; }
        h1 { font-size: 19px; margin: 6px 0 0; }
        .metadata { margin-bottom: 20px; }
        .metadata p { margin: 3px 0; }
        .metadata strong { display: inline-block; width: 105px; color: #526781; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        th { background: #0068cc; color: #fff; padding: 10px; text-align: left; }
        td { border-bottom: 1px solid #dce7f3; padding: 10px; word-wrap: break-word; }
        tr { page-break-inside: avoid; }
        tbody tr:nth-child(even) { background: #f0f6fc; }
        .numeric { text-align: right; }
        .empty { text-align: center; padding: 24px; color: #526781; }
        .note { font-size: 9px; color: #526781; margin-top: 18px; }
        footer { position: fixed; bottom: -30px; left: 0; right: 0; border-top: 1px solid #dce7f3; padding-top: 8px; font-size: 8px; color: #526781; }
        .page { float: right; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    <header>
        <div class="brand">CRMLAND</div>
        <h1>{{ $document->type->label() }}</h1>
    </header>
    <div class="metadata">
        @foreach ($document->metadata() as $label => $value)
            <p><strong>{{ $label }}</strong> {{ $value }}</p>
        @endforeach
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($document->type->headings() as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($document->rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td @class(['numeric' => is_int($value) || is_float($value)])>{{ is_float($value) ? number_format($value, 2) : $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($document->type->headings()) }}">Sin datos para el periodo seleccionado.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($document->type->note() !== null)
        <p class="note">{{ $document->type->note() }}</p>
    @endif
    <footer>CRMLAND | {{ $document->type->label() }} <span class="page">Pagina </span></footer>
</body>
</html>
