<?php

/*
|--------------------------------------------------------------------------
| Tenant Database Map
|--------------------------------------------------------------------------
| Maps each domain/subdomain to its own MySQL database credentials.
| Add one entry per client shop. The host key must match the exact
| HTTP Host header (no port, no trailing slash).
|
| Example:
|   'asitha-pos.lumac.lk' => [
|       'database' => 'asitha_lmucpos',
|       'username' => 'asitha_dbuser',
|       'password' => 'secret',
|       'host'     => 'localhost',   // optional, defaults to DB_HOST
|   ],
*/

return [
    'mailagas.lumac.lk' => [
        'database' => 'lmucunal_mailagas_pos',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'asitha-pos.lumac.lk' => [
        'database' => 'lmucunal_asitha_pos',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'lover-kahatagasdigiliya.lumac.lk' => [
        'database' => 'lmucunal_lover_kahatagasdigiliya',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'renuka.lumac.lk' => [
        'database' => 'lmucunal_renuka',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'hiruna-marketing.lumac.lk' => [
        'database' => 'lmucunal_hiruna_marketing',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'electric.lumac.lk' => [
        'database' => 'lmucunal_hiruna_electric',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
    'hiruna-marketing.lumac.cc' => [
        'database' => 'hiruna_marketing',
        'username' => 'pos_user',
        'password' => 'Pos@2026Strong',
    ],
    'pos.lumac.lk' => [
        'database' => 'lmucunal_senewirathna',
        'username' => 'lmucunal_mysql',
        'password' => 'K!ngd0m@!t0ne',
    ],
        'localhost' => [
        'database' => 'hiruna',
        'username' => 'root',
        'password' => 'root',
    ],

    
];
