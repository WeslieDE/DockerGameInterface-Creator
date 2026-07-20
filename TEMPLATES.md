# SGI-Creator — Template-Handbuch

Alles, was du zum Anlegen neuer Templates brauchst: Aufbau, Platzhalter,
unterstützte Compose-Felder, Eigenheiten und Fallstricke. Dieses Dokument
beschreibt exakt das Verhalten des Codes (`TemplateRepository`, `ComposeParser`,
`CreatorService`).

---

## 1. Was ein Template ist

Ein Template ist **eine ganz normale `docker-compose`-Datei**, die zusätzlich
einen **Kommentar-Header** mit Metadaten trägt. Aus jeder Datei kann SGI-Creator
genau **einen** Container erstellen und starten.

- Ablageort: der Template-Ordner (`SGIC_TEMPLATE_DIR`, Default `/var/www/html/templates`).
  Per Bind-Mount überlagerbar (siehe README).
- Dateiendung: **`.yml`** oder **`.yaml`** (alles andere wird ignoriert).
- Dateikodierung: **UTF-8** (wichtig für Emojis/Umlaute im Header).
- Die **id** eines Templates = Dateiname ohne Endung (z.B. `minecraft-paper.yml`
  → id `minecraft-paper`). Sie muss eindeutig sein — Dateinamen sind es.

> **Genau ein Service pro Datei.** Eine Compose-Datei mit 0 oder mehreren
> Services unter `services:` wird abgelehnt (HTTP 422).

---

## 2. Der Header (Metadaten)

Die Metadaten stehen als **führender, zusammenhängender Kommentarblock** ganz oben
in der Datei. Das Einlesen stoppt bei der **ersten Zeile, die weder leer ist noch
mit `#` beginnt** (also sobald der YAML-Body anfängt).

```yaml
#SGI = true
#Name = Minecraft Java — Paper
#Game = Minecraft
#Version = 1.21.4
#Description = High-performance Spigot fork with plugin support
#Icon = 🧱
#Tags = plugins, spigot, java
```

### Erlaubte Schreibweisen

Jede Zeile: `#` + Schlüssel + `=` oder `:` + Wert. Diese Varianten sind alle gültig:

```
#Name = Minecraft
#Name: Minecraft
# Name=Minecraft
#name = minecraft        (Schlüssel sind case-insensitive)
```

- Leerzeilen **innerhalb** des Headers sind erlaubt.
- Kommentare, die SGI-Creator nicht kennt, werden ignoriert (du kannst also
  freie `#`-Kommentare dazwischen schreiben).
- Kommentare **nach** dem YAML-Body werden **nicht** als Metadaten gelesen — nur
  der Block ganz oben zählt.

### Schlüssel

| Schlüssel      | Pflicht | Default              | Wirkung im Webinterface                                   |
|----------------|:-------:|----------------------|-----------------------------------------------------------|
| `#SGI`         |   ✅    | –                    | `true`/`1`/`yes`/`on` = Template aktiv. Sonst versteckt.   |
| `#Name`        |   –     | Dateiname (id)       | Anzeigename (linke Spalte im Dropdown, Detail).           |
| `#Game`        |   –     | `Other`              | Gruppen-Überschrift im Dropdown.                          |
| `#Version`     |   –     | *(leer)*             | Chip + Detail (rechts).                                    |
| `#Description` |   –     | *(leer)*             | Kurzbeschreibung (rechts + Detail).                       |
| `#Icon`        |   –     | 🎮 (im Frontend)     | Ein Emoji.                                                 |
| `#Tags`        |   –     | *(keine)*            | Komma-getrennt; durchsuchbar + als Chips.                 |
| `#Image`       |   –     | aus `image:` gelesen | **Nur Anzeige** im Detail. Siehe Eigenheit unten.         |

**Suche:** Das Dropdown durchsucht wortweise `Name`, `Game`, `Description` und
`Tags`. „mc java" findet also einen Eintrag mit Name „Minecraft Java".

---

## 3. Der SGI-Marker (`#SGI = true`)

- **Ohne `#SGI = true` ist die Datei kein SGI-Template.** Sie wird von
  `GET /api/templates` nicht gelistet und ist nicht erstellbar.
- Das ist Absicht: Der Ordner darf auch ganz normale Compose-Dateien enthalten,
  die SGI-Creator einfach ignoriert.
- Als „true" zählen (case-insensitive): `true`, `1`, `yes`, `on`. Alles andere
  (auch `#SGI = false` oder ein fehlender Marker) → **versteckt**.

---

## 4. Die drei Platzhalter

