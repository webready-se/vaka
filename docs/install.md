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

Download the zip from the [latest release](https://github.com/webready-se/vaka/releases/latest): it holds only the files Vaka needs to run, and its docs. Unpack it on the server:

```bash
unzip vaka-*.zip && cd vaka-*/
cp .env.example .env          # optional: every setting has a default
php vaka user:add admin       # a user for the dashboard (Enter makes a strong password)
php vaka site:add https://shop.example
php vaka run                  # check it once by hand
```

Or `git clone https://github.com/webready-se/vaka.git` to follow `main` (it also has the tests and the demo scripts).

Set `TIMEZONE` in `.env` to your own, such as `TIMEZONE=Europe/Stockholm`: unset, Vaka uses the server's, which is usually UTC, and the dashboard's footer says so when that is not the time where you are.

`data/` (the database) and `users.json` are created on first use and stay out of git. Keep them outside the document root: only `public/` may be served.

Open the dashboard. Before the first user exists, it says how to create one. Without sites, it shows the first steps, with this server's own cron line, and ticks them off as cron starts running and alerts get somewhere to go. The line names PHP by its full path, since cron starts with a short PATH, and the dashboard shows it again whenever Vaka has stopped running.

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
2. Turn on **zero-downtime deployments** if you like: Vaka notices the `releases/` directory and keeps `data/` and `users.json` next to it, so they survive every deploy, and reads `.env` from there too, where the Environment tab saves it. The same for a site Forge isolates under a user of its own (`/home/<user>/<site>`).
3. In the site's **Environment** tab, set what you need from `.env.example` (at least `APP_URL`, `SLACK_WEBHOOK_URL` and `HEARTBEAT_URL`).
4. Add a **scheduled job**, every minute: `php /home/forge/vaka.example.com/current/vaka run` (or `.../vaka.example.com/vaka run` without zero-downtime deployments). The dashboard shows the exact line once you have logged in.
5. Add the first user over SSH: `cd /home/forge/vaka.example.com/current && php vaka user:add admin` (the site's own directory without zero-downtime deployments). Run every command from `current/`: in the site's root `php vaka` finds no file, and a shell left in an old release under `releases/` runs old code against the live database, which Vaka warns about on every command.
6. Turn on Let's Encrypt for the site.
7. In the site's Nginx configuration (the site's `…` menu, **Edit Nginx configuration**, the file **General site configuration**), remove the `add_header` lines for `X-Frame-Options "SAMEORIGIN"` and `X-XSS-Protection`, and keep the one for `X-Content-Type-Options "nosniff"`. Nginx adds these to Vaka's own answers too, so `X-Frame-Options` goes out as both `DENY` (Vaka) and `SAMEORIGIN` (Forge), which contradict each other, and `X-XSS-Protection` is obsolete. The `nosniff` line is where the files Nginx serves itself (the dashboard's script, styles and fonts) get theirs, since Forge keeps `location /` in a file of its own; on Vaka's own answers it then comes twice with the same value, which does no harm.

The deploy script needs nothing beyond pulling the code. Database changes are applied automatically the first time Vaka opens the database after an update.

## Shared hosting

Any host with PHP 8.3, SQLite and cron works.

**With SSH, or a terminal in the host's control panel:**

1. Upload the files, keeping the folders as they are.
2. Point the domain's document root at `public/`. If the host does not let you choose it, put Vaka outside the web root and make the web root a link to `public/`.
3. Run `php vaka user:add admin`, then add your sites with `php vaka site:add <url>` or `php vaka site:import sites.csv`.
4. Add the cron line in the host's control panel. The dashboard shows the exact line once you have logged in, with PHP's full path: until then, the line further up, with the path to `php` your host gives.

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
        add_header X-Content-Type-Options "nosniff" always;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Vaka sends its own security headers (a strict Content Security Policy among them); do not add a second, looser one in Nginx. The one line above is for the files Nginx serves itself, the dashboard's script, styles and fonts, so they say `nosniff` too (Vaka checks that on the sites it watches, and on itself). On Apache, the same is `Header set X-Content-Type-Options "nosniff"` for `public/`.

Behind a reverse proxy or load balancer, set `TRUSTED_PROXIES` so the login lockout sees each visitor's real address. See [Configuration](configuration.md).

## Watching Vaka

Three things tell you when Vaka itself stops:

- **`HEARTBEAT_URL`**: every run pings this address. Point it at a dead man's switch such as [Healthchecks.io](https://healthchecks.io) (the free tier is plenty); it alerts you when the pings stop, for example because cron stopped or Vaka's server went down. Set the check's period to 1 minute and its grace time to 5 minutes, so one slow run does not alert: a run still going when the next minute starts makes that minute's run skip, without a ping. That is on purpose: a run that holds on for longer means no checks are being made, which is what the heartbeat is there to tell.
- **`/?health`** answers 200 while runs are recent and 503 when the last one is more than five minutes old, for any external monitor.
- **The dashboard** says when Vaka last checked, and warns when it has stopped.

## Backups

Your sites can be added again from a CSV file, but the rest of what Vaka knows is only in its database (`data/vaka.sqlite`): the groups and where they alert (their webhook URLs), maintenance windows, server names, read tokens, snoozes and every site's history. A backup leaves out `users.json` (the logins) and `.env` (the settings, default alert addresses among them), which change only when you add a user or a setting: copy those off the server yourself when they do.

`php vaka backup` copies the database as it is at one moment, so runs can go on meanwhile, checks the copy, compresses it with gzip and keeps the newest 7 in `data/backups/` (`--keep=14` keeps 14). A folder you give it instead must exist; a relative one is read from Vaka's folder, as `DB_PATH` is. Without PHP's zlib the copy is kept uncompressed. Only the owner can read the copies, since they hold the groups' webhook URLs.

