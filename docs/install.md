# Install

Vaka is a handful of PHP files and an SQLite database. There is nothing to build and nothing to install from a package manager.

## Before you start: where Vaka runs

**Run Vaka on a different server than the sites it watches.** If they share a server and that server goes down, Vaka goes down with it and nobody hears a thing. A small server of its own, or one that hosts other things, is fine.

Then let something outside watch Vaka itself (see [Watching Vaka](#watching-vaka)).

## Requirements

- PHP 8.3 or newer with `pdo_sqlite`, `curl` and `openssl` (all common). `intl` is optional, for international domain names.
- Cron, to run `php vaka run` every minute.
- A web server whose document root you can point at `public/`, for the dashboard.
- For email alerts: a mail transfer agent that PHP's `mail()` can use. See [Alerts](alerts.md#email).

## Install

```bash
git clone https://github.com/webready-se/vaka.git
cd vaka
cp .env.example .env          # optional: every setting has a default
php vaka user:add admin       # a user for the dashboard (Enter makes a strong password)
php vaka site:add https://shop.example
php vaka run                  # check it once by hand
```

`data/` (the database) and `users.json` are created on first use and stay out of git. Keep them outside the document root: only `public/` may be served.

Open the dashboard. Before the first user exists, it says how to create one. Without sites, it shows the first steps, with this server's own cron line, and ticks them off as cron starts running and alerts get somewhere to go.

Add the cron line (use the full path to `php` if cron does not find it):

```cron
* * * * * php /path/to/vaka/vaka run >> /dev/null 2>&1
```

Then set where alerts go, and send a test:

```bash
# in .env
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...

php vaka alert:test
```

## Laravel Forge

1. Create a site (for example `vaka.example.com`) on a server that hosts none of the watched sites, and connect the repository. Set the **web directory** to `/public`.
2. Turn on **zero-downtime deployments** if you like: Vaka notices the `releases/` directory and keeps `data/` and `users.json` next to it, so they survive every deploy, and reads `.env` from there too, where the Environment tab saves it.
3. In the site's **Environment** tab, set what you need from `.env.example` (at least `APP_URL`, `SLACK_WEBHOOK_URL` and `HEARTBEAT_URL`).
4. Add a **scheduled job**, every minute: `php /home/forge/vaka.example.com/current/vaka run` (or `.../vaka.example.com/vaka run` without zero-downtime deployments). The dashboard shows the exact line once you have logged in.
5. Add the first user over SSH: `php vaka user:add admin`.
6. Turn on Let's Encrypt for the site.

The deploy script needs nothing beyond pulling the code. Database changes are applied automatically the first time Vaka opens the database after an update.

## Shared hosting

Any host with PHP 8.3, SQLite and cron works.

**With SSH, or a terminal in the host's control panel:**

1. Upload the files, keeping the folders as they are.
2. Point the domain's document root at `public/`. If the host does not let you choose it, put Vaka outside the web root and make the web root a link to `public/`.
3. Run `php vaka user:add admin`, then add your sites with `php vaka site:add <url>` or `php vaka site:import sites.csv`.
4. Add the cron line in the host's control panel. The dashboard shows the exact line once you have logged in.

**Without SSH:**

1. On your own computer (PHP 8.3 with SQLite), in Vaka's folder: `php vaka user:add admin`, then add your sites and groups. This creates `users.json` and `data/vaka.sqlite`.
2. Upload everything, including `users.json`, `data/` and `.env` if you made one.
3. Point the document root at `public/` and add the cron line.

Later changes to sites and groups need the command line on the server. Uploading the database again replaces the history Vaka has collected since, so make the changes before the first upload.

Check that `https://vaka.example.com/users.json`, `/.env` and `/data/vaka.sqlite` answer 404: if they do not, the document root is wrong.

## Nginx

```nginx
server {
    server_name vaka.example.com;
    root /var/www/vaka/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Vaka sends its own security headers (a strict Content Security Policy among them); do not add a second, looser one in Nginx.

Behind a reverse proxy or load balancer, set `TRUSTED_PROXIES` so the login lockout sees each visitor's real address. See [Configuration](configuration.md).

## Watching Vaka

Three things tell you when Vaka itself stops:

- **`HEARTBEAT_URL`**: every run pings this address. Point it at a dead man's switch such as [Healthchecks.io](https://healthchecks.io) (the free tier is plenty); it alerts you when the pings stop, for example because cron stopped or Vaka's server went down.
- **`/?health`** answers 200 while runs are recent and 503 when the last one is more than five minutes old, for any external monitor.
- **The dashboard** says when Vaka last checked, and warns when it has stopped.

## Upgrading

Replace the files (or pull and deploy), keep `data/`, `.env` and `users.json`. Database changes are applied on first use. Read the [changelog](../CHANGELOG.md) first.

## Trying it without real sites

```bash
php scripts/seed-demo.php /tmp/vaka-demo.sqlite
USERS_FILE=/tmp/vaka-demo-users.json php vaka user:add demo
DB_PATH=/tmp/vaka-demo.sqlite USERS_FILE=/tmp/vaka-demo-users.json php -S localhost:8080 -t public
```

Then open http://localhost:8080 and log in as `demo`.
