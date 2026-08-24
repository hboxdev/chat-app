# Chat Web

Plain PHP/MySQL chat app.

## Production Setup

1. Create a MySQL database and user in Hostinger.
2. Import `database/schema.sql` into that database.
3. Copy `config/config.local.example.php` to `config/config.local.php` on the server.
4. Fill in the Hostinger database credentials in `config/config.local.php`.
5. Deploy the repo files to the domain document root.
6. Make sure `uploads/` is writable by PHP.

Do not commit `config/config.local.php`; it contains production secrets.
