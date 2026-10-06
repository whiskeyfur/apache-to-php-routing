# apache-to-php-routing

`apache2router` reads one or more Apache httpd configuration files and writes a
`router.php` for PHP's built-in web server, so a site that was set up for Apache
can be run locally with nothing more than:

```sh
php -S 127.0.0.1:8080 router.php
```

The config files do not need to be where they were on the original server. They
can be copied from another machine or another OS (including Windows/XAMPP
paths such as `C:\xampp\htdocs`), and `--map` tells the tool where things live
now.

Requires PHP 8.1 or newer. There are no dependencies.

## Quick start

```sh
# Config copied from a Debian/Ubuntu server into ./server/etc/apache2,
# site files copied into ./server/www
bin/apache2router \
    --map /etc/apache2=./server/etc/apache2 \
    --map /var/www=./server/www \
    -o router.php \
    server/etc/apache2/apache2.conf

php -S 127.0.0.1:8080 router.php
```

A single vhost file works too:

```sh
bin/apache2router --map /var/www/mysite=~/code/mysite mysite.conf
```

The generated `router.php` is self-contained (it does not need this
repository), and its header comment lists the virtual hosts it found, the path
maps, the files it read and any warnings.

## Options

| Option | Meaning |
| --- | --- |
| `-o, --output FILE` | Where to write the router (default `router.php`, `-` for stdout). |
| `-m, --map OLD=NEW` | Paths under `OLD` (as written in the config) now live under `NEW`. Repeatable; the longest matching `OLD` wins. Windows paths match case-insensitively and with either slash. |
| `-r, --server-root DIR` | Where the config's `ServerRoot` lives now. Without it, if the original `ServerRoot` does not exist, the tool assumes it moved along with the config files (e.g. `/etc/httpd` → the `httpd` directory above `conf/httpd.conf`) and says so. |
| `-D, --define NAME[=VALUE]` | Like `httpd -D`: for `<IfDefine>` and `${NAME}`. |
| `--default-host NAME` | Virtual host used when the `Host` header matches none. Defaults to the first vhost, as Apache does. |
| `--htaccess auto\|always\|never` | `auto` follows `AllowOverride` (and honours `.htaccess` everywhere if the config never mentions `AllowOverride`, which happens when you pass just a vhost file). |
| `--proxy-status CODE` | Status of the page shown for proxied URLs (default `502`). |
| `--keep-redirects` | Don't point redirects at `php -S` (see below). |
| `-q, --quiet` | Don't print warnings. |

## Virtual hosts

One router serves every `<VirtualHost>`, choosing by the request's `Host`
header (`ServerName`, then `ServerAlias`, wildcards included). To reach a
particular site, either point its name at 127.0.0.1 in your hosts file and
browse to `http://site.example:8080/`, send the header yourself
(`curl -H 'Host: site.example' http://127.0.0.1:8080/`), or regenerate with
`--default-host site.example`.

When a name has both an HTTP and an HTTPS vhost, the HTTPS one is used, since
the HTTP one usually only redirects to it. `php -S` only speaks plain HTTP, so
redirects to any host the router serves are rewritten to `http://` on the
current port, and a redirect that would land on the very URL being requested
(a "force HTTPS" rule) is skipped rather than looping. `--keep-redirects`
turns this off.

## Proxying

`php -S` cannot forward requests to another server. Wherever Apache would have
proxied (`ProxyPass`, `ProxyPassMatch`, `<Location>` + `ProxyPass`,
`RewriteRule … [P]`, `SetHandler "proxy:…"`), the router answers with a page
saying where the request would have gone: the full target URL, the directive
and the file and line it came from, and the members of a `balancer://`
cluster. The target is also in the `X-Apache2Router-Proxy-Target` header, and
clients that ask for JSON get JSON.

Proxies to PHP-FPM (`fcgi://…`, `SetHandler "proxy:unix:…|fcgi://…"`) are the
exception: those just mean "run PHP", which `php -S` does itself.

## What is translated

* **Config structure:** `Include`/`IncludeOptional` (globs and directories),
  `ServerRoot`, `Define` and `${VAR}`, `<IfModule>` (using the `LoadModule`
  lines seen so far), `<IfDefine>`, `<IfVersion>`, `<IfFile>`, `<Macro>`/`Use`.
* **Hosts and mapping:** `<VirtualHost>`, `ServerName`, `ServerAlias`,
  `DocumentRoot`, `Alias`, `AliasMatch`, `ScriptAlias`, `Redirect`,
  `RedirectMatch`, `RedirectPermanent`, `RedirectTemp`.
* **Sections:** `<Directory>`, `<DirectoryMatch>`, `<Files>`, `<FilesMatch>`,
  `<Location>`, `<LocationMatch>`, `<Limit>`, `<LimitExcept>`, plus
  `.htaccess` files (read at request time, so edits show up immediately).
* **mod_rewrite:** `RewriteEngine`, `RewriteBase`, `RewriteCond`,
  `RewriteRule`, `RewriteOptions Inherit`, `RewriteMap` (`txt:`, `rnd:`,
  `int:`), in server and per-directory context. Flags: `L`, `END`, `R`, `F`,
  `G`, `P`, `PT`, `C`, `S`, `N`, `NC`, `QSA`, `QSD`, `QSL`, `NE`, `B`, `E`, `CO`,
  `T`, `H`. Conditions support regexes, `-f -d -s -l -x -U`, string and
  integer comparisons, `[OR]` and `[NC]`.
* **Per-directory behaviour:** `DirectoryIndex`, `DirectorySlash`,
  `Options Indexes`, `AcceptPathInfo`, `ErrorDocument`, `AddType`,
  `AddHandler`, `SetHandler`, `ForceType`, `RemoveHandler`, `AddDefaultCharset`,
  `Header set/append/unset`, `SetEnv`, `UnsetEnv`, `SetEnvIf`, `BrowserMatch`,
  `php_value`, `php_flag`, `php_admin_value`, `php_admin_flag` (including
  `engine off`).
* **Access control:** `Require all|ip|local|method|env|not`, `<RequireAll>`,
  `<RequireAny>`, `<RequireNone>`, and 2.2-style `Order`/`Allow`/`Deny`.
  `.ht*` files are never served.

PHP scripts run at global scope, with `$_SERVER` set up as Apache would
(`SCRIPT_NAME`, `PATH_INFO`, `DOCUMENT_ROOT`, `REDIRECT_URL`,
`REDIRECT_STATUS`, `REDIRECT_*` environment, and `$_GET` rebuilt after a
rewrite changes the query string).

## Limitations

* No HTTPS, HTTP/2 or `Range` requests (that is `php -S`).
* Authentication (`AuthType`, `Require valid-user`) is not enforced; the
  generator warns when the config uses it.
* CGI scripts are not run; the router says so instead.
* `<If>`/`<ElseIf>`/`<Else>` and `RewriteCond expr` are skipped with a warning.
* `RewriteMap` types `prg:`, `dbm:` and `dbd:` return the default value.
* Other modules' directives are ignored.

## Tests

```sh
php tests/run.php
```

Unit tests cover the parser, path mapper and rewrite engine. Integration tests
generate a router from `tests/fixtures` (a Debian-style `/etc/apache2`, a
Windows vhost and three sites, all remapped), start `php -S` with it and check
real responses.
