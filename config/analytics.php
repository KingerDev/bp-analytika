<?php

return [

    // Analyzované obdobie v mesiacoch (rovnaké pre oba segmenty kvôli férovému porovnaniu)
    'period_months' => 24,

    'segments' => [
        'b2c' => [
            'label' => 'Maloobchod (B2C)',
            'connection' => 'src_titi',
            // titi: status 1 = Storno
            'cancelled_status_ids' => [1],
            // testovacie účty — ich objednávky sa do analýzy vôbec neimportujú
            'excluded_customer_source_ids' => [2, 12, 32, 8147, 8150, 8169, 12453, 14595],
        ],
        'b2b' => [
            'label' => 'Veľkoobchod (B2B)',
            'connection' => 'src_tsv',
            // tsv: 3 = Zamietnutá schvaľovateľom, 8 = Zrušená
            'cancelled_status_ids' => [3, 8],
            'excluded_customer_source_ids' => [],
        ],
    ],

    // RFM: hranice kvintilov sa počítajú dynamicky per segment
    'rfm' => [
        'segments_map' => [
            'sampioni' => ['label' => 'Šampióni', 'r' => [4, 5], 'f' => [4, 5]],
            'verni' => ['label' => 'Verní zákazníci', 'r' => [3, 5], 'f' => [3, 5]],
            'perspektivni' => ['label' => 'Perspektívni', 'r' => [4, 5], 'f' => [1, 2]],
            'ohrozeni' => ['label' => 'Ohrození', 'r' => [1, 2], 'f' => [3, 5]],
            'strateni' => ['label' => 'Stratení', 'r' => [1, 2], 'f' => [1, 2]],
            'ostatni' => ['label' => 'Ostatní', 'r' => [1, 5], 'f' => [1, 5]],
        ],
    ],

    'clarity' => [
        'b2c_token' => env('CLARITY_B2C_TOKEN'),
        'b2b_token' => env('CLARITY_B2B_TOKEN'),
    ],
];
