/*
 * Reordenar los horarios de una jornada (panel "Ordenar horarios" en admin/partidos.php).
 *
 * Solo maneja el ORDEN en pantalla y muestra cómo quedarían las horas. Lo que se manda al
 * servidor es la lista de ids por día; el servidor recalcula las horas y valida todo, así
 * que nada de lo que se haga aquí puede dejar un horario inválido guardado.
 *
 * Con SortableJS se arrastra (también entre el sábado y el domingo de la jornada). Si el
 * CDN no carga, las flechas ↑ ↓ siguen funcionando: al llegar arriba del todo, la flecha
 * pasa el encuentro al final del día anterior, y abajo del todo, al inicio del siguiente.
 */
(function () {
    'use strict';

    function aMinutos(hora) {
        var p = String(hora || '').split(':');
        var h = parseInt(p[0], 10), m = parseInt(p[1], 10);
        return (isNaN(h) || isNaN(m)) ? 540 : h * 60 + m;
    }

    function aHora(min) {
        min = Math.min(min, 23 * 60 + 59);
        var h = Math.floor(min / 60), m = min % 60;
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }

    function iniciar(form) {
        var listas = Array.prototype.slice.call(form.querySelectorAll('.lista-orden:not(.bloqueada)'));
        var guardar = form.querySelector('[data-guardar-orden]');
        var deshacer = form.querySelector('[data-deshacer-orden]');
        var aviso = form.querySelector('[data-aviso-choque]');

        // Foto del orden original, para "Deshacer" y para saber si algo cambió.
        var original = listas.map(function (ul) {
            return Array.prototype.slice.call(ul.children);
        });
        var firma = function () {
            return listas.map(function (ul) {
                return Array.prototype.map.call(ul.children, function (li) { return li.getAttribute('data-id'); }).join(',');
            }).join('|');
        };
        var firmaOriginal = firma();

        function actualizar() {
            var hayChoque = false;
            listas.forEach(function (ul) {
                var inicio = aMinutos(ul.getAttribute('data-inicio'));
                var intervalo = parseInt(ul.getAttribute('data-intervalo'), 10) || 60;
                var simultaneos = parseInt(ul.getAttribute('data-simultaneos'), 10) || 1;
                var vistos = {};
                var items = Array.prototype.slice.call(ul.children);
                items.forEach(function (li) {
                    (li.getAttribute('data-equipos') || '').split(',').forEach(function (eq) {
                        vistos[eq] = (vistos[eq] || 0) + 1;
                    });
                });
                items.forEach(function (li, i) {
                    var hora = li.querySelector('.hora-orden');
                    if (hora) {
                        hora.textContent = aHora(inicio + Math.floor(i / simultaneos) * intervalo);
                    }
                    var choca = (li.getAttribute('data-equipos') || '').split(',').some(function (eq) {
                        return vistos[eq] > 1;
                    });
                    li.classList.toggle('list-group-item-danger', choca);
                    hayChoque = hayChoque || choca;
                });
            });
            var cambio = firma() !== firmaOriginal;
            aviso.classList.toggle('d-none', !hayChoque);
            guardar.disabled = !cambio || hayChoque;
            deshacer.disabled = !cambio;
        }

        form.addEventListener('click', function (ev) {
            var boton = ev.target.closest('[data-mover]');
            if (!boton) {
                return;
            }
            var li = boton.closest('li');
            var ul = li.parentNode;
            var pos = listas.indexOf(ul);
            if (boton.getAttribute('data-mover') === 'arriba') {
                if (li.previousElementSibling) {
                    ul.insertBefore(li, li.previousElementSibling);
                } else if (pos > 0) {
                    listas[pos - 1].appendChild(li);
                }
            } else {
                if (li.nextElementSibling) {
                    ul.insertBefore(li.nextElementSibling, li);
                } else if (pos < listas.length - 1) {
                    listas[pos + 1].insertBefore(li, listas[pos + 1].firstChild);
                }
            }
            boton.focus();
            actualizar();
        });

        deshacer.addEventListener('click', function () {
            listas.forEach(function (ul, i) {
                original[i].forEach(function (li) { ul.appendChild(li); });
            });
            actualizar();
        });

        // Al enviar: un campo orden[fecha][] por encuentro, en el orden que quedó.
        form.addEventListener('submit', function () {
            Array.prototype.forEach.call(form.querySelectorAll('input[data-orden]'), function (i) { i.remove(); });
            listas.forEach(function (ul) {
                Array.prototype.forEach.call(ul.children, function (li) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'orden[' + ul.getAttribute('data-fecha') + '][]';
                    input.value = li.getAttribute('data-id');
                    input.setAttribute('data-orden', '1');
                    form.appendChild(input);
                });
            });
        });

        if (window.Sortable) {
            var grupo = 'jornada-' + (form.querySelector('input[name="jornada"]') || {}).value;
            listas.forEach(function (ul) {
                window.Sortable.create(ul, {
                    group: grupo,
                    handle: '.asa-orden',
                    animation: 150,
                    // Arrastre con eventos de puntero (igual en mouse y en pantalla táctil) en vez
                    // del drag & drop nativo del navegador, que en móvil no responde bien.
                    forceFallback: true,
                    onSort: actualizar
                });
            });
        }

        actualizar();
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-ordenar-jornada]'), iniciar);
    });
})();