Im Compose-Body werden zur Erstellungszeit vom PHP genau drei Platzhalter ersetzt:

| Platzhalter     | Wird ersetzt durch                                                                 |
|-----------------|-------------------------------------------------------------------------------------|
| `%%port%%`      | Ein **freier Host-Port** (über Docker ermittelt).                                   |
| `%%path%%`      | Der Host-Datenpfad dieses Servers: `<SGIC_VOLUME_ROOT>/<token>`.                     |
| `%%sgi.token%%` | Ein frischer, eindeutiger **Server-Token** im Format `######-####-####` (6-4-4).     |

### `%%port%%` — Eigenheiten

- **Jedes Vorkommen bekommt einen eigenen, unterschiedlichen freien Port**, in der
  Reihenfolge des Auftretens. Zwei `%%port%%` → zwei verschiedene Ports.
- „Frei" wird **über Docker** bestimmt: belegte Host-Ports laufender Container
  werden übersprungen. Ist ein Port durch einen **Nicht-Docker-Prozess** belegt
  (Start schlägt fehl), blockt SGI-Creator ihn und nimmt den nächsthöheren.
- Der Suchbereich ist `SGIC_PORT_MIN`…`SGIC_PORT_MAX` (Default **20000–40000**).
- Die **Adresse**, die dem Spieler angezeigt wird, ist Host + `:` + **erster**
  veröffentlichter Port (`ports[0]`).

> ⚠️ **Gleicher Port auf TCP *und* UDP:** Wenn ein Spiel denselben Portnummer auf
> `tcp` und `udp` braucht, funktioniert `%%port%%` **nicht**, weil jedes Vorkommen
> eine andere Nummer bekommt. Aktuell nicht automatisch lösbar — dann entweder
> einen festen Port hart eintragen (kein Auto-Free-Port) oder das Spiel auf einen
> Port beschränken.

### `%%path%%` — Eigenheiten

- Wird zu **einem Host-Bind-Pfad**: `<SGIC_VOLUME_ROOT>/<token>` (Default-Root
  `/home/GameServerVolumes`). Der Docker-Daemon legt das Verzeichnis auf dem
  **Host** automatisch an; die Daten überleben den Container dort.
- **Alle `%%path%%` in einer Datei ergeben denselben Pfad.** Brauchst du
  Unterverzeichnisse, hänge sie an: `"%%path%%/world:/data"`,
  `"%%path%%/plugins:/plugins"`.

### `%%sgi.token%%` — Eigenheiten

- Format **6-4-4**, Zeichensatz `A–Z` + `2–9` (ohne die verwechselbaren
  `0/O/1/I`), z.B. `X8LFVW-PNE6-ETKE`.
- Wird gegen laufende Container auf Eindeutigkeit geprüft.
- Gehört in das Label `sgi.token`, damit der Server sich später in SGI einloggen
  lässt (siehe Abschnitt 7).

> **YAML-Tipp:** Setze Werte mit Platzhaltern in **Anführungszeichen**, das ist
> immer sicher und lesbar: `"%%port%%:25565"`, `"%%path%%:/data"`,
> `sgi.token=%%sgi.token%%` (in Label-Listenform ohne Quotes ebenfalls ok).

---

## 5. Der Compose-Body — was unterstützt wird

Aus dem einen Service werden **nur** diese Schlüssel ausgewertet:

| Schlüssel        | Verhalten                                                                  |
|------------------|-----------------------------------------------------------------------------|
| `image`          | **Pflicht.** Wird bei Bedarf automatisch gepullt.                          |
| `ports`          | Short- oder Long-Syntax (Details unten). **Port-Ranges verboten.**         |
| `environment`    | Map (`KEY: value`) oder Liste (`- KEY=value`).                             |
| `volumes`        | Short (`src:dst[:ro]`) oder Long (`{source,target,read_only}`).            |
| `command`        | String (→ `/bin/sh -c "…"`) oder Liste (Exec-Form).                        |
| `labels`         | Map oder Liste.                                                            |
| `restart`        | `always` / `unless-stopped` / `on-failure`. Sonst keine Policy.           |
| `stdin_open`     | Default **true** (für die SGI-Konsole). Explizit `false` schaltet ab.      |
| `tty`            | Default **false**.                                                        |
| `container_name` | Wird als **Namens-Basis** verwendet (siehe Eigenheit unten).              |

> ⚠️ **Alles andere wird ignoriert.** Nicht unterstützt und stillschweigend
> verworfen werden u.a.: `networks`, `depends_on`, `healthcheck`, `deploy`
> (inkl. `deploy.resources` → **keine** RAM-/CPU-Limits!), `env_file`, `build`,
> `cap_add`, `devices`, `sysctls`, `ulimits`, `extra_hosts`, `dns`, `user`,
> `working_dir`, `entrypoint`. Was dein Server davon braucht, muss ins Image oder
> über die unterstützten Felder abgebildet werden.

