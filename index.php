<?php
// index.php
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generador de Proxies MTG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light">

<div class="container py-5">
    <h1 class="mb-4 text-center">Generador de Proxies MTG</h1>

    <div class="card bg-secondary">
        <div class="card-body">
            <form action="generate.php" method="post" enctype="multipart/form-data">

                <div class="mb-3">
                    <label for="language" class="form-label">Idioma de las cartas (Scryfall)</label>
                    <select name="language" id="language" class="form-select">
                        <option value="es">Español</option>
                        <option value="en">Inglés</option>
                        <option value="fr">Francés</option>
                        <option value="de">Alemán</option>
                        <option value="it">Italiano</option>
                        <option value="pt">Portugués</option>
                        <option value="ja">Japonés</option>
                        <option value="ko">Coreano</option>
                        <option value="ru">Ruso</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Modo de búsqueda</label><br>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="search_mode" id="search_exact" value="exact" checked>
                        <label class="form-check-label" for="search_exact">Exacta</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="search_mode" id="search_fuzzy" value="fuzzy">
                        <label class="form-check-label" for="search_fuzzy">Fuzzy (tolera errores)</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="cards" class="form-label">Lista de cartas (1 por línea)</label>
                    <textarea name="cards" id="cards" rows="8" class="form-control" placeholder="Ejemplos:
4 Lightning Bolt
Lightning Bolt x4
Lightning Bolt,4
4,Lightning Bolt
Lightning Bolt (4)
2 Tarmogoyf|mm3"></textarea>
                    <div class="form-text">
                        Formatos soportados de cantidad:
                        <code>4 Lightning Bolt</code>,
                        <code>Lightning Bolt x4</code>,
                        <code>Lightning Bolt,4</code>,
                        <code>4,Lightning Bolt</code>,
                        <code>Lightning Bolt 4</code>,
                        <code>Lightning Bolt (4)</code>.
                        Versión concreta: <code>Nombre|setcode</code> (ej: <code>Tarmogoyf|mm3</code>).
                    </div>
                </div>

                <div class="mb-3">
                    <label for="cards_file" class="form-label">O cargar lista desde archivo (TXT o CSV)</label>
                    <input type="file" name="cards_file" id="cards_file" class="form-control" accept=".txt,.csv">
                    <div class="form-text">
                        TXT: una carta por línea (valen los formatos de cantidad de arriba).<br>
                        CSV: se intentará interpretar <code>4,Lightning Bolt</code> automáticamente.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">PDFs a generar</label><br>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="pdf_front_img" id="pdf_front_img" checked>
                        <label class="form-check-label" for="pdf_front_img">
                            PDF frontal (imágenes de cartas, 3x3 por hoja)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="pdf_front_text" id="pdf_front_text">
                        <label class="form-check-label" for="pdf_front_text">
                            PDF frontal TEXTUAL (proxies solo texto, 3x3 por hoja)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="pdf_back" id="pdf_back">
                        <label class="form-check-label" for="pdf_back">
                            PDF dorso (usa <code>assets/card_back.jpg</code>)
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="create_zip" id="create_zip" checked>
                        <label class="form-check-label" for="create_zip">
                            Generar ZIP con textos, imágenes y PDFs
                        </label>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary btn-lg">
                        Generar archivos
                    </button>

                    <button type="submit" class="btn btn-info btn-lg"
                            formaction="preview.php">
                        Vista previa (solo pantalla)
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

</body>
</html>
