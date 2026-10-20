# SLU Services

A public services app for residents of Saint Lucia, at **services.digitalsolucians.com**. Built by Digital SoLucians, to be run together with the Government of Saint Lucia.

- **Residents:**
  - sign in to find any public service across ministries, departments and statutory bodies (search, topics, life events, agencies)
  - apply online and track requests
  - change their address once for several agencies
  - link a government ID, once the Government authorises it
- **Agencies:** keep their service information current by sending spreadsheets, documents, web pages, email or API calls. The app turns them into drafts that an officer approves.

**Start here: [SETUP.md](SETUP.md)** has every step, from this repository to the first sign-in.
Sources and the government apps this design draws on: [docs/REFERENCES.md](docs/REFERENCES.md).

## What's in this repo

| Path | What it is |
| --- | --- |
| `index.php` | Front controller: every page and the API go through it. |
| `cron.php` | Background tasks (run every 15 minutes by a Hostinger cron job). |
| `app/` | The app: database and migrations (`schema.php`), starter catalogue (`seed.php`), sign-in and roles (`auth.php`), services and requests (`catalog.php`), intake readers (`intake.php`), mailbox reader (`imap.php`), and `pages/` for each screen. |
| `assets/` | Styles (`app.css`, design tokens at the top), `app.js`, fonts and icons. |
| `data/` | Created on the server: settings, encrypted documents, logs. **Never committed** (see `.gitignore`), never served (`data/.htaccess`). |
| `.htaccess` | HTTPS, blocks `app/`, `data/` and `cron.php` from the web, sends everything else to `index.php`. |
| `.github/workflows/deploy.yml` | Checks every PHP file, then uploads to Hostinger (`public_html/services`) whenever `main` changes. Never deletes files on the server. |

No build step: the files you see are what the server runs. PHP 8.1+ with `pdo_mysql`, `openssl`, `zip`, `dom` and `curl`, and a MySQL/MariaDB database.

## Preview on your computer
```bash
php -S 127.0.0.1:8100 index.php
# open http://127.0.0.1:8100 and follow the setup page (needs a local MySQL/MariaDB database)
```
