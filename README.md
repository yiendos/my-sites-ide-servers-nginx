# nginx server

[nginx](https://nginx.org/) for [my-sites-ide](https://github.com/yiendos/my-sites-ide), serving
every site in `Repos/` over HTTPS on port 443 and handing PHP to the IDE's `fpm` container. It's
the IDE's default web server, and runs alongside the apache (8443) and caddy (9443) plugins, or
instead of them - each site picks up whichever servers have a vhost for it.

Written for: developers running sites in my-sites-ide, including ones moving over from the nginx
that used to ship inside the IDE.

## Contents

- [Installation](#installation)
- [Upgrading from the built-in nginx](#upgrading-from-the-built-in-nginx)
- [Architecture](#architecture)
- [Site vhosts](#site-vhosts)
- [Certificates](#certificates)
- [Command reference](#command-reference)
- [Configuration](#configuration)
- [What it uses from the IDE](#what-it-uses-from-the-ide)
- [Troubleshooting](#troubleshooting)
- [Known gaps](#known-gaps)

## Installation

A [my-sites-ide](https://github.com/yiendos/my-sites-ide) plugin. Add it to the `require` section of the IDE's `composer.local.json`
(the IDE's `composer.local-example.json` already lists it):

```json
"yiendos/my-sites-ide-servers-nginx": "@dev"
```

Then, from the IDE root:

```
composer update
php my-sites-ide ide:build      # builds the ${NAMESPACE}_nginx image, then sparks the IDE
```

Composer's `post-autoload-dump` hook registers the `servers:nginx-*` commands, the `nginx`
compose service, and the plugin's `site-created` hook. The service has `autostart: true`, so
`ide:spark` starts it alongside `APP` - you don't need to list `nginx` in `APP`.

Then visit https://default.localhost/.

## Upgrading from the built-in nginx

nginx used to live in the IDE at `_dev/environment/servers/nginx/`. The image, config, container
name and network alias are unchanged, and existing sites' `*nginx.conf` vhosts keep working as
they are. The parts that moved:

| Before | Now |
|---|---|
| `_dev/environment/servers/nginx/` | this package |
| `sample.vhost` | `stubs/sample.vhost` |
| `SERVERS="nginx"` decided whether new sites got an nginx vhost | installing the plugin does - `SERVERS` is gone from the IDE |
| `nginx` listed in `APP` | started by `autostart` |
| `IDE_SITE_ALIAS` documented in the IDE's `env-example` | documented in this plugin's `env-example` (`php my-sites-ide ide:plugin-env yiendos/my-sites-ide-servers-nginx`) - the setting itself is unchanged |
| `local/laravel.conf.template` | dropped - nothing mounted or read it |

A container created before the move still mounts `nginx.conf` from the old path, which no longer
exists. Recreate it once from the plugin's compose file:

```
php my-sites-ide servers:nginx-start
```

Your existing `.env` can keep `nginx` in `APP` and the `SERVERS` line while the plugin is
installed - the first is de-duplicated, the second ignored. Remove `nginx` from `APP` if you
uninstall the plugin, or `ide:spark` will ask compose for a service that no longer exists.

## Architecture

```
host (my-sites-ide CLI)
  |- ide:create-site / ide:repo-clone --> site-created hook --> servers:nginx-vhost <site>
  |                                                             writes Repos/<site>/_build/config/1-<site>-nginx.conf
  |- ide:spark / ide:restart          --> docker compose up / restart (nginx included)
  |- servers:nginx-test               --> nginx -t (or -T) in the running container, or a throwaway one
  |- servers:nginx-reload             --> nginx -t, then nginx -s reload in the running container
  |- servers:nginx-start / -stop      --> docker compose up -d / stop nginx

browser --https:443--> nginx container  (also on the my-sites-ide network as ${IDE_SITE_ALIAS})
                         |- conf/nginx.conf: include /opt/repos/**/_build/config/*nginx.conf
                         |- static files served straight from /opt/repos/<site>/Sites/public
                         |- *.php ---- fastcgi_pass fpm:9000 ----> IDE fpm container
```

The container runs as `nginx` (uid/gid 10014, set in the `Dockerfile`), listening on 443 only -
there's no plain-HTTP listener. Because the master process isn't root, nginx logs a harmless
`the "user" directive makes sense only if the master process runs with super-user privileges`
warning on every start and test.

## Site vhosts

`conf/nginx.conf` loads every site's own vhosts, so a site is served once
`Repos/<site>/_build/config/` holds a file ending `nginx.conf` and nginx has reloaded
(`php my-sites-ide servers:nginx-reload`).

`ide:create-site` and `ide:repo-clone` create that vhost for you through this plugin's
`site-created` hook. It copies `stubs/sample.vhost` to `Repos/<site>/_build/config/1-<site>-nginx.conf`,
replacing `__PROJECT__` with the site name:

- `server_name <site>.localhost`, so https://<site>.localhost
- `root /opt/repos/<site>/Sites/public/`
- PHP passed to `fpm:9000`, with the same document root
- the IDE's self-signed certificate

A site that already has any `*nginx.conf` is left alone. A cloned repository usually brings its
own, and nginx loads every file it finds, so a second vhost for the same `server_name` would be
ignored with a "conflicting server name" warning. Pass `--force` to write the sample anyway.

For sites that existed before the plugin was installed, run the command yourself:

```
php my-sites-ide servers:nginx-vhost <site>
php my-sites-ide servers:nginx-reload
```

## Certificates

New vhosts use the IDE's self-signed certificate, `/etc/nginx/ssl/selfsigned.crt`/`.key`
(`_dev/environment/servers/ssl/` in the IDE, shared with the apache plugin).

nginx also mounts the IDE's shared certificate store, `storage/certificates/`, at
`/etc/nginx/ssl/live` and `/etc/nginx/ssl/archive`. A certificate plugin such as
[yiendos/my-sites-ide-certificates-certbot-cloudflare](https://github.com/yiendos/my-sites-ide-certificates-certbot-cloudflare)
fills it. To use one of its certificates, point the site's vhost at it:

```nginx
ssl_certificate     /etc/nginx/ssl/live/<domain>/fullchain.pem;
ssl_certificate_key /etc/nginx/ssl/live/<domain>/privkey.pem;
```

then `php my-sites-ide servers:nginx-reload`. Run the reload after each renewal too - nginx keeps
the certificate it loaded until then.

## Command reference

| Command | What it does |
|---|---|
| `servers:nginx-vhost <site>` | Create `Repos/<site>/_build/config/1-<site>-nginx.conf` from the sample, unless the site already has an nginx vhost |
| `servers:nginx-vhost <site> --force` | Same, writing the sample even when an nginx vhost exists (overwrites `1-<site>-nginx.conf`) |
| `servers:nginx-test` | Test the config, every site's vhost included (`nginx -t`). Uses the running container, or a throwaway one when nginx is stopped |
| `servers:nginx-test --dump` | Same, then print the whole config with every include expanded (`nginx -T`) - each file is headed `# configuration file <path>:`, so you can see which one declares a `server_name` |
| `servers:nginx-reload` | Test the config, then reload (`nginx -s reload`) - old workers finish their requests, and only nginx is touched. Nothing is reloaded if the test fails |
| `servers:nginx-start` | Test the config in a throwaway container, then `docker compose up -d nginx`. Also recreates a running container whose compose config has changed (e.g. a new `IDE_SITE_ALIAS`) |
| `servers:nginx-stop` | `docker compose stop nginx`, leaving the rest of the IDE running. The next `ide:spark` starts it again |

After editing a vhost, `servers:nginx-reload` is quicker than `ide:restart`, which restarts every
container. The reload is asynchronous: a request made the instant it returns can still be answered
by an old worker.

## Configuration

| Variable | Default | What it does |
|---|---|---|
| `NGINX_PORT` | `443` (this plugin's `.env`) | The host port nginx publishes HTTPS on. Pick one nothing else publishes - apache has 8443, caddy 9443 |
| `IDE_SITE_ALIAS` | `default.test` (inline in `docker-compose.yml`) | A hostname nginx answers to on the `my-sites-ide` network, so other containers (e.g. the [zaproxy plugin](https://github.com/yiendos/my-sites-ide-security-zaproxy)) can reach a site by name. One at a time, and it must be in that site's vhost `server_name`. Takes effect after `servers:nginx-start` |

Set either in the IDE's root `.env`, which wins over the plugin's defaults.
`php my-sites-ide ide:plugin-env yiendos/my-sites-ide-servers-nginx` copies them in, commented out.

To change how nginx behaves:

- **One site**: edit its `Repos/<site>/_build/config/*nginx.conf` and run `servers:nginx-reload`. This is
  the place for per-site rewrites, extra `server_name`s or a real certificate.
- **All sites**: edit `conf/nginx.conf` in this package (logging, includes, gzip) and run
  `servers:nginx-reload`. With a `Packages/` clone, that's your working copy; otherwise copy the
  change upstream, as `composer update` replaces `vendor/`.
- **The image**: edit the `Dockerfile` and rebuild with `docker compose build nginx`.

## What it uses from the IDE

| From the IDE | Used for |
|---|---|
| `Repos/` | mounted at `/opt/repos` - the sites and their vhosts |
| `_dev/environment/servers/ssl/` | the self-signed certificate, mounted at `/etc/nginx/ssl` |
| `storage/certificates/live`, `archive` | certificates from a certificate plugin (`ide:spark` creates both) |
| `Repos/_default/_build/config/0-default-nginx.conf` | the default site, https://default.localhost |
| the `fpm` service (`fpm:9000`) | PHP, through `fastcgi_pass` |
| `NAMESPACE` (root `.env`) | the image name, `${NAMESPACE}_nginx` |
| `IDE_ROOT` (set by the CLI and `_dev/cache/ide.env`) | reaching the paths above from `vendor/` |
| the `my-sites-ide` network | reaching `fpm`, and being reached as `IDE_SITE_ALIAS` |

## Troubleshooting

**https://<site>.localhost shows the default site, or the wrong one.** The site has no nginx vhost,
nginx hasn't reloaded since it was added, or another vhost claims the same name first.
`php my-sites-ide servers:nginx-test --dump` shows every loaded file and its `server_name`; then
`php my-sites-ide servers:nginx-reload`.

**`open() "/etc/nginx/nginx.conf" failed` or the container keeps restarting after upgrading.** The
container was created from the IDE's old compose file, whose mount path no longer exists - see
[Upgrading from the built-in nginx](#upgrading-from-the-built-in-nginx). `servers:nginx-start` recreates it.

**`servers:nginx-reload` or `-start` reports `[emerg]`.** The message names the file and line,
usually a site's `*nginx.conf`. The running server is left as it was until the error is fixed.

**Every site is down after cloning a repo.** One bad vhost stops nginx loading any of them - often a
committed vhost whose `ssl_certificate` names a certificate this machine doesn't have
(`cannot load certificate "/etc/nginx/ssl/live/<domain>/fullchain.pem"`). Issue the certificate
with a certificate plugin, or point the vhost back at the self-signed one.

**Static files load but PHP pages return 502.** nginx can't reach `fpm`. Make sure the `fpm`
service is running (`docker compose ps fpm`), as it's an IDE service and not part of this plugin.

**PHP pages say "File not found".** fpm resolves the script from `SCRIPT_FILENAME`, built from the
vhost's `root`, so that path has to exist inside fpm's `/opt/repos` too. Check the site's `root` line.

**`ide:spark` fails with `no such service: nginx`.** `APP` in `.env` still lists `nginx`, but the
plugin isn't installed. Install it, or remove `nginx` from `APP`.

**Port 443 is already allocated.** Another container or host process has it.
`docker ps --filter publish=443` shows which one, or move nginx with `NGINX_PORT`.

**Browser certificate warning.** Expected with the IDE's self-signed certificate. Accept it once per
hostname, or use a certificate plugin.

## Known gaps

- The vhost stub assumes a Laravel-style `Sites/public` document root. Other layouts need their
  vhost edited after it's generated.
- The default site's vhost (`Repos/_default/_build/config/0-default-nginx.conf`) still lives in the
  IDE, not in this package.
- Certificate renewals aren't followed by an automatic reload - run `servers:nginx-reload`.
- `conf/nginx.conf` still includes `/etc/nginx/conf.d/sites-enabled/*.conf`, which nothing mounts.
