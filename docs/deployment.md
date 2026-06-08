# Deployment

## Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName api.sinclear.de
    DocumentRoot /var/www/sinclear-api/public

    <Directory /var/www/sinclear-api/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /path/to/cert.pem
    SSLCertificateKeyFile /path/to/key.pem
</VirtualHost>
```

## Deployment-Schritte

```bash
git pull
cd api
composer install --no-dev --optimize-autoloader
cp .env.example .env   # einmalig, dann pflegen
php bin/migrate.php    # einmalig für neue Tabellen
chown -R www-data:www-data var/
chmod 750 var/logs var/rate-limit
```

## Umgebungsvariablen

Siehe `.env.example`. Kritisch für Produktion:

- `APP_DEBUG=false`
- `JWT_PRIVATE_KEY` / `JWT_PUBLIC_KEY` (RS256)
- `DB_*` – Produktionsdatenbank
- `DISCORD_*`, `SMTP_*`, `WEBAUTHN_*`

## PHP-Erweiterungen

Erforderlich: `pdo`, `pdo_mysql`, `json`, `openssl`, `mbstring`

```bash
# Fedora/RHEL
sudo dnf install php-pdo php-mysqlnd php-mbstring php-openssl
```

## PHP-Einstellungen

```ini
memory_limit = 256M
upload_max_filesize = 5M
post_max_size = 5M
```

## Verzeichnisberechtigungen

- `var/logs/` – beschreibbar für www-data
- `var/rate-limit/` – beschreibbar für www-data
- `vendor/` – nicht öffentlich (DocumentRoot = `public/`)
