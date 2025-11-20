<?php
// generate.php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$language   = $_POST['language']    ?? 'es';
$searchMode = $_POST['search_mode'] ?? 'exact';

$pdfFrontImg  = isset($_POST['pdf_front_img']);
$pdfFrontText = isset($_POST['pdf_front_text']);
$pdfBack      = isset($_POST['pdf_back']);
$createZip    = isset($_POST['create_zip']);

$cards = get_card_list_from_request();

if (empty($cards)) {
    die("No se ha proporcionado ninguna carta. <a href='index.php'>Volver</a>");
}

$baseOutput = __DIR__ . '/output';
$textDir    = $baseOutput . '/proxies_text';
$imgDir     = $baseOutput . '/proxies_img';
$pdfDir     = $baseOutput . '/pdf';

ensure_dir($baseOutput);
ensure_dir($textDir);
ensure_dir($imgDir);
ensure_dir($pdfDir);

$results        = [];
$imageCardsHtml = [];
$textCardsHtml  = [];

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

    $cardName   = $card['name'] ?? $rawName;
    $fileStub   = clean_filename($cardName . ($setCode ? "_$setCode" : ""));
    $manaCost   = $card['mana_cost']   ?? '';
    $typeLine   = $card['type_line']   ?? '';
    $oracleTxt  = $card['oracle_text'] ?? '';
    $langCard   = $card['lang']        ?? '';
    $setCard    = $card['set']         ?? '';
    $reprints   = count_reprints($card);

    $proxyText  = "==============================\n";
    $proxyText .= $cardName . " (" . $manaCost . ")\n";
    $proxyText .= $typeLine . "\n\n";
    $proxyText .= $oracleTxt . "\n\n";
    if ($reprints !== null) {
        $proxyText .= "Reimpresiones conocidas en Scryfall: " . $reprints . "\n";
    }
    $proxyText .= "Idioma: " . strtoupper($language) . "\n";
    $proxyText .= "Cantidad total: " . $qty . "\n";

    file_put_contents("$textDir/$fileStub.txt", $proxyText);

    $imgUrl = get_front_image_url($card);
    $savedImgPath = null;

    // Seguimos descargando la imagen para guardarla en output/proxies_img
    if ($imgUrl) {
        $imgContent = @file_get_contents($imgUrl);
        if ($imgContent !== false) {
            $savedImgPath = "$imgDir/$fileStub.jpg";
            file_put_contents($savedImgPath, $imgContent);
        }
    }

    // Para el PDF usamos SIEMPRE la URL de Scryfall, no el archivo local
    for ($i = 0; $i < $qty; $i++) {
        $textCardsHtml[] = build_text_card_html($card);
        if ($imgUrl) {
            $imageCardsHtml[] = build_image_card_html($imgUrl);
        }
    }

    $results[] = [
        'input'    => $rawName,
        'qty'      => $qty,
        'status'   => 'ok',
        'card'     => $cardName,
        'set'      => $setCard,
        'lang'     => $langCard,
        'reprints' => $reprints,
        'img'      => $savedImgPath ? basename($savedImgPath) : null,
    ];

    usleep(80000);
}

$pdfFrontImgFile  = null;
$pdfFrontTextFile = null;
$pdfBackFile      = null;

if ($pdfFrontImg && !empty($imageCardsHtml)) {
    $pdfFrontImgFile = $pdfDir . '/proxies_front_img_' . time() . '.pdf';
    generate_grid_pdf($imageCardsHtml, $pdfFrontImgFile);
}

if ($pdfFrontText && !empty($textCardsHtml)) {
    $pdfFrontTextFile = $pdfDir . '/proxies_front_text_' . time() . '.pdf';
    generate_grid_pdf($textCardsHtml, $pdfFrontTextFile);
}

$backImagePath = __DIR__ . '/assets/card_back.jpg';
if ($pdfBack && file_exists($backImagePath)) {
    $countBacks = max(1, max(count($imageCardsHtml), count($textCardsHtml)));
    $backCardsHtml = [];
    for ($i = 0; $i < $countBacks; $i++) {
        $backCardsHtml[] = build_image_card_html($backImagePath);
    }
    $pdfBackFile = $pdfDir . '/proxies_back_' . time() . '.pdf';
    generate_grid_pdf($backCardsHtml, $pdfBackFile);
}

$zipPath = null;
if ($createZip) {
    $zipPath = $baseOutput . '/proxies_' . time() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseOutput, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === $zipPath) continue;
            if ($file->isFile()) {
                $relPath = substr($filePath, strlen($baseOutput) + 1);
                $zip->addFile($filePath, $relPath);
            }
        }
        $zip->close();
    } else {
        $zipPath = null;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resultados - Generador de Proxies MTG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<div class="container py-5">
    <h1 class="mb-4">Resultados</h1>

    <a href="index.php" class="btn btn-secondary mb-4">← Volver</a>

    <div class="card bg-secondary mb-4">
        <div class="card-body">
            <h2 class="h4">Cartas procesadas</h2>
            <table class="table table-dark table-striped align-middle mt-3">
                <thead>
                    <tr>
                        <th>Input</th>
                        <th>Cantidad</th>
                        <th>Estado</th>
                        <th>Carta</th>
                        <th>Set</th>
                        <th>Idioma</th>
                        <th>Reprints</th>
                        <th>Imagen</th>
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
                        <td><?= isset($r['reprints']) ? (int)$r['reprints'] : '-' ?></td>
                        <td>
                            <?php if (!empty($r['img'])): ?>
                                <a href="output/proxies_img/<?= rawurlencode($r['img']) ?>" target="_blank" class="btn btn-sm btn-outline-light">
                                    Ver
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="mt-3">
                Proxies de texto en: <code>output/proxies_text</code><br>
                Proxies de imagen en: <code>output/proxies_img</code>
            </p>
        </div>
    </div>

    <div class="card bg-secondary mb-4">
        <div class="card-body">
            <h2 class="h4">Descargas</h2>
            <ul>
                <?php if ($pdfFrontImgFile): ?>
                    <li>PDF frontal (imágenes):
                        <a href="output/pdf/<?= htmlspecialchars(basename($pdfFrontImgFile)) ?>" target="_blank">
                            Descargar
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($pdfFrontTextFile): ?>
                    <li>PDF frontal (texto):
                        <a href="output/pdf/<?= htmlspecialchars(basename($pdfFrontTextFile)) ?>" target="_blank">
                            Descargar
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($pdfBackFile): ?>
                    <li>PDF dorso:
                        <a href="output/pdf/<?= htmlspecialchars(basename($pdfBackFile)) ?>" target="_blank">
                            Descargar
                        </a>
                    </li>
                <?php endif; ?>
                <?php if ($zipPath): ?>
                    <li>ZIP completo:
                        <a href="output/<?= htmlspecialchars(basename($zipPath)) ?>" download>
                            Descargar ZIP
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
</body>
</html>
