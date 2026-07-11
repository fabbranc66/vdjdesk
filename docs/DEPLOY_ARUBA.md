# Deploy Aruba

Database Aruba: `Sql1874742_4`

## 1. Configurazione

Sul server Aruba crea un file `config.php` nella root di KR Desk, partendo da `config.example.php`.

```php
<?php
declare(strict_types=1);

return [
    'database' => [
        'host' => 'HOST_DATABASE_ARUBA',
        'port' => '',
        'name' => 'Sql1874742_4',
        'user' => 'UTENTE_DATABASE_ARUBA',
        'password' => 'PASSWORD_DATABASE_ARUBA',
        'charset' => 'utf8mb4',
        'create_database' => false,
    ],
];
```

## 2. Export database locale

Da PowerShell, nella cartella progetto:

```powershell
.\tools\export-aruba-db.ps1
```

Il file generato è:

```text
storage\aruba-vdjdesk.sql
```

Va importato nel database Aruba `Sql1874742_4`.

## 3. Upload file

Carica su Aruba:

- `api.php`
- `index.php`
- `qr.php`
- `quiz-screen.php`
- `request.php`
- `assets/`
- `src/`
- `vendor/`
- `config.php`

Non caricare:

- `config.example.php`
- `storage/*.sqlite`
- eventuali export SQL vecchi

## Nota operativa

Su Aruba KR Desk può usare il database MariaDB/MySQL, ma le funzioni che leggono file locali di VirtualDJ, drive `E:` o Network Control funzionano solo sul PC locale.
