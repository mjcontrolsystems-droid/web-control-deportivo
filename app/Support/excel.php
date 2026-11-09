<?php
declare(strict_types=1);

/**
 * Generador de archivos .xlsx, sin librerías.
 *
 * Por qué a mano
 * --------------
 * Un .xlsx es un ZIP con unos cuantos XML adentro. El proyecto ya abre ZIPs para importar
 * plantillas de Excel, así que escribirlos no necesita nada nuevo: ni Composer, ni una
 * dependencia de miles de archivos que hay que mantener, ni el riesgo de que el hosting
 * no traiga la extensión que esa librería pide.
 *
 * Por qué no un CSV
 * -----------------
 * Un CSV parece más simple hasta que lo abres en Excel: los acentos salen rotos si falta
 * el BOM, y el separador correcto depende del idioma de Windows — con coma, un Excel en
 * español mete toda la fila en una sola celda. Además solo permite una hoja. Un .xlsx de
 * verdad se abre igual en cualquier computadora y lleva las cuatro hojas en un archivo.
 *
 * Qué NO hace: fórmulas, colores, anchos de columna, gráficas. Es una exportación de
 * datos para trabajarlos en Excel, no un reporte con diseño.
 */

/**
 * Arma el archivo y devuelve su contenido binario.
 *
 * @param array<string, array<int, array<int, string|int|float|null>>> $hojas
 *        Nombre de la hoja => filas. La primera fila se toma como encabezado (va en negrita).
 * @throws RuntimeException si el servidor no puede escribir ZIPs.
 */
function excel_generar(array $hojas): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Este servidor no puede generar archivos de Excel en este momento.');
    }
    if (empty($hojas)) {
        throw new RuntimeException('No hay nada que exportar.');
    }

    $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($ruta === false) {
        throw new RuntimeException('No se pudo preparar el archivo. Intenta de nuevo.');
    }

    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($ruta);
        throw new RuntimeException('No se pudo preparar el archivo. Intenta de nuevo.');
    }

    $nombres = array_keys($hojas);
    $total = count($nombres);

    // --- Piezas fijas del paquete ---
    $tiposHojas = '';
    foreach (range(1, $total) as $i) {
        $tiposHojas .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $tiposHojas
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>'
    );

    // Dos fuentes: normal y negrita para el encabezado. Excel exige que existan al menos
    // dos rellenos aunque no se usen; sin eso se queja de archivo dañado.
    //
    // Estilos de celda (el índice es el s="N" de cada celda, ver EXCEL_ESTILOS):
    //   0 normal · 1 negrita · 2 fecha dd/mm/aaaa · 3 dinero 1,234.50 · 4 dinero en negrita
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>'
    );

    // --- Libro y relaciones ---
    $listaHojas = '';
    $relaciones = '';
    $usados = [];
    foreach ($nombres as $i => $nombre) {
        $n = $i + 1;
        $limpio = excel_nombre_hoja((string) $nombre, $usados);
        $listaHojas .= '<sheet name="' . excel_xml($limpio) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $relaciones .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $relaciones .= '<Relationship Id="rId' . ($total + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $listaHojas . '</sheets>'
        . '</workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $relaciones
        . '</Relationships>'
    );

    // --- Las hojas ---
    $indice = 0;
    foreach ($hojas as $filas) {
        $indice++;
        $zip->addFromString('xl/worksheets/sheet' . $indice . '.xml', excel_hoja_xml((array) $filas));
    }

    $zip->close();

    $contenido = file_get_contents($ruta);
    @unlink($ruta);

    if ($contenido === false) {
        throw new RuntimeException('No se pudo leer el archivo generado. Intenta de nuevo.');
    }

    return $contenido;
}

/**
 * Manda el archivo al navegador como descarga y termina la petición.
 */
function excel_descargar(string $contenido, string $nombreArchivo): void
{
    // El nombre viaja en una cabecera: los acentos y las comillas romperían la respuesta.
    $seguro = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombreArchivo) ?: 'export.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $seguro . '"');
    header('Content-Length: ' . strlen($contenido));
    header('Cache-Control: no-store');
    echo $contenido;
    exit;
}

/**
 * Nombre de hoja aceptable para Excel: hasta 31 caracteres, sin : \ / ? * [ ], y único
 * dentro del libro (dos hojas con el mismo nombre abren un archivo corrupto).
 *
 * @param array<string,bool> $usados Se actualiza por referencia con los nombres ya dados.
 */
