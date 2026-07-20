# SGI-Creator — Simple Game Interface, Creator

A minimal, **stateless** companion tool for [SGI](../DockerGameInterface). Pick a
template in the web UI, press **Start**, and SGI-Creator creates and starts a new
game-server Docker container that SGI can then manage (console, backups, power).

Like SGI it runs in a single PHP 8.3 + Apache container, talks to the host Docker
engine over the socket, and keeps **no database and no sessions** — every answer
comes from the live Docker runtime and a directory listing of the templates
folder.

- **Namespace:** `tk\weslie\SgiCreator`
- **Frontend:** the single-page `public/index.html` (vanilla HTML/CSS/JS, no libs)
- **Auth:** password only (no username). The panel password is an environment
  variable; the session token is a stateless HMAC of it.

---

## How it works

1. **Templates** live in `templates/`. Each file is a normal `docker-compose`
   file that doubles as a template. Its catalogue metadata sits in the leading
   `#Key = Value` comment block. A file is only an SGI template if it carries
   **`#SGI = true`** — otherwise it is ignored (never listed, never creatable), so
   the folder can also hold plain compose files.

2. **Placeholders** in the compose body are filled in by PHP at create time:

   | Placeholder     | Replaced with                                                        |
   |-----------------|----------------------------------------------------------------------|
   | `%%port%%`      | A **free host port** (found via Docker). Each occurrence gets its own.|
   | `%%path%%`      | The per-server host data path `<SGIC_VOLUME_ROOT>/<token>`.           |
   | `%%sgi.token%%` | A fresh, unique **server token** in the form `######-####-####`.      |

3. **Create + start.** SGI-Creator pulls the image if missing, creates the
   container, starts it, and returns the confirmation (name, address, token,
   optional SGI deep link). If a host port turns out to be taken (even by a
   non-Docker process), it blocks that port and retries with the next free one.

4. **Manage in SGI.** Because the container is labelled with `sgi.token`, it logs
   straight into the SGI panel with the token shown in the confirmation.

---

## Template format

```yaml
#SGI = true
#Name = Minecraft Java — Paper
#Game = Minecraft
#Version = 1.21.4
#Description = High-performance Spigot fork with plugin support
#Icon = 🧱
#Tags = plugins, spigot, java

services:
  minecraft:
    image: itzg/minecraft-server
    stdin_open: true            # SGI console attaches to stdin
    tty: false
    restart: unless-stopped
    ports:
      - "%%port%%:25565"
    environment:
      EULA: "TRUE"
      TYPE: "PAPER"
    volumes:
      - "%%path%%:/data"
      - "sgi_backup:/backup"    # shared SGI backup volume
    labels:
      - sgi.token=%%sgi.token%%
      - sgi.name=Minecraft Paper
      - sgi.backup.path=/data
```

### Header keys

| Key            | Required | Meaning                                                        |
|----------------|:--------:|----------------------------------------------------------------|
| `#SGI`         |    ✅     | `true` marks the file as an SGI template. Anything else hides it.|
| `#Name`        |    –     | Display name in the picker (default: the file name).           |
| `#Game`        |    –     | Group heading in the picker (default: `Other`).                |
| `#Version`     |    –     | Shown as a chip / in the detail.                               |
| `#Description` |    –     | One-line description.                                          |
| `#Icon`        |    –     | An emoji (default: 🎮).                                        |
| `#Tags`        |    –     | Comma-separated, searchable + shown as chips.                  |
| `#Image`       |    –     | Overrides the `image:` shown in the detail (else read from the compose). |

The template **id** is the file name without extension (e.g. `minecraft-paper`).
Exactly **one** service per file (one template = one container).

> Tip: keep `stdin_open: true` and mount the shared `sgi_backup` volume at
> `/backup` so SGI's live console and backups work out of the box.

---

## Quick start

```bash
# 1. (Once) create the shared backup volume SGI and the game containers use.
docker volume create sgi_backup

# 2. Run SGI-Creator (serves on http://localhost:8090).
docker compose up -d --build
#    …or with docker run:
docker run -d --name sgi-creator \
  -p 8090:80 \
  -e SGIC_PASSWORD='choose-a-strong-password' \
  -e SGIC_VOLUME_ROOT='/home/GameServerVolumes' \
  -v /var/run/docker.sock:/var/run/docker.sock \
  tk-weslie/sgi-creator:latest
```

Open `http://localhost:8090`, sign in with the password, pick a template, Start.

---

## Configuration (environment variables)

| Variable            | Required | Default                    | Purpose                                                    |
|---------------------|:--------:|----------------------------|------------------------------------------------------------|
| `SGIC_PASSWORD`     |    ✅     | –                          | The panel login password. Login is disabled until set.     |
| `SGIC_VOLUME_ROOT`  |    –     | `/home/GameServerVolumes`  | Host root for `%%path%%` binds (`<root>/<token>`).          |
| `SGIC_HOST`         |    –     | the request host           | Hostname shown in the connection **address**.              |
| `SGIC_SGI_URL`      |    –     | –                          | SGI panel URL for the "Open in SGI" link in the dialog.    |
| `SGIC_PORT_MIN`     |    –     | `20000`                    | Start of the free-host-port scan window.                   |
| `SGIC_PORT_MAX`     |    –     | `40000`                    | End of the free-host-port scan window.                     |
| `SGIC_TEMPLATE_DIR` |    –     | `<app>/templates`          | Templates folder.                                          |
| `DOCKER_SOCKET`     |    –     | `/var/run/docker.sock`     | Docker Unix socket.                                        |

---

## API

`Content-Type: application/json`. Every endpoint except `POST /api/auth` requires
`Authorization: Bearer <session token>`.

| Method | Path              | Purpose                                                             |
|--------|-------------------|---------------------------------------------------------------------|
| POST   | `/api/auth`       | `{password}` → `{token}`. 401 on a wrong password.                   |
| GET    | `/api/templates`  | Array of SGI templates for the picker (hidden ones excluded).       |
| POST   | `/api/containers` | `{templateId}` → creates **and starts**; 201 with `{templateName,name,address,token,sgiUrl?}`. |

Errors are `{ "error": "<sentence>", "code": "<machine code>" }` with the HTTP
status the frontend maps to its wording (the `error` text always wins).

---

## Security notes

- The password guards **web** access, not host access — anyone with Docker access
  on the host can read the container labels. Use a long, random password.
- The session token is derived from the password and does **not** rotate; leaking
  it is equivalent to leaking the password.
- **TLS is out of scope.** Run behind a TLS-terminating reverse proxy.
- Mounting `docker.sock` grants the container full control of the host Docker
  engine. Treat the SGI-Creator host as trusted infrastructure.
