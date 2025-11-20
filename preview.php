<?php
// preview.php

require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/lib.php';

$language   = $_POST['language']    ?? 'es';
$searchMode = $_POST['search_mode'] ?? 'exact';

$cards = get_card_list_from_request();

if (empty($cards)) {
    die("No se ha proporcionado ninguna carta. <a href='index.php'>Volver</a>");
}

$textCardsHtml  = [];
$imageCardsHtml = [];
$results        = [];

foreach ($cards as $entry) {
    $rawName = trim($entry['name']);
    $qty     = (int)$entry['qty'];
    if ($qty < 1) $qty = 1;
    if ($rawName === '') continue;

    $name    = $rawName;
    $setCode = null;
    if (strpos($rawName, '|') !== false) {
        [$name, $setCode] = array_map('trim', explode('|', $rawName, 2));
        if ($name === '') $name = $rawName;
    }

    $card = fetch_card_from_scryfall($name, $language, $searchMode, $setCode);

    if (!$card) {
        $results[] = [
            'input'   => $rawName,
            'qty'     => $qty,
            'status'  => 'error',
            'message' => 'No encontrada en Scryfall',
        ];
        continue;
    }

    $cardName = $card['name'] ?? $rawName;
    $setCard  = $card['set']  ?? '';
    $langCard = $card['lang'] ?? '';

    for ($i = 0; $i < $qty; $i++) {
        $textCardsHtml[] = build_text_card_html($card);

        $imgUrl = get_front_image_url($card);
        if ($imgUrl) {
            $imageCardsHtml[] = '
<div class="card-container">
  <img class="card-img" src="' . htmlspecialchars($imgUrl) . '" alt="card">
</div>';
        }
    }

    $results[] = [
        'input'  => $rawName,
        'qty'    => $qty,
        'status' => 'ok',
        'card'   => $cardName,
        'set'    => $setCard,
        'lang'   => $langCard,
    ];

    usleep(80000);
}

$textPagesHtml  = build_grid_pages_html($textCardsHtml);
$imagePagesHtml = build_grid_pages_html($imageCardsHtml);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vista previa - Generador de Proxies MTG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#111; color:#eee; }

        .page {
            width: 210mm;
            height: 297mm;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-content: space-between;
            padding: 10mm;
            margin: 10mm auto;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
        }

        .card-container {
            position: relative;
            width: 67mm;
            height: 92mm;
            overflow: hidden;
            background: #f9f4e9;
        }

        .card-container::before,
        .card-container::after {
            content: "";
            position: absolute;
            border-color: black;
            width: 8mm;
            height: 8mm;
            z-index: 100;
        }

        .card-container::before {
            top: -2mm;
            left: -2mm;
            border-top: 0.3mm solid black;
            border-left: 0.3mm solid black;
        }

        .card-container::after {
            bottom: -2mm;
            right: -2mm;
            border-bottom: 0.3mm solid black;
            border-right: 0.3mm solid black;
        }

        .card-img {
            width: 67mm;
            height: 92mm;
            object-fit: cover;
            display: block;
        }

        .card-text {
            width: 67mm;
            height: 92mm;
            padding: 4mm;
            box-sizing: border-box;
            background: #f9f4e9;
            position: relative;
            font-family: "Times New Roman", serif;
            box-shadow: inset 0 0 2mm rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .border-W { border: 1.5mm solid #e8d9a6; }
        .border-U { border: 1.5mm solid #7ab6e8; }
        .border-B { border: 1.5mm solid #4a4a4a; }
        .border-R { border: 1.5mm solid #c96a5a; }
        .border-G { border: 1.5mm solid #6b8f68; }
        .border-C { border: 1.5mm solid #bfbfbf; }
        .border-M { border: 1.5mm solid #d4b455; }

        .title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 2mm;
            padding-right: 10mm;
        }

        .mana {
            position: absolute;
            right: 3mm;
            top: 3mm;
        }

        .type {
            font-style: italic;
            margin-bottom: 3mm;
            font-size: 10pt;
        }

        .oracle {
            font-size: 9pt;
            line-height: 1.2;
            white-space: pre-wrap;
        }

        .mana img,
        .oracle img {
            width: 11pt;
            height: 11pt;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="mb-3">Vista previa</h1>
    <a href="index.php" class="btn btn-secondary mb-4">← Volver</a>

    <div class="card bg-secondary mb-4">
        <div class="card-body">
            <h2 class="h5">Cartas interpretadas</h2>
            <table class="table table-dark table-striped align-middle mt-3">
                <thead>
                    <tr>
                        <th>Input</th>
                        <th>Cantidad</th>
                        <th>Estado</th>
                        <th>Carta</th>
                        <th>Set</th>
                        <th>Idioma</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['input']) ?></td>
                        <td><?= (int)($r['qty'] ?? 1) ?></td>
                        <td>
                            <?php if ($r['status'] === 'ok'): ?>
                                <span class="badge bg-success">OK</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= htmlspecialchars($r['message']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($r['card'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['set'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['lang'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($imageCardsHtml)): ?>
        <h2 class="h5 text-light mb-2">Vista previa frontal (IMÁGENES)</h2>
        <?= $imagePagesHtml ?>
    <?php endif; ?>

    <?php if (!empty($textCardsHtml)): ?>
        <h2 class="h5 text-light mt-4 mb-2">Vista previa frontal (TEXTO)</h2>
        <?= $textPagesHtml ?>
    <?php endif; ?>
</div>
</body>
</html>
