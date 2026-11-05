# imgsli

**imgsli** is a before/after image slider. Drop in a few images (or paste
image URLs), drag the handle, and flip between *before* and *after* — live,
right in your browser.

It's a Laravel web app built around one self-contained web component
([`image-compare`](public/image-compare.js)). The home page *is* the slider.
Log in with Discord to save your comparisons as albums and share them by link.

> imgsli is a clean rebuild of
> [imgsli.com](https://web.archive.org/web/2026/https://imgsli.com/). The
> original slider belongs to its author; the component here was rewritten from
> scratch as a self-contained web component.

## What you can do

- **Compare images live.** Add 2–8 images from your computer or from image
  URLs. A draggable handle reveals each image over the previous one. Reorder
  boxes by dragging and add captions. This happens entirely in your browser —
  anonymous comparisons are never uploaded anywhere.
- **Save as an album.** Log in with Discord, give it a title, and save. File
  images are uploaded and URL images are fetched to the server.
- **Share albums.** Every saved album gets a link. Mark it **public** to share
  it with anyone, or keep it **private** so only you can open it. Deep-link to
  a specific slider position with `/a/{album}/{before}/{after}`.
- **Manage your albums.** Rename, toggle public/private, and delete
  (Images can't be edited after saving — to change them, delete and re-upload.)

### The compare tool, at a glance

| | |
|---|---|
| Images | 2–8 per comparison |
| Sources | local files (picked or dragged in) **or** image URLs |
| Formats | PNG, JPEG, GIF, WebP |
| Size | up to 10 MB per image |
| Slider | live in the browser; drag the handle to compare; drag boxes to reorder |
| Privacy | anonymous = 100% client-side, nothing leaves your browser |

## Using the site

1. Open the home page — you're already in the compare tool.
2. Add images: drag files onto a box, click a box to pick, or paste an image
   URL. Add two or more.
3. Drag boxes to reorder them; add a caption to any box.
4. Drag the slider handle to compare *before* and *after*.
5. To save: click **Log in with Discord**, add a title, choose **public** or
   **private**, then **Save album**. You'll get a link to copy and share.
6. Manage what you've saved under **My albums**.

## Under the hood

| Piece | Choice |
|---|---|
| Framework | Laravel 13, Blade views, no Vue/React |
| PHP | 8.3 (local dev), 8.4 (Docker image) |
| Database | MySQL 8.4 (Docker) / MariaDB (local, wire-compatible) |
| Auth | `laravel/socialite` + Discord (required only to save albums) |
| Storage | One Laravel disk `public`, driver from `STORAGE_DISK`: `local` or `s3` (see [Storage](#storage-local-vs-s3)) |
| Frontend | Blade + vanilla JS (`public/js/compare.js`, `album.js`, `mine.js`, `publicity.js`, `rename.js`) + the `image-compare` web component |
| Production | Docker image: nginx + php-fpm under supervisord, behind Traefik |

## Routes

| Method & path | Auth | What |
|---|---|---|
| `GET /` | any | The compare tool. Logged in: title field, public/private toggle, save button. A "public albums" strip lists recent public albums. |
| `GET /a/{album}` | public: any; private: owner | Album view — the component with the stored images. The owner gets a rename, a public/private toggle and delete. `/{before}/{after}` set the initial picker positions. |
| `GET /mine` | Discord | Your albums (public + private) with a rename, a public/private toggle and delete. |
| `POST /api/albums` | Discord | Multipart: `title`, `is_public`, and per image `images[n].file` **or** `images[n].url` (2–8 images, png/jpg/gif/webp, ≤10 MB). URL images are fetched server-side with SSRF protection (public IPs only, checked per redirect hop). |
| `PATCH /api/albums/{album}` | owner | JSON `{is_public: bool}` and/or `{title}` (rename; `null` clears it) — at least one field required. |
| `DELETE /api/albums/{album}` | owner | Deletes the album and its stored files. Images are immutable (rename/visibility are editable). |
| `GET /auth/discord`, `GET /auth/discord/callback` | — | Socialite Discord login (upsert by `discord_id`). |
| `POST /logout` | — | |

Album storage keys are random hex under `albums/{ulid}/…` — user input never
reaches storage paths.

## Setup

Three ways to run it: **local development** (fast iteration), **Docker
production** (the deploy setup), or **Docker development** (the production
stack with live code + in-container tests). All use MySQL and a `.env` file.
Discord login is only
needed if you want to *save* albums — the compare tool works without it.

### Prerequisites

- **PHP 8.3+** and **Composer** (local dev)
- **MySQL 8.x** or **MariaDB** (local dev)
- **Docker** and **Docker Compose** (Docker production)

### 1. Get the code

```bash
git clone <repo-url> imgsli
cd imgsli
```

### 2. Configure

```bash
cp .env.example .env
```

Then fill in `.env`:

- `DB_PASSWORD` — a strong password for the MySQL user (`DB_USERNAME` and
  `DB_DATABASE` default to `imgsli`).
- `APP_KEY` — generated by the step below (or `php artisan key:generate`).
- `DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET` / `DISCORD_REDIRECT_URI` — only
  needed to save albums (see [Discord login](#discord-login-to-save-albums)).
- For a **Docker** deploy also set `APP_HOST` (the site's hostname, e.g.
  `imgsli.example.com`), `APP_URL=https://<APP_HOST>`, and `TRUSTED_PROXIES`
  (see [Docker production](#3b-docker-production)).

### 3a. Local development

```bash
composer install
php artisan key:generate      # only if APP_KEY is still empty
php artisan migrate --seed    # --seed adds a demo user + a sample public album
php artisan storage:link
php artisan serve
```

Open <http://127.0.0.1:8000>.

> Shortcut: `composer setup` runs `composer install`, copies `.env.example` to
> `.env`, generates the key, and migrates — in one command. You still need to
> fill in `DB_PASSWORD` (and Discord credentials) afterward, and run
> `php artisan storage:link`.

**Run the tests:**

```bash
php artisan test
```

The suite runs against a separate `imgsli_test` database (see `phpunit.xml`).

### 3b. Docker production

```bash
# from the app directory, with a ready .env:
docker compose up -d --build
```

The production layout is self-contained — no named volumes; the app directory
is the whole footprint:

```
imgsli/
  docker-compose.yml .env …   # the repo files
  db/                          # MySQL data dir (mysql:8.4)
  storage/app/                 # album images (STORAGE_DISK=local)
```

**Traffic path.** Example edge: a CDN (e.g. Cloudflare, proxied) → your
reverse proxy (e.g. traefik) → web. The containers expose **no ports at all**;
the proxy (on the shared external `app_net`) is the only ingress path. TLS:
terminate at your CDN or at the proxy — the example uses the proxy's default
certificate (a file provider), so the router only needs the `tls` option (no
certresolver). The `db` service sits off `app_net` (a private internal
network), so no other container on the edge can reach MySQL.

**Boot.** The entrypoint prepares the bind-mounted storage dir, runs
`migrate --force` and `storage:link`, and renders the upload size limits from
the `UPLOAD_*` variables. The container listens on plain 80; TLS terminates at
your CDN or proxy.

**Environment.** The `.env` is the single source of truth for all environment
config; the web service takes it whole via `env_file`. Set the production
values in it:

- `APP_HOST` (bare hostname) — compose uses it for the traefik router's
  `Host()` matcher;
- `APP_URL=https://<APP_HOST>` — storage / og:image / share URLs are absolute
  via `APP_URL`;
- `DISCORD_REDIRECT_URI=https://<APP_HOST>/auth/discord/callback` — add it to
  the Discord app's redirect list too;
- `TRUSTED_PROXIES` — your CDN's published IP ranges (e.g. Cloudflare's,
  re-sync from <https://www.cloudflare.com/ips/> when the list changes)
  **plus the subnet your proxy forwards from** (e.g. `172.20.0.0/16`): the
  proxy terminates TLS and forwards plain HTTP from that subnet, so its
  `X-Forwarded-Proto` header must be trusted for `Request::ip()` /
  `isSecure()` and `asset()` URLs to see the real client over https;
- `DB_HOST` — the compose service name (`db`).

Compose **fails fast if `APP_HOST` or `DB_PASSWORD` is empty in `.env`** — set
both before `compose up`. For the live production environment also set
`APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` and
`SESSION_SECURE_COOKIE=true` in the `.env` (the production env is not shared
with local development).

**Ops.** nginx logs are size-capped by logrotate. Upload limits (images per
album, per-image size, URL length, redirect count, timeouts, rate limit) are
centralized in `config/uploads.php` and tuned via the `UPLOAD_*` env vars
(defaults: 2–8 images, 10 MB/image, 10/min). In Docker the entrypoint derives
the nginx `client_max_body_size` and PHP `upload_max_filesize` /
`post_max_size` from `UPLOAD_MAX_IMAGES` × `UPLOAD_MAX_IMAGE_BYTES`, so editing
those two resizes the whole chain; `docker compose up -d` applies it.

### 3c. Docker development (dev machine)

For developing on a machine without PHP/Composer installed. The repo ships
the dev config in `docker-compose.dev.yml`; apply it on the dev machine by
copying it to the (gitignored) override name, then start:

```bash
cp docker-compose.dev.yml docker-compose.override.yml
docker compose up -d --build
```

- **Live code, no rebuilds.** `web` builds the same Dockerfile with the dev
  dependencies (`COMPOSER_FLAGS=""` → PHPUnit, Mockery, …) and bind-mounts the
  source tree, so file edits are live on the next request — Blade templates
  recompile automatically, JS/CSS are served from `public/`. A rebuild is
  only needed when `composer.json` / `composer.lock` / the Dockerfile change.
- **Tests** run in a one-off service against a separate `imgsli_test`
  database (created by `docker/db-init-test.sql` on first db init) — the app
  database is never touched. `phpunit.xml` only fills env vars that aren't
  already set, and the dev `.env` points at the app database, so the test
  service sets its values explicitly:

  ```bash
  docker compose run --rm test                            # full suite
  docker compose run --rm test vendor/bin/phpunit --filter AlbumVisibilityTest
  ```

- **Data.** The dev file keeps MySQL data and album images in named volumes
  (`db_data`, `storage_data`) instead of the production file's `./db` /
  `./storage/app` bind mounts, so the repo directory stays code-only.
- **Never apply the dev file to a production host** — it bind-mounts the
  working tree, so uncommitted edits would go live.

## Discord login (to save albums)

Saving albums uses a Discord account via OAuth. To enable it:

1. In the [Discord Developer Portal](https://discord.com/developers/applications)
   → **Applications** → **New Application** → **OAuth2** → **Redirects**, add
   the callback for each environment:
   - local: `http://localhost:8000/auth/discord/callback`
   - production: `https://<APP_HOST>/auth/discord/callback`
2. Copy the application's **Client ID** and **Client Secret** into `.env`:

   ```ini
   DISCORD_CLIENT_ID=...
   DISCORD_CLIENT_SECRET=...
   DISCORD_REDIRECT_URI=http://localhost:8000/auth/discord/callback   # or the production URL
   ```

3. No bot or permissions are needed — it's plain OAuth user login. Anonymous
   visitors can still use the compare tool; only *saving* requires a login.

## Storage: local vs S3

All album image URLs go through `Storage::disk('public')->url($key)`, so
switching backends is env-only:

```ini
# local (default): storage/app/public + storage:link (the entrypoint runs it)
STORAGE_DISK=local

# s3: bucket name in STORAGE_DISK_ROOT (or AWS_BUCKET), credentials in AWS_*,
# and the public base URL:
STORAGE_DISK=s3
STORAGE_DISK_URL=https://cdn.example.com
```

With S3 the `storage/app` bind mount goes unused.

## Configuration quick reference

The `.env` is the single source of truth for configuration. The most useful
variables:

| Variable | Default | What it controls |
|---|---|---|
| `APP_HOST` | — | Site hostname; the Docker reverse-proxy router matches it |
| `APP_URL` | — | Absolute base URL (storage links, og:image, share links) |
| `STORAGE_DISK` | `local` | `local` (server disk) or `s3` (bucket) for album images |
| `UPLOAD_MIN_IMAGES` / `UPLOAD_MAX_IMAGES` | 2 / 8 | how many images per album |
| `UPLOAD_MAX_IMAGE_BYTES` | `10485760` | per-image size cap, for file uploads **and** URL fetches |
| `UPLOAD_MAX_URL_LENGTH` | `2048` | max length of a pasted image URL |
| `UPLOAD_STORE_RATE` | `10,1` | saves per minute per user |

All upload limits are centralized in `config/uploads.php` and driven by the
`UPLOAD_*` variables. The complete, commented list is in
[`.env.example`](.env.example).

## Tests

`tests/Feature` (PHPUnit) cover page rendering (compare, album public/private
access), album creation (file, URL, mixed), validation failures, URL-SSRF
rejection, rename, delete, and the Discord callback upsert. Run with `php artisan test`
against a separate `imgsli_test` database (see `phpunit.xml`) — or in the
Docker dev setup, `docker compose run --rm test`
([3c](#3c-docker-development-dev-machine)).

## Repo layout

```
app/ bootstrap/ config/ database/ public/ resources/ routes/ storage/
public/image-compare.js          # the component (custom element <image-compare>)
public/js/compare.js             # client app: image boxes, live render, save flow
public/js/album.js               # album page wiring
public/js/publicity.js           # shared public/private toggle (mine + album)
public/js/rename.js              # shared inline rename (mine + album)
docker/Dockerfile nginx.conf supervisord.conf entrypoint.sh db-init-test.sql
docker-compose.yml docker-compose.dev.yml    # base (prod) + dev config
tests/
```

## License

[MIT](LICENSE). This covers the code in this repository, not imgsli.com's
original code, name, or design, which belong to its author.