### Ports im Detail

Gültig:
```yaml
ports:
  - "%%port%%:25565"          # host:container
  - "%%port%%:2456/udp"       # mit Protokoll
  - "25565"                   # nur container → host = container
  - "0.0.0.0:%%port%%:25565"  # ip:host:container (IP wird ignoriert, publish auf allen Interfaces)
```
Long-Syntax:
```yaml
ports:
  - target: 25565
    published: "%%port%%"
    protocol: tcp
```
**Verboten:** Ranges wie `"8000-8010"` → HTTP 422.
Fehlt der Host-Teil, wird der Container-Port als Host-Port genommen.

### Volumes im Detail

- **Der Source (linke Seite) bleibt erhalten** — anders als im SGI-Panel. Das ist
  nötig, weil `%%path%%` den echten Host-Pfad liefert.
- **Target muss absolut sein** (`/data`), sonst HTTP 422.
- `:ro` am Ende → read-only.
- Ein benanntes Volume als Source ist erlaubt: `"sgi_backup:/backup"`.
- Ohne Source (`"/data"`) → anonymes Docker-Volume.

```yaml
volumes:
  - "%%path%%:/data"          # Host-Bind (persistent, unter /home/GameServerVolumes/<token>)
  - "sgi_backup:/backup"      # benanntes Volume (SGI-Backups)
  - "%%path%%/config:/config" # Unterordner desselben Host-Pfads
```

### `container_name` — Eigenheit

`container_name` (oder, falls fehlend, der Service-Name) wird nur als **Basis**
genommen, bereinigt (`[a-z0-9_.-]`) und um ein Token-Fragment ergänzt:
`minecraft` → **`minecraft-x8lfvw`**. Der endgültige Name ist also nicht exakt das,
was in der Datei steht — das garantiert Eindeutigkeit.

---

## 6. Konsole, stdin & tty

Damit die **Live-Konsole in SGI** funktioniert (via `docker attach`):

```yaml
stdin_open: true
tty: false
```

SGI-Creator setzt `OpenStdin` standardmäßig auf **true** (auch wenn du es weglässt),
weil die Templates ja für SGI gedacht sind. Willst du das ausdrücklich nicht,
setze `stdin_open: false`.

---

## 7. SGI-Integration (Labels)

Damit der erstellte Container von SGI verwaltet werden kann, gehören diese Labels
ins Template:

```yaml
labels:
  - sgi.token=%%sgi.token%%     # PFLICHT für SGI-Login (wird sonst automatisch injiziert)
  - sgi.name=Minecraft Paper    # Anzeigename in SGI (sonst Container-Name)
  - sgi.backup.path=/data       # Pfad im Container, den SGI sichert
```

- **`sgi.token`**: Falls du es vergisst, injiziert SGI-Creator es automatisch mit
  dem generierten Token — der zurückgegebene Token loggt also immer in SGI ein.
  Trag es trotzdem explizit ein (Klarheit).
- **`sgi.backup.path`**: Verzeichnis im Container, das SGI beim Backup einpackt
  (z.B. `/data`). Ohne Angabe fällt SGI auf das erste Named-Volume zurück.
- **Backups:** Damit SGIs Backups laufen, mounte das gemeinsame Volume
  `sgi_backup` unter `/backup`:  `- "sgi_backup:/backup"`.

---

## 8. Was am Ende passiert (pro Erstellung)

1. Token (6-4-4) generieren, `%%path%%` = `<VOLUME_ROOT>/<token>`.
2. Freie Ports über Docker ermitteln, Platzhalter ersetzen.
3. YAML parsen → Docker-Create-Spec bauen (`sgi.token`-Label wird garantiert).
4. Image sicherstellen (ggf. `pull`), Container **erstellen und starten**.
5. Antwort ans Frontend: `templateName`, `name` (`<basis>-<tokenfragment>`),
   `address` (`Host:ersterPort`), `token`, optional `sgiUrl`.

---

## 9. Vollständiges, kommentiertes Beispiel

