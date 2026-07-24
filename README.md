# FluxTorrent

**A modern, self-hosted web front-end for qBittorrent, Transmission and Deluge — with built-in torrent search powered by Prowlarr.**

FluxTorrent is a maintained fork of the long-dormant [TorrentFlux / TorrentFlux-NG](https://github.com/epsylon3/torrentflux) project, brought up to date for a modern stack: PHP 8, remote-daemon backends (qBittorrent, Transmission, or Deluge), Prowlarr-based search, Docker packaging, and a refreshed dark-capable UI. The original codebase spun up its own per-process BitTorrent clients and scraped a list of now-defunct torrent sites; FluxTorrent instead drives a torrent client you already run and searches through your Prowlarr indexers.

> ⚠️ FluxTorrent is intended for managing **your own legal downloads** on a server you control. You are responsible for how you use it.

---

## Features

- **Choice of torrent backend** — drive **qBittorrent** (Web API, v4 & v5), **Transmission** (RPC), or **Deluge** (Web JSON-RPC). Pick the active backend in the admin UI; add, start/stop, delete (with or without data), rate-limit, set seed-ratio limits, and set per-file priorities all work through the client's own API. No CLI clients are spawned by the web server. Torrents added directly in the daemon (or via magnet) are adopted into the transfer list automatically.
- **Prowlarr search** — search across every tracker and indexer configured in Prowlarr, with a live indexer dropdown that syncs from Prowlarr automatically. Add results with one click.
- **Directory manager** — browse, rename, move, extract (zip/rar), SFV-check, and generate `.torrent` files (via `mktorrent`).
- **In-browser HTML5 player** — stream media directly with seeking (HTTP range); browser-native formats play inline, others offer a download.
- **Multi-user** — per-user accounts, permissions, transfer profiles, quotas (xfer limits), and audit logging.
- **Modern packaging** — Composer-managed dependencies and a Docker Compose stack (web + qBittorrent + Prowlarr) that runs out of the box.
- **PHP 8.5 clean** — the entire legacy codebase was ported off PHP 5-isms (curly-brace offsets, `ereg`/`split`, `each()`, magic quotes, PHP-4 constructors, etc.).

---

## Quick start (Docker)

```bash
git clone https://github.com/JawshTheDark/torrentflux-ng.git fluxtorrent
cd fluxtorrent
docker compose up -d
```

Then:

1. Open `http://localhost:8000/setup.php` and complete first-run setup (SQLite DB, download path `/downloads/`).
2. Log in — the first login creates the superadmin account.
3. **Admin → Transfer Settings** → pick your **Active Backend** and fill in its connection details. For the bundled qBittorrent, set the URL to `http://qbittorrent:8081`, user `admin`, and the temporary password from `docker compose logs qbittorrent`.
4. **Admin → Search Settings** → set the Prowlarr URL to `http://prowlarr:9696` and the API key from *Prowlarr → Settings → General*.
5. Add some indexers in Prowlarr, and you're ready to search and download.

The compose file exposes the web UI on `:8000`, qBittorrent on `:8081`, and Prowlarr on `:9696`.

---

## Torrent backends

Choose the **Active Backend** under *Admin → Transfer Settings*. Only the selected client's connection details need to be filled in; existing transfers keep whichever client they were started with.

| Backend | Connect via | Notes |
| --- | --- | --- |
| **qBittorrent** | Web API URL + user/password | Web UI must be enabled (*Tools → Options → Web UI*). v4 and v5 supported. |
| **Transmission** | RPC host / port / user / password | Enable RPC in Transmission's settings. |
| **Deluge** | `deluge-web` URL (default `:8112`) + password | `deluge-web` must be running and connected to a daemon. |

The client and its download path should point at the same storage FluxTorrent serves (a shared volume in Docker) so the file manager and player can see completed downloads.

## Configuration notes

- **Page title / branding** — set your instance's display name under *Admin → WebApp Settings → Default Page Title*.
- **Same Docker network** — use the service name and internal port (e.g. `http://qbittorrent:8081`, `transmission`, `http://deluge:8112`), not a public URL.
- **Seed ratio** — the *Seed Ratio Limit* on Transfer Settings is a percentage: `100` = 1:1, `200` = 2:1, `0` = seed forever. It's enforced through the client's own seed-ratio limit.
- **File permissions** — the web container runs as uid/gid 33 (`www-data`); the compose file sets the torrent client's `PUID/PGID` to match so the shared `/downloads` volume lines up.

---

## Credits & license

FluxTorrent stands on the shoulders of the original **TorrentFlux** and **TorrentFlux-b4rt** projects and the **TorrentFlux-NG** maintenance work by [Epsylon3](https://github.com/epsylon3). The RedRound theme is by Kanesodi & Epsylon3, based on the `thk tf-b4rt` theme. Full attribution is preserved in [`docs/CREDITS`](docs/CREDITS).

Like its ancestors, FluxTorrent is released under the **GNU General Public License v2** — see [`COPYING`](COPYING).
