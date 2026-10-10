<?php

return [
    'printer' => env('QZ_PRINTER', 'POS-80 11.3.0.0 USB'),
    'certificate' => env('QZ_CERTIFICATE_PATH', storage_path('app/private/qz/digital-certificate.txt')),
    'private_key' => env('QZ_PRIVATE_KEY_PATH', storage_path('app/private/qz/private-key.pem')),
];
