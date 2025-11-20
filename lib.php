<?php
// =======================================================
// ===============  MTG PROXY GENERATOR  =================
// ==========   Versión con POSICIÓN ABSOLUTA   ==========
// =======================================================


/* -------------------------------------------------------
   HTTP GET JSON helper
------------------------------------------------------- */
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
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status !== 200) return null;

    $json = json_decode($response, true);
    return is_array($json) ? $json : null;
}


/* -------------------------------------------------------
   Asegura existencia de carpeta
------------------------------------------------------- */
function ensure_dir(string $path): void {
    if (!file_exists($path)) mkdir($path, 0777, true);
}


/* -------------------------------------------------------
   Limpia nombre de archivo
------------------------------------------------------- */
function clean_filename(string $name): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
}


/* -------------------------------------------------------
   Lee líneas con cantidad opcional
------------------------------------------------------- */
function parse_card_line_with_qty(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;

    // Formatos soportados
    $patterns = [
        '/^(\d+)\s+(.+)$/u',          // 4 Lightning Bolt
        '/^(.+?)\s*[xX]\s*(\d+)$/u',  // Lightning Bolt x4
        '/^(\d+)\s*,\s*(.+)$/u',      // 4,Lightning Bolt
        '/^(.+)\s*,\s*(\d+)$/u',      // Lightning Bolt,4
        '/^(.+?)\s+(\d+)$/u',         // Lightning Bolt 4
        '/^(.+?)\s*\((\d+)\)\s*$/u'   // Lightning Bolt (4)
    ];

    foreach ($patterns as $p) {
        if (preg_match($p, $line, $m)) {
            return [
                'name' => trim($m[ count($m)==3 ? 2 : 1 ]),
                'qty'  => max(1, (int)$m[ count($m)==3 ? 1 : 2 ]),
            ];
        }
    }

    return ['name' => $line, 'qty' => 1];
}


/* -------------------------------------------------------
   Lee textarea y archivo TXT/CSV
------------------------------------------------------- */
function get_card_list_from_request(): array {
    $map = [];

    $consume = function(string $line) use (&$map) {
        $parsed = parse_card_line_with_qty($line);
        if (!$parsed) return;
        if (!isset($map[$parsed['name']])) $map[$parsed['name']] = 0;
        $map[$parsed['name']] += $parsed['qty'];
    };

    // textarea
    if (!empty($_POST['cards'])) {
        foreach (preg_split('/\r\n|\r|\n/', trim($_POST['cards'])) as $line)
            if (trim($line) !== '') $consume($line);
    }

    // archivo
    if (!empty($_FILES['cards_file']['tmp_name'])) {
        $content = file_get_contents($_FILES['cards_file']['tmp_name']);
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $consume($line);
        }
    }

    $final = [];
    foreach ($map as $name => $qty) $final[] = ['name'=>$name,'qty'=>$qty];
    return $final;
}


/* -------------------------------------------------------
   API Scryfall
------------------------------------------------------- */
function fetch_card_from_scryfall(string $name, string $lang, string $searchMode, ?string $setCode=null): ?array {
    $base = 'https://api.scryfall.com/cards/named?';
    $params = [ ($searchMode==='fuzzy'?'fuzzy':'exact') => $name, 'lang'=>$lang ];
    if ($setCode) $params['set'] = strtolower($setCode);

    return http_get_json($base . http_build_query($params));
}


/* -------------------------------------------------------
   Imagen frontal
------------------------------------------------------- */
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


/* -------------------------------------------------------
   Renderizar {R}, {1}, {G/U} → texto plano tipo Deckstats
------------------------------------------------------- */
function render_mana_symbols(string $text): string {
    return preg_replace_callback('/\{([^}]+)\}/', function ($m) {
        return strtoupper(str_replace('/', '', $m[1])); // {2/R} → 2R
    }, $text);
}


/* -------------------------------------------------------
   Modo TEXTO estilo Deckstats
------------------------------------------------------- */
function build_text_card_html(array $card): string {

    $name        = htmlspecialchars($card['name'] ?? '');
    $typeLine    = htmlspecialchars($card['type_line'] ?? '');
    $oracleText  = nl2br(htmlspecialchars($card['oracle_text'] ?? ''));
    $power       = htmlspecialchars($card['power'] ?? '');
    $toughness   = htmlspecialchars($card['toughness'] ?? '');
    $collector   = htmlspecialchars($card['collector_number'] ?? '');
    $set         = strtoupper(htmlspecialchars($card['set'] ?? ''));
    
    // MANÁ COSTE como texto plano tipo “3R”
    $manaCostRaw = $card['mana_cost'] ?? '';
    $manaCostAscii = preg_replace_callback('/\{([^}]+)\}/', function($m){
        return strtoupper(str_replace('/', '', $m[1])); 
    }, $manaCostRaw);

    // FOOTER estilo Deckstats
    $footerLeft  = "$set #$collector";

    // P/T
    $pt = ($power !== '' && $toughness !== '') ? "$power / $toughness" : "--";

    return "
<div class='card-text'>
    
    <div class='txt-header'>
        <div class='txt-title'>$name</div>
        <div class='txt-mana'>$manaCostAscii</div>
    </div>

    <div class='txt-type'>$typeLine</div>

    <div class='txt-oracle'>$oracleText</div>

    <div class='txt-footer'>
        <span class='footer-left'>$footerLeft</span>
        <span class='footer-pt'>$pt</span>
    </div>

</div>";
}



