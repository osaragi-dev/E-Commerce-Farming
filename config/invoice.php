<?php

return [
    'fonts' => [
        'regular' => PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/times.ttf' : null,
        'bold' => PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/timesbd.ttf' : null,
        'italic' => PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/timesi.ttf' : null,
        'heading' => PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts/GARABD.TTF' : null,
        'company' => resource_path('fonts/invoice/PlayfairDisplay-Bold.ttf'),
    ],
    'company_name' => 'PT. AGUNG FARM & GARDEN',
    'address' => "Jl. Kol. R. W Sugiarto RT. 02 RW. 05 Kel. Nongkosawit\nKec.Gunung Pati Kota Semarang (50223)",
    'company_phone' => '08115911191',
    'bank_name' => 'BANK BNI',
    'bank_account' => '8888706709',
    'bank_holder' => 'AGUNG FARM AND GARDEN',
];
