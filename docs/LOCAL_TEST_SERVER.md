# Local test server

The local Laragon application uses the MySQL service on `127.0.0.1:3306` and the database `u622428657_main`, matching `config/db_config.php` without changing production files.

To recreate the local database from the checked-in Hostinger export:

```powershell
.\scripts\setup_local_test_db.ps1
```

The script drops and recreates only the local database, then imports `u622428657_homekmart.sql`. Use a different dump when needed:

```powershell
.\scripts\setup_local_test_db.ps1 -DumpFile path\to\dump.sql
```

Keep the local Laragon MySQL service running before starting the PHP site.

The local test URL is:

`http://127.0.0.1/homekmart/admin/login.php`

Laragon's Apache service must be running. Local HTTP requests skip the production HTTPS redirect; production hosts still redirect to HTTPS.
