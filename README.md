# Atrium Backup

Backups for the [Atrium](https://github.com/AlveBy/atrium) admin, on top of [spatie/laravel-backup](https://spatie.be/docs/laravel-backup):

- the databases (MySQL, MariaDB, PostgreSQL, SQLite) in a consistent dump, and the folders you choose, in one compressed zip, encrypted with a password if you give one;
- copied to several places at once: a folder on the server, S3 or any S3-compatible storage, FTP, SFTP, Google Drive, Dropbox;
- daily or weekly, keeping a number of daily, weekly, monthly and yearly copies;
- an e-mail when a backup fails, a destination cannot be reached, the newest archive is too old, or its size jumps;
- a **Backups** screen for superadmins: the archives with their size and place, **Back up now**, download, restore with a summary of what will change and a typed confirmation, and a log.

## Install

```bash
composer require alveby/atrium-backup
php artisan atrium:module:install alveby/atrium-backup
```

Backups run from Laravel's scheduler, so cron must call `php artisan schedule:run` every minute. The server needs `mysqldump`/`mysql`, `pg_dump`/`psql` or `sqlite3` for the databases you use.

By default the default connection and the admin's uploads go to `storage/app/backups` every night at 02:00. To add an S3 bucket:

```dotenv
ATRIUM_BACKUP_DESTINATIONS=backups,backup-s3
ATRIUM_BACKUP_S3_KEY=...
ATRIUM_BACKUP_S3_SECRET=...
ATRIUM_BACKUP_S3_BUCKET=site-backups
ATRIUM_BACKUP_PASSWORD=...   # keep a copy somewhere other than the server
```

```bash
composer require league/flysystem-aws-s3-v3
```

## Commands

```bash
php artisan atrium:backup:run
php artisan atrium:backup:restore --dry-run
```

Everything else, including the risks of a restore and what this package adds to spatie's: [docs/backup.md](https://github.com/AlveBy/atrium/blob/main/docs/backup.md).

## License

MIT
