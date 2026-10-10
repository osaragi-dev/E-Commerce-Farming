import qz from 'qz-tray';
import { loadPreferences, savePreferences, rawbtUri } from './printer-preferences.js';

let printing = false;
const controls = () => document.querySelector('[data-printer-controls]');
const preferences = () => loadPreferences(localStorage, controls()?.dataset.defaultPrinter || '');
const status = (message) => {
    const element = document.querySelector('[data-print-status]');
    if (element) element.textContent = message;
};

async function request(url, method = 'GET', data = undefined, html = false) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: html ? 'text/html' : 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: data === undefined ? undefined : JSON.stringify(data),
    });
    if (response.redirected) throw new Error('Your session has expired. Refresh the page and sign in again.');
    if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        throw new Error(result.message || 'Unable to authorize this receipt. Refresh the page and try again.');
    }
    return html ? response.text() : response.json();
}

async function browserPrint(url) {
    const target = new URL(url, window.location.origin);
    target.searchParams.set('embedded', '1');
    const html = await request(target, 'GET', undefined, true);
    document.getElementById('receipt-print-frame')?.remove();
    const frame = document.createElement('iframe');
    frame.id = 'receipt-print-frame';
    frame.title = 'Receipt print document';
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:1px;border:0';
    await new Promise((resolve, reject) => {
        frame.onload = () => {
            try {
                frame.contentWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
                frame.contentWindow.focus();
                frame.contentWindow.print();
                resolve();
            } catch (error) { frame.remove(); reject(error); }
        };
        frame.srcdoc = html;
        document.body.append(frame);
    });
    status('Print dialog opened. Select your printer and 80 mm paper.');
}

async function printReceipt(urls) {
    if (printing) return;
    printing = true;
    document.querySelector('[data-rawbt-link]')?.setAttribute('hidden', '');
    let submitted = false;
    try {
        const settings = preferences();
        status('Preparing receipt...');
        if (settings.method === 'browser') {
            await browserPrint(urls.receiptUrl);
        } else if (settings.method === 'rawbt') {
            if (!/Android/i.test(navigator.userAgent)) throw new Error('RawBT requires an Android device. Choose Browser Dialog or QZ Tray in Printer Settings.');
            const job = await request(urls.rawbtUrl, 'POST');
            const uri = rawbtUri(job.data);
            const link = document.querySelector('[data-rawbt-link]');
            link.href = uri;
            link.hidden = false;
            status('Opening RawBT. If it does not open, tap Open RawBT. The app must be installed and connected to your printer.');
            window.location.assign(uri);
        } else {
            status('Connecting to QZ Tray. Allow Farming if QZ asks for permission.');
            const { certificate } = await request(urls.certificateUrl);
            qz.security.setCertificatePromise((resolve) => resolve(certificate));
            qz.security.setSignatureAlgorithm('SHA512');
            if (!qz.websocket.isActive()) await qz.websocket.connect({ retries: 0 });
            const job = await request(urls.jobUrl, 'POST', { printer_name: settings.printerName });
            const config = qz.configs.create(job.params.printer, job.params.options);
            if (JSON.stringify(config.getOptions()) !== JSON.stringify(job.params.options)) {
                throw new Error('The QZ Tray configuration is incompatible. Please use QZ Tray 2.3 or newer.');
            }
            qz.security.setSignaturePromise((digest) => (resolve, reject) => {
                if (digest !== job.digest) {
                    reject(new Error('The print request does not match the authorized receipt.'));
                    return;
                }
                resolve(job.signature ?? '');
            });
            submitted = true;
            await qz.print(config, job.params.data, [], job.timestamp);
            status(`Sent to ${job.params.printer.name}. Check the printer for your receipt.`);
        }
    } catch (error) {
        const detail = error instanceof Error ? error.message : String(error);
        status(submitted
            ? `Print was not confirmed: ${detail}. Check the print queue before retrying to avoid a duplicate receipt.`
            : `Unable to print: ${detail}`);
    } finally {
        printing = false;
    }
}

function initialize() {
    const form = document.querySelector('[data-printer-settings]');
    if (form && !form.dataset.initialized) {
        form.dataset.initialized = 'true';
        const method = form.elements.method;
        const printer = form.elements.printerName;
        const feedback = form.querySelector('[data-settings-status]');
        try {
            const settings = preferences();
            method.value = settings.method;
            printer.value = settings.printerName;
        } catch (error) { feedback.textContent = 'Browser storage is unavailable. Allow site storage to save printer settings.'; }
        const update = () => {
            form.querySelector('[data-qz-fields]').hidden = method.value !== 'qz';
            printer.required = method.value === 'qz';
            form.querySelector('[data-method-help]').textContent = {
                browser: 'Open the browser print dialog from Payments without a new tab. Choose the printer in the dialog.',
                qz: 'Send directly to the named printer through QZ Tray on this computer. Trusted signing is required for printing without permission prompts.',
                rawbt: 'Open RawBT on Android. Install RawBT and select your printer inside the app. Android may ask permission to open it.',
            }[method.value];
        };
        method.addEventListener('change', update);
        update();
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            try {
                savePreferences(localStorage, { method: method.value, printerName: printer.value });
                feedback.textContent = 'Printer settings saved for this browser.';
            } catch (error) { feedback.textContent = error.message; }
        });
    }
    document.querySelectorAll('[data-print-receipt]').forEach((button) => {
        if (button.dataset.initialized) return;
        button.dataset.initialized = 'true';
        button.addEventListener('click', () => printReceipt(button.dataset));
    });
}

window.addEventListener('farming-print-receipt', (event) => printReceipt(event.detail));
document.addEventListener('livewire:navigated', initialize);
initialize();
