<?php
// lib.php

function http_get_json(string $url): ?array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => "MTG Proxy Generator"
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $status !== 200) {
        // Log opcional:
        // error_log("HTTP error: $err, status: $status, url: $url");
        return null;
    }

    $json = json_decode($response, true);
    return is_array($json) ? $json : null;
}


function ensure_dir(string $path): void {
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
}

function clean_filename(string $name): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
}

/**
 * Parsea una línea con cantidad opcional.
 * Formatos soportados:
 *  - "4 Lightning Bolt"
 *  - "Lightning Bolt x4"
 *  - "Lightning Bolt,4"
 *  - "4,Lightning Bolt"
 *  - "Lightning Bolt 4"
 *  - "Lightning Bolt (4)"
 * Si no detecta cantidad, asume 1.
 */
function parse_card_line_with_qty(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;

    // 4 Lightning Bolt
    if (preg_match('/^(\d+)\s+(.+)$/u', $line, $m)) {
        return [
            'name' => trim($m[2]),
            'qty'  => max(1, (int)$m[1]),
        ];
    }

    // Lightning Bolt x4
    if (preg_match('/^(.+?)\s*[xX]\s*(\d+)$/u', $line, $m)) {
        return [
            'name' => trim($m[1]),
            'qty'  => max(1, (int)$m[2]),
        ];
    }

    // 4,Lightning Bolt
    if (preg_match('/^(\d+)\s*,\s*(.+)$/u', $line, $m)) {
        return [
            'name' => trim($m[2]),
            'qty'  => max(1, (int)$m[1]),
        ];
    }

    // Lightning Bolt,4
    if (preg_match('/^(.+)\s*,\s*(\d+)$/u', $line, $m)) {
        return [
            'name' => trim($m[1]),
            'qty'  => max(1, (int)$m[2]),
        ];
    }

    // Lightning Bolt 4
    if (preg_match('/^(.+?)\s+(\d+)$/u', $line, $m)) {
        return [
            'name' => trim($m[1]),
            'qty'  => max(1, (int)$m[2]),
        ];
    }

    // Lightning Bolt (4)
    if (preg_match('/^(.+?)\s*\((\d+)\)\s*$/u', $line, $m)) {
        return [
            'name' => trim($m[1]),
            'qty'  => max(1, (int)$m[2]),
        ];
    }

    // Por defecto, 1 copia
    return [
        'name' => $line,
        'qty'  => 1,
    ];
}

/**
 * Lee la lista de cartas desde textarea y/o fichero TXT/CSV.
 * Devuelve array de:
 * [
 *   ['name' => 'Lightning Bolt|mm3', 'qty' => 4],
 *   ...
 * ]
 */
function get_card_list_from_request(): array {
    $map = [];

    $consume_line = function(string $line) use (&$map) {
        $parsed = parse_card_line_with_qty($line);
        if ($parsed === null) return;
        $name = $parsed['name'];
        $qty  = $parsed['qty'];

        if ($name === '') return;

        if (!isset($map[$name])) {
            $map[$name] = 0;
        }
        $map[$name] += $qty;
    };

    // Textarea
    if (!empty($_POST['cards'])) {
        $lines = preg_split('/\r\n|\r|\n/', trim($_POST['cards']));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $consume_line($line);
        }
    }

    // Archivo
    if (!empty($_FILES['cards_file']['tmp_name']) && is_uploaded_file($_FILES['cards_file']['tmp_name'])) {
        $tmp  = $_FILES['cards_file']['tmp_name'];
        $ext  = strtolower(pathinfo($_FILES['cards_file']['name'], PATHINFO_EXTENSION));
        $content = file_get_contents($tmp);
        $lines   = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            if ($ext === 'csv') {
                $cols = str_getcsv($line);
                if (!empty($cols[0]) && !empty($cols[1]) && is_numeric($cols[0])) {
                    // Soporta "4,Lightning Bolt" o "4;Lightning Bolt" dependiendo del separador
                    $consume_line($cols[0] . ' ' . $cols[1]);
                } else {
                    $consume_line($line);
                }
            } else {
                $consume_line($line);
            }
        }
    }

    $list = [];
    foreach ($map as $name => $qty) {
        $list[] = [
            'name' => $name,
            'qty'  => $qty,
        ];
    }

    return $list;
}

/**
 * Llama a la API de Scryfall para obtener una carta.
 */
