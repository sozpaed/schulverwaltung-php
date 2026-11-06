const qr = document.querySelector('[data-totp-uri]');

if (qr instanceof HTMLElement) {
    new QRCode(qr, {
        text: qr.dataset.totpUri,
        width: 256,
        height: 256,
        correctLevel: QRCode.CorrectLevel.M,
    });
}
