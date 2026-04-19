<?php
// diccionario_entidades.php - Mapeo oficial del BCRA y BINs de tarjetas

return [
    'cbu' => [
        '011' => 'Banco Nacion',
        '014' => 'Banco Provincia Cuenta DNI',
        '007' => 'Banco Galicia',
        '072' => 'Banco Santander',
        '285' => 'Banco Macro',
        '017' => 'Banco BBVA Frances',
        '029' => 'Banco Ciudad',
        '027' => 'Banco Supervielle',
        '143' => 'Brubank',
        '330' => 'Banco Santa Fe',
        '034' => 'Banco Patagonia',
        '044' => 'Banco Hipotecario',
        '309' => 'Banco Comafi',
        '322' => 'Banco Industrial',
        '259' => 'Banco Itau Macro BMA',
        '336' => 'Openbank'
    ],
    'cvu' => [
        '00000031' => 'Mercado Pago',
        '00000079' => 'Uala',
        '00000012' => 'Naranja X',
        '00000132' => 'Personal Pay',
        '00000216' => 'AstroPay',
        '00000017' => 'MODO',
        '00000200' => 'Prex',
        '00000115' => 'Claro Pay',
        '00000014' => 'YPF App',
        '00000086' => 'Pluspagos Billetera Santa Fe',
        '00000062' => 'Moni',
        '00000143' => 'Tap'
    ],
    'tarjetas' => [
        // BINs Internacionales (Los primeros números de las tarjetas)
        '4' => 'Visa',
        '51' => 'Mastercard', '52' => 'Mastercard', '53' => 'Mastercard', '54' => 'Mastercard', '55' => 'Mastercard',
        '34' => 'American Express', '37' => 'American Express',
        '589562' => 'Tarjeta Naranja',
        '589657' => 'Cabal'
    ]
];