/* -------------------------------------------------------
   Modo IMAGEN
------------------------------------------------------- */
function build_image_card_html(string $src): string {
    return "
<div class='card-container'>
    <img class='card-img' src='$src'>
</div>";
}


/* -------------------------------------------------------
   NUEVO Sistema 3×3 con posición ABSOLUTA
------------------------------------------------------- */
function build_absolute_pages_html(array $cardsHtml): string {
    if (empty($cardsHtml)) return '';

    // coordenadas 3×3
    $startLeft = 5;
    $startTop  = 5;
    $cardW     = 67;
    $cardH     = 91.5;
    $gap       = 2;

    $positions = [];
    for ($r=0;$r<3;$r++) {
        for ($c=0;$c<3;$c++) {
            $left = $startLeft + ($cardW + $gap) * $c;
            $top  = $startTop  + ($cardH + $gap) * $r;
            $positions[] = ['top'=>$top, 'left'=>$left];
        }
    }

    $html = '';
    $total = count($cardsHtml);
    $i = 0;

    while ($i < $total) {
        $html .= "<div class='page'>";

        for ($slot=0; $slot<9; $slot++) {
            if ($i >= $total) break;

            $p = $positions[$slot];
            $html .= "
<div class='abs-card' style='top: {$p['top']}mm; left: {$p['left']}mm;'>
{$cardsHtml[$i]}
</div>";

            $i++;
        }

        $html .= "</div>";
    }

    return $html;
}


/* -------------------------------------------------------
   Generar PDF
------------------------------------------------------- */
function generate_grid_pdf(array $cardsHtml, string $outputPath): void {
    if (empty($cardsHtml)) return;

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);

    $pagesHtml = build_absolute_pages_html($cardsHtml);

    // CSS FINAL SIN TABLAS
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
    position: relative;
    page-break-after: auto;
}

/* ======== CARTAS POSICIÓN ABSOLUTA ======== */
.abs-card {
    position: absolute;
    width: 67mm;
    height: 92mm;
}

/* ======== CARTA TEXTO ======== */
.card-text {
    width: 67mm;
    height: 92mm;
    padding: 3mm;
    box-sizing: border-box;
    border: 0.4mm solid black;
    background: white;
    font-family: DejaVu Serif, serif;
    position: relative;
    overflow: hidden;
}

/* ENCABEZADO: Título + Coste de maná */
.txt-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1mm;
}

.txt-mana {
    font-size: 10pt;
    font-weight: bold;
    text-align: right;
    white-space: nowrap;
}

.txt-title {
    font-size: 11pt;
    font-weight: bold;
}

/* Tipo */
.txt-type {
    font-style: italic;
    font-size: 9pt;
    margin-bottom: 2mm;
}

/* Oracle */
.txt-oracle {
    font-size: 8.5pt;
    line-height: 1.2;
    white-space: pre-wrap;
}

/* Footer */
.txt-footer {
    position: absolute;
    bottom: 1.5mm;
    left: 2mm;
    right: 2mm;
    height: 6mm;
    font-size: 7pt;
    line-height: 1;
}

.footer-left {
    position: absolute;
    left: 0;
    white-space: nowrap;
}

.footer-pt {
    position: absolute;
    right: 0;
    bottom: 0;
    font-size: 10pt;
    font-weight: bold;
    white-space: nowrap;
}


/* ======== CARTA IMAGEN ======== */
.card-container {
    width: 100%;
    height: 100%;
    position: relative;
}

.card-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* SOLO IMAGEN: CROP MARKS */
.card-container::before,
.card-container::after {
    content: "";
    position: absolute;
    width: 8mm;
    height: 8mm;
    z-index: 20;
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

</style>
</head>
<body>
$pagesHtml
</body>
</html>
HTML;

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','portrait');
    $dompdf->render();

    file_put_contents($outputPath, $dompdf->output());
}

?>