Run it once a night, as the user that runs `vaka run`. The first backup prints the line for your server, with the folder and `--keep` you gave it, to add with `crontab -e`, such as:

```cron
30 3 * * * /usr/bin/php8.4 /var/www/vaka/vaka backup > /dev/null
```

`> /dev/null` drops its report. An error still goes to cron, which mails it to that user where the server can send mail, and many cannot. To be told when the backups stop, make a check at [Healthchecks.io](https://healthchecks.io) (a second one beside Vaka's heartbeat; period 1 day, grace 1 hour) and end the line with a ping to it, sent only when the backup worked:

```cron
30 3 * * * /usr/bin/php8.4 /var/www/vaka/vaka backup > /dev/null && curl -fsS -m 10 --retry 3 https://hc-ping.com/<your check>
```

On Forge, add the job under the server's **Scheduler**:

- **Command:** `php /home/forge/vaka.example.com/current/vaka backup > /dev/null && curl -fsS -m 10 --retry 3 https://hc-ping.com/<your check>` (end it after `> /dev/null` without a check)
- **User:** `forge`
- **Frequency:** Custom, `30 3 * * *`
- **Name:** `Vaka: nightly backup`

### Off the server

The copies are on the same server as the database, so they help against a broken database or a mistake, not against losing the server, which is when you need them most. To have them then too, copy them somewhere else after each backup with [rclone](https://rclone.org/). It speaks to Dropbox, Google Drive, S3, Backblaze B2 and many more, so storage you already pay for will often do. rclone is one file, which can live in `~/bin` without root.

Set it up once with `rclone config`, as the user the job runs as, and name the remote `backups`. With Dropbox, first create an app of your own at [dropbox.com/developers/apps](https://www.dropbox.com/developers/apps), with access to an App folder only, and give `rclone config` its key and secret: the server then reaches that one folder, not the whole account. The server has no browser, so `rclone config` asks you to run `rclone authorize "dropbox" "<key>" "<secret>"` on your own computer and paste what it prints.

Then replace the job's command with this line, which makes the backup, copies the backups off the server, removes the ones older than 30 days there, and pings only when a backup from the last hour is there off the server, so a failed backup, a failed copy and an expired token all stop the ping. rclone goes by its full path, since cron's `PATH` is short:

```text
php /home/forge/vaka.example.com/current/vaka backup > /dev/null && /home/forge/bin/rclone copy /home/forge/vaka.example.com/data/backups backups:vaka --include 'vaka-*' && /home/forge/bin/rclone delete --min-age 30d backups:vaka && /home/forge/bin/rclone lsf backups:vaka --max-age 1h --include 'vaka-*' | grep -q . && curl -fsS -m 10 --retry 3 https://hc-ping.com/<your check>
```

In plain cron it goes after `30 3 * * *`, with your paths. `rclone copy` never removes anything there, so the copies off the server outlive the 7 on it, and `--include 'vaka-*'` leaves out a backup still being written. Check it once with `rclone ls backups:vaka`.

Anyone who can read the copies can post to the groups' Slack channels and webhooks: on the server only its user can, but off it, anyone with access to that storage. rclone's [crypt](https://rclone.org/crypt/) remote encrypts them on the way, and a webhook that leaks can be replaced in Slack and then in Vaka with `php vaka group:edit`.

### Restoring

First stop the jobs that write to the database: `run` and `backup` (on Forge, pause them under Scheduler). The dashboard writes only when someone logs in or changes something, so restore when nobody does. Then, in Vaka's folder (`/home/forge/vaka.example.com` on Forge, where `data/` is) and as the user that runs `vaka run`, so the restored file has the same owner, pick a backup (`ls data/backups` lists them, the newest last) and run this with its name:

```bash
gunzip -c data/backups/vaka-2026-11-19-033000.sqlite.gz > data/restore.sqlite && chmod 600 data/restore.sqlite && flock data/vaka.lock sh -c 'mkdir -p data/before-restore && mv data/vaka.sqlite* data/before-restore/ && mv data/restore.sqlite data/vaka.sqlite'
```

It unpacks the copy beside the database first, so a wrong name stops it before anything is touched; waits for a run still going (`data/vaka.lock`); and moves the old database with its `-wal` and `-shm` files to `data/before-restore/`, so a wrong pick can be undone. A copy that ends in `.sqlite` (made without zlib) is copied instead: `cp data/backups/<name> data/restore.sqlite` in place of the `gunzip`.

Check that it is the one you wanted with `php vaka status`, then start the jobs again: the next run checks what has become due since the copy was made. Remove `data/before-restore/` once you are sure.

A copy from off the server comes back first with `rclone copy backups:vaka/<name> data/backups/`; on a new server, after `rclone config` there.

## Upgrading

Read the [changelog](../CHANGELOG.md) first: Vaka is still 0.x, so a version may need a step of yours, and the changelog says which. Make a [backup](#backups) with the version you have (`php vaka backup`): database changes are applied the first time the new version opens the database, and cannot be undone. Then copy the files from the new release over the old ones (or pull and deploy), and keep `data/`, `.env` and `users.json`. Database changes are applied on first use.

## Trying it without real sites

From a clone of the repository (the demo script is not in the release zip):

```bash
php scripts/seed-demo.php /tmp/vaka-demo.sqlite
USERS_FILE=/tmp/vaka-demo-users.json php vaka user:add demo
DB_PATH=/tmp/vaka-demo.sqlite USERS_FILE=/tmp/vaka-demo-users.json php -S localhost:8080 -t public
```

Then open http://localhost:8080 and log in as `demo`.
