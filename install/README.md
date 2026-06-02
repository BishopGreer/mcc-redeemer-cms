# OurSaintFrancis CMS — Installation Guide

## Requirements
- PHP 8.1 or higher
- MariaDB 10.5+ (or MySQL 8.0+)
- Apache with mod_rewrite (or Nginx with equivalent config)
- PHP extensions: PDO, pdo_mysql, gd or imagick, mbstring, json

## Installation Steps

### 1. Upload the files
Upload the entire project to your VPS, e.g. to `/var/www/parish_cms`.

### 2. Install PHP dependencies
```bash
cd /var/www/parish_cms
composer install --no-dev
```

### 3. Create the database
```sql
CREATE DATABASE parish_cms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cms_user'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL ON parish_cms.* TO 'cms_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Import the schema
```bash
mysql -u cms_user -p parish_cms < install/schema.sql
```

### 5. Configure the site
Copy the config file and fill in your values:
```bash
cp config/config.php config/config.local.php
nano config/config.local.php
```

Set at minimum:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `SITE_URL` — your full domain, e.g. `https://your-site.org`
- `BASE_PATH` — full filesystem path to the project

### 6. Set file permissions
```bash
chown -R www-data:www-data /var/www/parish_cms/public/uploads
chmod -R 755 /var/www/parish_cms/public/uploads
```

### 7. Configure Apache VirtualHost
```apache
<VirtualHost *:80>
    ServerName your-site.org
    ServerAlias www.your-site.org
    DocumentRoot /var/www/parish_cms

    <Directory /var/www/parish_cms>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/osf_error.log
    CustomLog ${APACHE_LOG_DIR}/osf_access.log combined
</VirtualHost>
```

Enable mod_rewrite: `sudo a2enmod rewrite && sudo systemctl restart apache2`

Then install Certbot for HTTPS: `sudo certbot --apache`

### 8. First login
Visit `https://your-site.org/admin/login`

Default credentials (CHANGE IMMEDIATELY):
- Email: `admin@your-site.org`
- Password: `ChangeMe123!`

After logging in:
1. Go to Settings and fill in parish information, SMTP settings, and mass schedule
2. Update the Home page content
3. Change the admin password under Settings > Users

## Importing MailPoet Subscribers
1. In your current MailPoet, go to Subscribers → Export → Download CSV
2. In this CMS, go to Subscribers → Import from CSV
3. Upload the file — it will match MailPoet's column format automatically

## Newsletter System
- Create newsletters with the drag-and-drop block builder
- Duplicate any past newsletter to re-use its design
- Send to all subscribers or to specific lists
- Track open rates on the newsletter list page
- Export subscribers back to MailPoet-compatible CSV anytime

## Pax et Bonum
