# ARISE + DataPost — Offline Health Education Platform

**A**dolescent **R**eproductive Health **I**nformation **S**upport and **E**mpowerment

A fully offline health education platform with built-in DataPost data sync system for Ubuntu 24.04 LTS servers.

## Architecture

```
Ubuntu 24.04 LTS Server (Apache + PHP + SQLite)
├── /public/          → Student-facing website (modules, quizzes, question box)
├── /admin/           → Admin panel (manage content, answer questions, setup)
├── /datapost/        → DataPost courier interface (pickup/deliver data)
├── /includes/        → Core PHP config and database helpers
├── /data/            → SQLite database, uploads, data bundles
└── /setup/           → Installation script
```

## Quick Install

```bash
# On a fresh Ubuntu 24.04 LTS machine:
git clone <repo> /tmp/arise
cd /tmp/arise
sudo bash setup/install.sh
```

## Access Points

| URL | Purpose |
|-----|---------|
| `http://[server-ip]/` | Student interface |
| `http://[server-ip]/admin/` | Admin panel |
| `http://[server-ip]/datapost/` | DataPost courier interface |

## Default Login
- Username: `admin`
- Password: `arise2026` (change after first login!)

## DataPost Workflow

1. **Server logs everything** — page views, quiz scores, questions asked (all anonymous)
2. **Courier visits school** — connects to server WiFi
3. **Opens DataPost** — `http://[server-ip]/datapost/`
4. **Downloads data bundle** — JSON file with all usage stats
5. **Back online** — uploads JSON to central dashboard
6. **Optional** — courier can also upload content updates to the server

## Tech Stack

- **PHP 8.x** — backend logic
- **SQLite3** — database (zero config, no MySQL needed)
- **Apache2** — web server
- **Vanilla HTML/CSS/JS** — no frameworks, fast on any device

## Features

### Student Side
- Browse 11 health education modules
- Read lesson content
- Take interactive quizzes with instant feedback
- Submit anonymous questions to educators
- View helpline numbers and resources

### Admin Side
- Dashboard with usage statistics
- Add/manage lessons and quiz questions
- Answer anonymous student questions
- Configure school details for DataPost
- Change admin password

### DataPost
- Local stats dashboard
- Data bundle generation (JSON)
- Courier pickup with date range selection
- Content delivery upload
- Full activity log

## Project by
- **World Possible Kenya** — worldpossiblekenya.org
- **M.T.T.I** — Masomotele Technical Training Institute
