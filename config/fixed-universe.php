<?php
declare(strict_types=1);

return [
    'version' => 'FIXED_VOLATILE_10_V1',
    'research_as_of' => '2026-11-24',
    'mandatory_symbols' => ['BBAS3', 'VALE3', 'PETR4'],
    'members' => [
        'BBAS3' => 'Banco do Brasil', 'VALE3' => 'Vale', 'PETR4' => 'Petrobras',
        'MGLU3' => 'Magazine Luiza', 'HAPV3' => 'Hapvida', 'BRKM5' => 'Braskem',
        'PCAR3' => 'Pão de Açúcar', 'SIMH3' => 'Simpar', 'MOVI3' => 'Movida',
        'LJQQ3' => 'Lojas Quero-Quero',
    ],
    'min_adv_brl' => 5000000,
    'min_price_cents' => 100,
    'max_quote_age_seconds' => 120,
];