```yaml
# ---- Metadaten-Header (führender Kommentarblock) ----
#SGI = true
#Name = Minecraft Java — Paper
#Game = Minecraft
#Version = 1.21.4
#Description = High-performance Spigot fork with plugin support
#Icon = 🧱
#Tags = plugins, spigot, java

# ---- Compose-Body (genau ein Service) ----
services:
  minecraft:
    image: itzg/minecraft-server
    stdin_open: true            # SGI-Konsole
    tty: false
    restart: unless-stopped
    ports:
      - "%%port%%:25565"        # freier Host-Port → Container 25565
    environment:
      EULA: "TRUE"
      TYPE: "PAPER"
      VERSION: "1.21.4"
      MEMORY: "2G"
    volumes:
      - "%%path%%:/data"        # persistente Spieldaten auf dem Host
      - "sgi_backup:/backup"    # gemeinsames SGI-Backup-Volume
    labels:
      - sgi.token=%%sgi.token%%
      - sgi.name=Minecraft Paper
      - sgi.backup.path=/data
```

## 10. Minimal-Beispiel

```yaml
#SGI = true
#Name = Simple TCP Server
#Game = Misc

services:
  app:
    image: ghcr.io/example/app:latest
    ports:
      - "%%port%%:8080"
    volumes:
      - "%%path%%:/data"
    labels:
      - sgi.token=%%sgi.token%%
```

---

## 11. Fallstricke (Checkliste beim Debuggen)

- **Template taucht nicht auf?**
  - Fehlt `#SGI = true`? (häufigster Grund)
  - Endung `.yml`/`.yaml`? Datei im richtigen Ordner? Für `www-data` lesbar?
    (`docker exec <container> ls -la /var/www/html/templates`)
- **Erstellung schlägt fehl (422)?** Meist am Compose-Body:
  - Mehr als ein Service, oder `image:` fehlt.
  - Port-Range benutzt, Volume-Target nicht absolut, ungültiges Port-Mapping.
  - Kaputtes YAML (nach dem Ersetzen der Platzhalter).
- **„Name or port in use" (409)?** Kein freier Port im Fenster
  `SGIC_PORT_MIN..MAX`, oder Namenskollision.
- **Bild/Image nicht gefunden (422 IMAGE_MISSING)?** Image nicht public bzw.
  Daemon ohne Zugangsdaten.
- **Konsole in SGI leer?** `stdin_open: true` + `tty: false` gesetzt?
- **Backups gehen nicht?** `sgi_backup:/backup` gemountet und `sgi.backup.path`
  korrekt?
- **`#Image` ≠ tatsächliches Image:** `#Image` ist nur Anzeige im Picker. Der
  Container nutzt immer das `image:` aus dem Compose-Body — halte beide gleich.
- **RAM/CPU-Limit gesetzt, wirkt aber nicht?** `deploy.resources` wird ignoriert.
  Aktuell gibt es keinen unterstützten Weg für Limits im Template.

---

## 12. Kurz-Referenz der wichtigsten Fehlercodes

| Status | Code (Beispiele)                    | Bedeutung für den Template-Autor                  |
|:------:|-------------------------------------|---------------------------------------------------|
| 404    | `TEMPLATE_GONE`                     | id unbekannt oder Template versteckt.             |
| 422    | `NO_SERVICE` / `MULTI_SERVICE`      | Nicht genau ein Service.                          |
| 422    | `NO_IMAGE`                          | `image:` fehlt.                                   |
| 422    | `PORT_RANGE` / `BAD_PORT`           | Port-Range oder ungültiges Mapping.               |
| 422    | `BAD_VOLUME`                        | Volume-Target nicht absolut.                      |
| 422    | `BAD_YAML`                          | YAML nach dem Ersetzen ungültig.                  |
| 422    | `IMAGE_MISSING`                     | Image nicht gefunden/pullbar.                     |
| 409    | `NO_FREE_PORT` / `NAME_TAKEN`       | Kein freier Port / Namenskollision.               |
| 502    | `DOCKER_ERROR`                      | Docker hat den Container abgelehnt.               |

---

## 13. Schnell-Checkliste für ein neues Template

1. Datei `templates/<id>.yml`, UTF-8.
2. Header mit **`#SGI = true`** und mindestens `#Name`, idealerweise `#Game`,
   `#Version`, `#Description`, `#Icon`, `#Tags`.
3. Genau **ein** Service, mit `image:`.
4. `stdin_open: true`, `tty: false`.
5. `ports: ["%%port%%:<containerport>"]`.
6. `volumes: ["%%path%%:/<datadir>", "sgi_backup:/backup"]`.
7. `labels: [sgi.token=%%sgi.token%%, sgi.name=…, sgi.backup.path=/<datadir>]`.
8. `restart: unless-stopped` (empfohlen).
9. Platzhalter-Werte in `"…"` setzen.
10. Datei ablegen → sollte sofort im Picker erscheinen (stateless, kein Neustart
    nötig).
