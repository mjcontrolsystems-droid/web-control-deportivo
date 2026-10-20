// Hoja de carnets: dibuja el QR de cada carnet y maneja el botón de imprimir. Va en un
// archivo aparte (y no en el HTML) porque el CSP del sitio no permite JavaScript en línea.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-qr]').forEach(function (caja) {
        if (!window.QRCode) {
            return;
        }
        new QRCode(caja, {
            text: caja.getAttribute('data-qr'),
            width: 128,
            height: 128,
            colorDark: '#111111',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });
    });
    var boton = document.querySelector('[data-imprimir-carnets]');
    if (boton) {
        boton.addEventListener('click', function () { window.print(); });
    }
});
