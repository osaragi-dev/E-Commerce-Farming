const key = 'farming.printer.v1';
const methods = ['browser', 'qz', 'rawbt'];

export function loadPreferences(storage, defaultPrinter = '') {
    let saved;
    try { saved = JSON.parse(storage.getItem(key)); } catch { saved = null; }
    return {
        method: methods.includes(saved?.method) ? saved.method : 'browser',
        printerName: typeof saved?.printerName === 'string' ? saved.printerName : defaultPrinter,
    };
}

export function savePreferences(storage, settings) {
    const printerName = settings.printerName.trim();
    if (!methods.includes(settings.method)) throw new Error('Choose a valid print method.');
    if (settings.method === 'qz' && (!printerName || printerName.length > 150 || /[\x00-\x1f\x7f]/.test(printerName))) {
        throw new Error('Enter a valid QZ printer name, up to 150 characters.');
    }
    storage.setItem(key, JSON.stringify({ method: settings.method, printerName }));
}

export function rawbtUri(base64) {
    if (typeof base64 !== 'string' || !/^[A-Za-z0-9+/]+={0,2}$/.test(base64)) {
        throw new Error('The receipt data is invalid.');
    }
    return `rawbt:base64,${base64}`;
}