function excel_nombre_hoja(string $nombre, array &$usados): string
{
    $limpio = trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $nombre));
    $limpio = mb_substr($limpio !== '' ? $limpio : 'Hoja', 0, 31);

    $base = $limpio;
    $n = 2;
    while (isset($usados[mb_strtolower($limpio)])) {
        $sufijo = ' ' . $n++;
        $limpio = mb_substr($base, 0, 31 - mb_strlen($sufijo)) . $sufijo;
    }
    $usados[mb_strtolower($limpio)] = true;

    return $limpio;
}

function excel_xml(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * El XML de una hoja. La primera fila va en negrita (estilo 1).
 *
 * Los textos van "en línea" (inlineStr) en vez de en la tabla compartida de cadenas: el
 * archivo pesa un poco más, pero se arma de un solo paso y sin llevar un diccionario
 * global, que es de donde salen la mayoría de los xlsx corruptos escritos a mano.
 */
const EXCEL_ESTILOS = ['normal' => 0, 'negrita' => 1, 'fecha' => 2, 'dinero' => 3, 'dinero_negrita' => 4];

/**
 * Celda con formato, para pasarla en una fila en lugar del valor suelto:
 *   excel_celda('2026-11-09', 'fecha')  → fecha real de Excel que se ve como 09/11/2026
 *   excel_celda(75.5, 'dinero')         → número con formato 75.50 (se puede sumar)
 *   excel_celda('TOTAL', 'negrita')
 */
function excel_celda(mixed $valor, string $estilo): array
{
    return ['__excel' => true, 'v' => $valor, 'estilo' => $estilo];
}

/**
 * Número de serie de Excel para una fecha Y-m-d (días desde el 30/12/1899), o null si
 * no es una fecha válida.
 */
function excel_serial_fecha(string $fecha): ?int
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($fecha, 0, 10), new DateTimeZone('UTC'));
    if ($d === false) {
        return null;
    }
    $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
    return (int) $base->diff($d)->format('%r%a');
}

function excel_hoja_xml(array $filas): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

    foreach (array_values($filas) as $i => $fila) {
        $numeroFila = $i + 1;
        $estilo = $i === 0 ? ' s="1"' : '';
        $xml .= '<row r="' . $numeroFila . '">';

        foreach (array_values((array) $fila) as $j => $valor) {
            $ref = excel_columna($j) . $numeroFila;

            // Celda con formato (excel_celda): fecha real, dinero o negrita.
            if (is_array($valor) && !empty($valor['__excel'])) {
                $tipo = (string) $valor['estilo'];
                $valor = $valor['v'];
                if ($valor === null || $valor === '') {
                    continue;
                }
                if ($tipo === 'fecha' && ($serial = excel_serial_fecha((string) $valor)) !== null) {
                    $xml .= '<c r="' . $ref . '" s="' . EXCEL_ESTILOS['fecha'] . '"><v>' . $serial . '</v></c>';
                    continue;
                }
                $s = ' s="' . (EXCEL_ESTILOS[$tipo] ?? 0) . '"';
                if (is_int($valor) || is_float($valor)) {
                    $xml .= '<c r="' . $ref . '"' . $s . '><v>' . $valor . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">'
                        . excel_xml((string) $valor) . '</t></is></c>';
                }
                continue;
            }

            if ($valor === null || $valor === '') {
                continue;   // una celda vacía simplemente no se escribe
            }

            // Los números se guardan como números para poder sumarlos en Excel. Ojo: un
            // dorsal como "07" o un código son texto — se detecta con is_numeric sobre el
            // valor ORIGINAL, no sobre su versión en texto, así el tipo lo decide quien
            // arma la fila y no una coincidencia de formato.
            if (is_int($valor) || is_float($valor)) {
                $xml .= '<c r="' . $ref . '"' . $estilo . '><v>' . $valor . '</v></c>';
                continue;
            }

            $xml .= '<c r="' . $ref . '" t="inlineStr"' . $estilo . '><is><t xml:space="preserve">'
                . excel_xml((string) $valor) . '</t></is></c>';
        }

        $xml .= '</row>';
    }

    return $xml . '</sheetData></worksheet>';
}

/**
 * Índice de columna a letra: 0 = A, 25 = Z, 26 = AA.
 */
function excel_columna(int $indice): string
{
    $letras = '';
    $n = $indice + 1;
    while ($n > 0) {
        $resto = ($n - 1) % 26;
        $letras = chr(65 + $resto) . $letras;
        $n = intdiv($n - 1 - $resto, 26);
    }

    return $letras;
}
