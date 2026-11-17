<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Via de preparo · Pedido #{{ $order->id }}</title>
    <style>
        body { font: 15px monospace; margin: 12px auto; max-width: 76mm; padding: 0 5mm; }
        li { margin-block: 8px; }
        @media print { button { display: none; } }
    </style>
</head>
<body>
    <h1>{{ $item->kitchen_station ?? 'Preparo geral' }}</h1>
    <p>Pedido #{{ $order->id }} · {{ App\Support\OrderMode::label($order->mode, $order->dining_label) }}</p>
    <ul>
        @foreach($items as $line)
            <li>
                <strong>{{ $line->quantity }}× {{ $line->name }}</strong>
                @foreach(json_decode($line->options ?? '[]', true) as $option)
                    <br>{{ $option['group'] ?? '' }}: {{ $option['name'] ?? '' }}
                @endforeach
            </li>
        @endforeach
    </ul>
    <button type="button" onclick="window.print()">Imprimir</button>
</body>
</html>