function fetch_card_from_scryfall(string $name, string $lang, string $searchMode, ?string $setCode = null): ?array {
    $base = 'https://api.scryfall.com/cards/named?';

    $params = [
        ($searchMode === 'fuzzy' ? 'fuzzy' : 'exact') => $name,
        'lang' => $lang,
    ];

    if (!empty($setCode)) {
        $params['set'] = strtolower($setCode);
    }

    $url = $base . http_build_query($params);

    return http_get_json($url);
}

/**
 * Cuenta reimpresiones usando prints_search_uri.
 */
function count_reprints(array $card): ?int {
    if (empty($card['prints_search_uri'])) return null;

    $data = http_get_json($card['prints_search_uri']);
    return $data['total_cards'] ?? null;
}

/**
 * Devuelve URL de imagen frontal de la carta.
 */
function get_front_image_url(array $card): ?string {
    if (isset($card['image_uris'])) {
        $u = $card['image_uris'];
        return $u['large'] ?? $u['normal'] ?? $u['png'] ?? null;
    }

    if (isset($card['card_faces'][0]['image_uris'])) {
        $u = $card['card_faces'][0]['image_uris'];
        return $u['large'] ?? $u['normal'] ?? $u['png'] ?? null;
    }

    return null;
}

/**
 * Clase de borde según color_identity para cartas de texto.
 */
function get_color_border_class(array $card): string {
    $colors = $card['color_identity'] ?? [];

    if (!is_array($colors)) $colors = [];

    if (count($colors) === 1) {
        return 'border-' . strtoupper($colors[0]);
    } elseif (count($colors) > 1) {
        return 'border-M'; // multicolor
    } else {
        return 'border-C'; // incoloro
    }
}

/**
 * Reemplaza {W}, {U}, {1}, etc. por imágenes SVG de Scryfall.
 */
function render_mana_symbols(string $text): string {
    return preg_replace_callback('/\{([A-Za-z0-9\/]+)\}/', function ($m) {
        $sym = $m[1];
        $url = 'https://svgs.scryfall.io/card-symbols/' . $sym . '.svg';
        return '<img src="' . $url . '" alt="{' . htmlspecialchars($sym) . '}">';
    }, $text);
}

/**
 * Construye HTML de una carta de texto con marco de color y símbolos de maná.
 */
function build_text_card_html(array $card): string {
    $borderClass = get_color_border_class($card);

    $name      = htmlspecialchars($card['name'] ?? '');
    $manaCost  = render_mana_symbols($card['mana_cost'] ?? '');
    $typeLine  = htmlspecialchars($card['type_line'] ?? '');
    $oracleTxt = render_mana_symbols($card['oracle_text'] ?? '');

    return '
<div class="card-container">
  <div class="card-text ' . $borderClass . '">
    <div class="mana">' . $manaCost . '</div>
    <div class="title">' . $name . '</div>
    <div class="type">' . $typeLine . '</div>
    <div class="oracle">' . $oracleTxt . '</div>
  </div>
</div>';
}

/**
 * Construye HTML de una carta de imagen (frontal o dorso).
 */
function build_image_card_html(string $imagePath): string {
    return '
<div class="card-container">
  <img class="card-img" src="file://' . $imagePath . '" alt="card">
</div>';
}

/**
 * Construye el HTML de páginas (div.page) con 9 cartas por página.
 */
function build_grid_pages_html(array $cardsHtml): string {
    if (empty($cardsHtml)) return '';

    $html = '<div class="page">';
    $count = 0;

    foreach ($cardsHtml as $cardHtml) {
        $html .= $cardHtml;
        $count++;
        if ($count % 9 === 0) {
            $html .= '</div><div class="page">';
        }
    }

    $html .= '</div>';

    return $html;
}

/**
 * Genera un PDF A4 con grid 3x3 a partir de HTML de cartas.
 */
function generate_grid_pdf(array $cardsHtml, string $outputPath): void {
    if (empty($cardsHtml)) return;

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);

    $pagesHtml = build_grid_pages_html($cardsHtml);

    $html = <<<HTML
<html>
<head>
  <meta charset="UTF-8">
  <style>
    @page {
        size: A4 portrait;
        margin: 0;
    }

    body {
        margin: 0;
        padding: 0;
    }

    .page {
        width: 210mm;
        height: 297mm;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-content: space-between;
        padding: 10mm;
        page-break-after: always;
    }

    .card-container {
        position: relative;
        width: 67mm;
        height: 92mm;
        overflow: hidden;
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
$pagesHtml
</body>
</html>
HTML;

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($outputPath, $dompdf->output());
}
?>
