<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => '',
        'name' => 'Sql1874742_4',
        'user' => 'UTENTE_DATABASE_ARUBA',
        'password' => 'PASSWORD_DATABASE_ARUBA',
        'charset' => 'utf8mb4',
        'create_database' => false,
    ],
    'session_tracks_upload' => [
        'endpoint' => 'https://www.kr-solutions.it/vdjdesk/api.php?action=session-tracks-receive',
        'token' => 'TOKEN_LUNGO_CONDIVISO_TRA_LOCALE_E_HOSTING',
        'timeout' => 45,
    ],
];
