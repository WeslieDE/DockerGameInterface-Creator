# KONZEPT — SGI-Creator (Simple Game Interface, Creator)

**Projektname:** SGI-Creator
**Namespace:** `tk\weslie\SgiCreator`
**Zweck:** Zusatztool zu [SGI](../DockerGameInterface). Über eine Weboberfläche
werden aus **Vorlagen** neue Gameserver-Docker-Container gestartet, die SGI
anschließend verwaltet.

---

## 1. Leitprinzipien

- **Stateless** — keine Datenbank, keine Server-Sessions. Alle Daten kommen zur
  Laufzeit aus:
  - **Docker Runtime** (belegte Ports, Token-Kollisionen, Container-Erstellung)
  - **Directory-Listing** des Template-Ordners
- **Passwort-Login** — kein Username. Das Panel-Passwort kommt als
  Umgebungsvariable `SGIC_PASSWORD` (per `-e` beim Start, wie bei SGI). Der
  Session-Token ist ein stateless HMAC des Passworts (deterministisch, per
  Neuberechnung geprüft).
- **Template ist Wahrheit** — der Template-Autor besitzt die komplette
  Compose-Datei. SGI-Creator füllt nur die drei Platzhalter und garantiert das
  `sgi.token`-Label, damit der zurückgegebene Token in SGI funktioniert.

---

## 2. Technologie-Stack

| Ebene        | Wahl                                                            |
|--------------|-----------------------------------------------------------------|
| Frontend     | `public/index.html` (Vanilla HTML/CSS/JS, keine Libs)           |
| Backend      | **PHP 8.3**, PSR-4 unter `tk\weslie\SgiCreator\`               |
| Webserver    | `php:8.3-apache` (ein Container)                                |
| YAML         | native `ext-yaml` (aus PECL im Image gebaut)                    |
| Docker       | Engine API über Unix-Socket via cURL (`CURLOPT_UNIX_SOCKET_PATH`)|
| Persistenz   | **keine** — Runtime-Daten + Template-Ordner                     |

---

## 3. Templates

- Jede `*.yml`/`*.yaml`-Datei im Template-Ordner = eine Vorlage = eine
  Compose-Datei mit **genau einem** Service.
- Die Katalog-Metadaten stehen im führenden `#Key = Value`-Kommentarblock.
- **Marker `#SGI = true`**: fehlt er, ist die Datei keine SGI-Vorlage — sie wird
  von `GET /api/templates` **nicht gelistet** und ist nicht erstellbar. So kann
  der Ordner auch normale Compose-Dateien enthalten.
- Header-Schlüssel: `SGI, Name, Game, Version, Description, Icon, Tags, Image`
  (siehe README). Die **id** = Dateiname ohne Endung.

### Platzhalter (vom PHP generiert)

| Platzhalter     | Ersetzt durch                                                     |
|-----------------|-------------------------------------------------------------------|
| `%%port%%`      | Freier Host-Port (**über Docker** ermittelt). Jedes Vorkommen erhält einen eigenen freien Port; ist ein Port belegt, wird der nächsthöhere genommen. |
| `%%path%%`      | Host-Bind-Pfad pro Server: `<SGIC_VOLUME_ROOT>/<token>`.          |
| `%%sgi.token%%` | Frischer, eindeutiger Server-Token im Format `######-####-####` (6-4-4). |

---

## 4. Ablauf „Container erstellen"

1. Token generieren (6-4-4, gegen laufende Container auf Eindeutigkeit geprüft).
2. `%%path%%` = `SGIC_VOLUME_ROOT/<token>`.
3. Belegte Host-Ports **über Docker** einlesen (`GET /containers/json`).
4. Schleife (bounded retry):
   - Freie Ports aufwärts zuweisen (belegte überspringen).
   - Platzhalter ersetzen → YAML parsen → Docker-Create-Spec bauen.
   - Image sicherstellen (`ensureImage`, ggf. `pull`).
   - Container erstellen + starten.
   - **Erfolg** → Bestätigung `{templateName, name, address, token, sgiUrl?}`.
   - **Port-Konflikt** (auch durch Nicht-Docker-Prozess) → Container entfernen,
     Ports blocken, nächsthöhere probieren.
5. Antwort über die API an das Frontend (201 = läuft; sonst gemappter Fehler).

---

## 5. API (Contract steht in `index.html`)

| Methode | Pfad              | Funktion                                                    |
|---------|-------------------|-------------------------------------------------------------|
| POST    | `/api/auth`       | `{password}` → `{token}`. 401 bei falschem Passwort.        |
| GET     | `/api/templates`  | Array der SGI-Templates (versteckte ausgeschlossen).        |
| POST    | `/api/containers` | `{templateId}` → erstellt **und startet**; 201 mit Bestätigung. |

Fehler: `{error, code}` mit passendem HTTP-Status; das Frontend zeigt `error`
wörtlich an.

---

## 6. Verzeichnisstruktur

```
SGI-Creator/
├── README.md
├── KONZEPT.md
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── composer.json
├── templates/                 # eine Datei = ein Template (Compose + #Header)
│   ├── minecraft-paper.yml
│   ├── valheim.yml
│   └── plain-nginx.yml         # kein #SGI-Marker → wird ignoriert
├── public/
│   ├── index.html              # Frontend (statisch)
│   ├── .htaccess               # /api/* → api/index.php
│   └── api/index.php           # Front-Controller / Verdrahtung aus ENV
└── src/                        # tk\weslie\SgiCreator\  (PSR-4)
    ├── Http/{Router,HttpException}.php
    ├── Auth/PasswordAuth.php
    ├── Docker/{DockerClient,DockerException}.php
    ├── Compose/ComposeParser.php      # behält Volume-Source (anders als SGI)
    ├── Template/{Template,TemplateRepository}.php
    └── Service/CreatorService.php     # Platzhalter, Ports, Token, Create
```

---

## 7. Sicherheit

- Passwort schützt **Web**-Zugriff, nicht Host-Zugriff (Labels sind auf dem Host
  lesbar). Langes, zufälliges Passwort verwenden.
- Session-Token wird aus dem Passwort abgeleitet und **rotiert nicht**.
- **TLS out of scope** — hinter TLS-terminierendem Reverse-Proxy betreiben.
- `docker.sock` = volle Kontrolle über die Host-Docker-Engine. Host als
  vertrauenswürdige Infrastruktur behandeln.
