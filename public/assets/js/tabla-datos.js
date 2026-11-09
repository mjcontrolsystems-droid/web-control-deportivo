/*
 * Tabla de datos: búsqueda, cuántas filas ver, orden por columna y paginación.
 *
 * Se activa poniéndole data-tabla-datos a cualquier <table> del panel. Sin librerías
 * (DataTables pesa más que todo nuestro JS junto) y sin JavaScript inline, que el CSP
 * no permite.
 *
 *   <table data-tabla-datos data-por-pagina="10">
 *     <th data-ordenable>Columna</th>          ← clic para ordenar
 *     <td data-orden="2026-11-09">09/11/2026</td> ← valor para ordenar (opcional)
 *
 * La búsqueda ignora acentos y mayúsculas ("lopez" encuentra "López").
 */
(function () {
    'use strict';

    var OPCIONES_POR_PAGINA = [10, 25, 50, 100, 0]; // 0 = todos

    function normalizar(texto) {
        return (texto || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    function valorOrden(celda) {
        if (!celda) { return ''; }
        var v = celda.hasAttribute('data-orden') ? celda.getAttribute('data-orden') : celda.textContent;
        return normalizar(v);
    }

    function comparar(a, b) {
        var na = parseFloat(a), nb = parseFloat(b);
        var sonNumeros = a !== '' && b !== '' && !isNaN(na) && !isNaN(nb) && /^-?[\d.]+$/.test(a) && /^-?[\d.]+$/.test(b);
        if (sonNumeros) { return na - nb; }
        // Vacíos siempre al final, sin importar el sentido.
        if (a === '' && b !== '') { return 1; }
        if (b === '' && a !== '') { return -1; }
        return a.localeCompare(b, 'es');
    }

    function crear(etiqueta, clase, texto) {
        var el = document.createElement(etiqueta);
        if (clase) { el.className = clase; }
        if (texto !== undefined) { el.textContent = texto; }
        return el;
    }

    function iniciar(tabla) {
        var tbody = tabla.tBodies[0];
        // Nunca dos veces la misma tabla: duplicaría la barra y la paginación.
        if (!tbody || tabla.hasAttribute('data-tabla-lista')) { return; }
        tabla.setAttribute('data-tabla-lista', '');
        var filas = Array.prototype.slice.call(tbody.rows);
        var porPagina = parseInt(tabla.getAttribute('data-por-pagina') || '10', 10);
        if (OPCIONES_POR_PAGINA.indexOf(porPagina) === -1) { porPagina = 10; }
        var pagina = 1;
        var busqueda = '';
        var orden = { col: -1, asc: true };
        var textoFila = filas.map(function (f) { return normalizar(f.textContent); });

        // --- Controles de arriba: cuántos ver + buscar ---
        var contenedor = tabla.closest('.table-responsive') || tabla;
        var barra = crear('div', 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 tabla-datos-barra');
        var izq = crear('label', 'd-flex align-items-center gap-2 small text-muted mb-0');
        izq.appendChild(document.createTextNode('Mostrar'));
        var selector = crear('select', 'form-select form-select-sm');
        selector.style.width = 'auto';
        OPCIONES_POR_PAGINA.forEach(function (n) {
            var op = crear('option', '', n === 0 ? 'Todos' : String(n));
            op.value = String(n);
            if (n === porPagina) { op.selected = true; }
            selector.appendChild(op);
        });
        izq.appendChild(selector);
        izq.appendChild(document.createTextNode('registros'));

        var buscar = crear('input', 'form-control form-control-sm');
        buscar.type = 'search';
        buscar.placeholder = 'Buscar…';
        buscar.style.maxWidth = '260px';
        buscar.setAttribute('aria-label', 'Buscar en la tabla');

        barra.appendChild(izq);
        barra.appendChild(buscar);
        contenedor.parentNode.insertBefore(barra, contenedor);

        // --- Pie: "Mostrando X a Y de Z" + paginación ---
        var pie = crear('div', 'd-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 tabla-datos-pie');
        var info = crear('div', 'small text-muted');
        var nav = crear('ul', 'pagination pagination-sm mb-0');
        pie.appendChild(info);
        pie.appendChild(nav);
        contenedor.parentNode.insertBefore(pie, contenedor.nextSibling);

        // Fila que se muestra cuando la búsqueda no encuentra nada.
        var columnas = tabla.tHead ? tabla.tHead.rows[0].cells.length : 1;
        var filaVacia = crear('tr', 'tabla-datos-vacia');
        var celdaVacia = crear('td', 'text-center text-muted py-4', 'No se encontró nada con esa búsqueda.');
        celdaVacia.colSpan = columnas;
        filaVacia.appendChild(celdaVacia);

        // --- Orden por columna ---
        var encabezados = tabla.tHead ? Array.prototype.slice.call(tabla.tHead.rows[0].cells) : [];
        encabezados.forEach(function (th, indice) {
            if (!th.hasAttribute('data-ordenable')) { return; }
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.setAttribute('role', 'button');
            th.title = 'Ordenar';
            var icono = crear('i', 'bi bi-arrow-down-up ms-1 opacity-50 tabla-datos-icono');
            th.appendChild(icono);
            th.addEventListener('click', function () {
                orden.asc = orden.col === indice ? !orden.asc : true;
                orden.col = indice;
                encabezados.forEach(function (otro) {
                    var i = otro.querySelector('.tabla-datos-icono');
                    if (i) { i.className = 'bi bi-arrow-down-up ms-1 opacity-50 tabla-datos-icono'; }
                });
                icono.className = 'bi ' + (orden.asc ? 'bi-sort-down-alt' : 'bi-sort-up') + ' ms-1 tabla-datos-icono';
                pagina = 1;
                pintar();
            });
        });

        function visibles() {
            var lista = filas.filter(function (f, i) {
                return busqueda === '' || textoFila[i].indexOf(busqueda) !== -1;
            });
            if (orden.col >= 0) {
                lista.sort(function (a, b) {
                    var r = comparar(valorOrden(a.cells[orden.col]), valorOrden(b.cells[orden.col]));
                    return orden.asc ? r : -r;
                });
            }
            return lista;
        }

        function botonPagina(texto, destino, activo, deshabilitado) {
            var li = crear('li', 'page-item' + (activo ? ' active' : '') + (deshabilitado ? ' disabled' : ''));
            var a = crear('button', 'page-link', texto);
            a.type = 'button';
            if (!deshabilitado && !activo) {
                a.addEventListener('click', function () { pagina = destino; pintar(); });
            }
            li.appendChild(a);
            nav.appendChild(li);
        }

        function pintar() {
            var lista = visibles();
            var total = lista.length;
            var tam = porPagina === 0 ? Math.max(total, 1) : porPagina;
            var paginas = Math.max(1, Math.ceil(total / tam));
            if (pagina > paginas) { pagina = paginas; }
            var inicio = (pagina - 1) * tam;
            var fin = Math.min(inicio + tam, total);

            // Reordena en el DOM (el orden importa) y muestra solo la página actual.
            filas.forEach(function (f) { f.style.display = 'none'; });
            lista.forEach(function (f, i) {
                tbody.appendChild(f);
                f.style.display = i >= inicio && i < fin ? '' : 'none';
            });
            if (total === 0) {
                tbody.appendChild(filaVacia);
            } else if (filaVacia.parentNode) {
                filaVacia.parentNode.removeChild(filaVacia);
            }

            info.textContent = total === 0
                ? 'Sin resultados' + (busqueda ? ' (de ' + filas.length + ' en total)' : '')
                : 'Mostrando ' + (inicio + 1) + ' a ' + fin + ' de ' + total + (total !== filas.length ? ' (filtrado de ' + filas.length + ')' : '');

            nav.innerHTML = '';
            if (paginas > 1) {
                botonPagina('‹', pagina - 1, false, pagina === 1);
                // Ventana de hasta 5 páginas alrededor de la actual.
                var desde = Math.max(1, Math.min(pagina - 2, paginas - 4));
                var hasta = Math.min(paginas, desde + 4);
                for (var p = desde; p <= hasta; p++) {
                    botonPagina(String(p), p, p === pagina, false);
                }
                botonPagina('›', pagina + 1, false, pagina === paginas);
            }
        }

        selector.addEventListener('change', function () {
            porPagina = parseInt(selector.value, 10);
            pagina = 1;
            pintar();
        });
        var espera = null;
        buscar.addEventListener('input', function () {
            clearTimeout(espera);
            espera = setTimeout(function () {
                busqueda = normalizar(buscar.value);
                pagina = 1;
                pintar();
            }, 150);
        });

        pintar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table[data-tabla-datos]').forEach(iniciar);
    });
})();
