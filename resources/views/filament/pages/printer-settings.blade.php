<x-filament-panels::page>
    <x-filament::section heading="Receipt printer" description="These settings apply only to this browser on this device. Receipts use 80 mm paper.">
        <form data-printer-settings class="max-w-xl space-y-6">
            <div class="space-y-2">
                <label for="print-method" class="text-sm font-medium">Print method</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="print-method" name="method">
                        <option value="browser">Browser Dialog</option>
                        <option value="qz">QZ Tray</option>
                        <option value="rawbt">RawBT Android</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <p data-method-help class="text-sm text-gray-600 dark:text-gray-300"></p>
            </div>
            <div data-qz-fields class="space-y-2" hidden>
                <label for="printer-name" class="text-sm font-medium">QZ printer name</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="printer-name" name="printerName" maxlength="150" />
                </x-filament::input.wrapper>
                <p class="text-sm text-gray-600 dark:text-gray-300">Enter the exact printer name shown in your operating system. Keep QZ Tray running.</p>
            </div>
            <x-filament::button type="submit">Save Settings</x-filament::button>
            <p data-settings-status role="status" aria-live="polite" class="text-sm text-gray-600 dark:text-gray-300"></p>
        </form>
        <div class="printer-setup-guide mt-8 max-w-2xl space-y-5 border-t border-gray-200 pt-6 text-sm dark:border-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Start printing in 3 steps</h2>

            <div class="space-y-2">
                <h3 class="font-semibold text-gray-950 dark:text-white">1. Connect the printer</h3>
                <p>Turn it on, load 80 mm paper, and connect it to your device. On a computer, install the manufacturer's driver and print a test page first.</p>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-gray-950 dark:text-white">2. Set up your print method</h3>
                <p>Open only the method you selected above.</p>

                <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <summary class="cursor-pointer font-medium text-gray-950 dark:text-white">QZ Tray — computer</summary>
                    <ol class="mt-3 list-decimal space-y-2 ps-5">
                        <li>Install <a href="https://qz.io/download/" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline dark:text-primary-400">QZ Tray 2.3 or newer</a> on this computer and keep it running.</li>
                        <li>Copy the printer's exact name from your computer's printer settings into <strong>QZ printer name</strong>. Example: <strong>POS-80 (Copy 1)</strong>.</li>
                        <li>When QZ asks for permission during printing, allow your Farming site.</li>
                    </ol>
                    <div class="mt-4 space-y-3">
                        <p class="font-medium">Set up this computer for printing without repeated prompts</p>
                        <x-filament::button type="button" wire:click="downloadQzCertificate" wire:loading.attr="disabled" wire:target="downloadQzCertificate" icon="heroicon-o-arrow-down-tray">
                            Download QZ Certificate
                        </x-filament::button>
                        <ol class="list-decimal space-y-2 ps-5">
                            <li>Click <strong>Download QZ Certificate</strong>.</li>
                            <li>Open <strong>QZ Tray → Advanced → Site Manager → Allowed → + → Browse</strong> and select the downloaded file.</li>
                            <li>If <strong>Unrecognized Certificate</strong> appears, choose <strong>Yes</strong> to install it as <strong>override.crt</strong> and approve the administrator prompt.</li>
                            <li>Print again. If permission is requested, select <strong>Remember this decision → Allow</strong>.</li>
                        </ol>
                        <p>Repeat once on each computer. The private key stays on the server.</p>
                    </div>
                </details>

                <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <summary class="cursor-pointer font-medium text-gray-950 dark:text-white">Browser Dialog — computer</summary>
                    <p class="mt-3">No extra app is needed. When the print dialog opens, choose your printer and 80 mm paper, then click <strong>Print</strong>.</p>
                </details>

                <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <summary class="cursor-pointer font-medium text-gray-950 dark:text-white">RawBT — Android</summary>
                    <ol class="mt-3 list-decimal space-y-2 ps-5">
                        <li>Install <a href="https://rawbt.ru/index.html" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline dark:text-primary-400">RawBT</a> on your Android device. For Bluetooth, pair the printer in Android settings.</li>
                        <li>Select your printer inside RawBT and run its test print.</li>
                        <li>Return to Farming. When printing, allow it to open RawBT. If needed, tap <strong>Open RawBT</strong>.</li>
                    </ol>
                </details>
            </div>

            <div class="space-y-2">
                <h3 class="font-semibold text-gray-950 dark:text-white">3. Save and print a receipt</h3>
                <p>Click <strong>Save Settings</strong>. Then open <strong>Payments</strong>, find a <strong>Paid</strong> payment, and click <strong>Print Receipt</strong>.</p>
                <p>Check that the receipt prints. Saving settings alone does not test the connection.</p>
            </div>

            <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <summary class="cursor-pointer font-medium text-gray-950 dark:text-white">Need help?</summary>
                <ul class="mt-3 list-disc space-y-2 ps-5">
                    <li><strong>Printer not found:</strong> check the connection and exact printer name. On Windows, find it in Settings → Bluetooth &amp; devices → Printers &amp; scanners.</li>
                    <li><strong>QZ does not connect:</strong> keep QZ Tray running and check its permission prompt.</li>
                    <li><strong>Blank or unreadable receipt:</strong> check the paper and driver. QZ and RawBT require an ESC/POS-compatible printer.</li>
                    <li><strong>Print not confirmed:</strong> check the print queue before retrying to avoid duplicates.</li>
                    <li><strong>Print Receipt is missing:</strong> check that the payment is Paid and ask an administrator to check your access.</li>
                </ul>
                <p class="mt-3">Settings apply only to this browser and site. Set them again if you switch devices or clear browser storage.</p>
            </details>
        </div>
    </x-filament::section>
</x-filament-panels::page>
