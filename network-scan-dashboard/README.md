# Network Scan Dashboard

An automated home network monitoring tool that periodically scans a local subnet with `nmap`, stores results in SQLite, and displays them through a password-protected web dashboard with basic risk flagging.

## Overview

This project runs on a single Ubuntu VM. Every 5 minutes, a cron job triggers an `nmap` scan of a configured network range, parses the results, and stores them in a SQLite database. A PHP dashboard reads from that database and displays live and historical scan results, with open ports color-coded by risk level. Access is restricted to the local network and protected by HTTPS + Basic Authentication.

**Architecture at a glance:**
```
cron (every 5 min)
   -> network_scan.sh
        -> nmap -F -sS -oX output.xml   (scan target subnet)
        -> parse_netscan.py             (parse XML, classify risk, insert into DB)
        -> netscan.db (SQLite)
   -> Apache + PHP (index.php)          (reads DB, renders dashboard)
        -> served over HTTPS, Basic Auth, ufw-restricted to LAN
   -> backup_db.sh (daily, 3am cron)    (gzip snapshot of DB, local backups/)
```

## Prerequisites

Tested on Ubuntu (Desktop or Server). You will need:

- `nmap` — performs the network scans
- `python3` (3.x, standard library only — uses `xml.etree.ElementTree` and `sqlite3`, no extra pip packages required)
- `sqlite3` (both the CLI tool and PHP's built-in SQLite3 extension)
- `apache2` — serves the dashboard
- `php` + `php-sqlite3` module — runs the dashboard backend
- `ufw` — host firewall, used to restrict access to the local subnet
- `fail2ban` — bans IPs after repeated failed login attempts
- `git` — for version control of scripts/config
- `openssl` (or a real certificate) — for HTTPS on the dashboard

Install everything in one pass:
```bash
sudo apt update
sudo apt install nmap python3 sqlite3 apache2 php libapache2-mod-php php-sqlite3 ufw fail2ban git
```

## Setup Instructions

### 1. Clone the repository
```bash
git clone <your-repo-url> netscan
cd netscan
```

### 2. Create the working directory and dedicated group
```bash
sudo mkdir -p /opt/netscan/backups
sudo groupadd netscan
sudo usermod -aG netscan www-data
```

### 3. Copy scripts into place
```bash
sudo cp network_scan.sh backup_db.sh parse_netscan.py /usr/local/bin/
sudo chmod +x /usr/local/bin/network_scan.sh /usr/local/bin/backup_db.sh /usr/local/bin/parse_netscan.py

sudo cp index.php health.php /var/www/html/
```

### 4. Create the configuration file
Create `/opt/netscan/scanner.conf` with your target network range:
```bash
sudo tee /opt/netscan/scanner.conf > /dev/null <<'EOF'
NETWORK_RANGE="192.168.50.0/24"
EOF
```
Replace `192.168.50.0/24` with your own local subnet (check with `ip a` / `ip route`).

### 5. Lock down permissions
```bash
sudo chown root:netscan /opt/netscan/scanner.conf
sudo chmod 640 /opt/netscan/scanner.conf

sudo chown root:netscan /opt/netscan
sudo chmod 750 /opt/netscan/backups
```
See `REFLECTION.md` / Bonus Task A for why `777` is avoided throughout this project in favor of scoped group ownership and minimal permissions.

### 6. Initialize the database
```bash
sqlite3 /opt/netscan/netscan.db <<'EOF'
CREATE TABLE IF NOT EXISTS scans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_time TEXT NOT NULL,
    ip_address TEXT NOT NULL,
    hostname TEXT,
    mac_address TEXT,
    open_ports TEXT,
    risk TEXT
);
PRAGMA auto_vacuum = INCREMENTAL;
EOF
sudo chown root:netscan /opt/netscan/netscan.db
sudo chmod 640 /opt/netscan/netscan.db
```

### 7. Configure Apache (HTTPS + Basic Auth + local-only access)
- Set up a certificate (self-signed is fine for a LAN tool):
  ```bash
  sudo mkdir -p /etc/apache2/ssl
  sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/apache2/ssl/netscan.key \
    -out /etc/apache2/ssl/netscan.crt
  ```
- Create a Basic Auth credentials file:
  ```bash
  sudo htpasswd -c /etc/apache2/.htpasswd youruser
  ```
- Configure your vhost (`/etc/apache2/sites-available/000-default-ssl.conf`) to:
  - redirect port 80 to 443
  - require the `.htpasswd` credentials on `/var/www/html`
  - restrict access via `Require ip <your-subnet>/24`
  - log requests with the authenticated username (`%u` in `LogFormat`)
- Enable required modules and the site, then restart:
  ```bash
  sudo a2enmod ssl headers
  sudo a2ensite 000-default-ssl
  sudo a2dismod autoindex
  sudo systemctl restart apache2
  ```

### 8. Restrict network access with ufw
```bash
sudo ufw allow from 192.168.50.0/24 to any port 80 proto tcp
sudo ufw allow from 192.168.50.0/24 to any port 443 proto tcp
sudo ufw limit from 192.168.50.0/24 to any port 80 proto tcp
sudo ufw limit from 192.168.50.0/24 to any port 443 proto tcp
sudo ufw enable
sudo ufw reload
```
Replace `192.168.50.0/24` with your own subnet. This restricts the dashboard to your local network only — see Bonus Task B in the report for the reasoning.

**Important:** always scope `ufw limit`/`ufw allow` rules with `from <subnet>`. Running `sudo ufw limit 80/tcp` with no `from` clause defaults to allowing "Anywhere" — this was actually caught as a real bug during this project's own final review (see `REFLECTION.md`), where a leftover unscoped rule from early setup silently coexisted with the correct subnet-scoped one. Always double check with `sudo ufw status verbose` that every rule shows your subnet, not "Anywhere."

### 9. Set up fail2ban for repeated failed logins
```bash
sudo tee /etc/fail2ban/jail.local > /dev/null <<'EOF'
[apache-auth]
enabled = true
filter = apache-auth
logpath = /var/log/apache2/access-ssl.log
maxretry = 6
bantime = 1800
EOF
sudo systemctl restart fail2ban
```

### 10. Schedule the cron jobs
```bash
sudo crontab -e
```
Add:
```
*/5 * * * * /usr/local/bin/network_scan.sh
0 3 * * *   /usr/local/bin/backup_db.sh
```

### 11. Set up log rotation
```bash
sudo tee /etc/logrotate.d/netscan > /dev/null <<'EOF'
/opt/netscan/scan.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
EOF
```

### 12. Verify it's working
```bash
# trigger a scan manually
sudo /usr/local/bin/network_scan.sh

# check results landed in the DB
sqlite3 /opt/netscan/netscan.db "SELECT * FROM scans ORDER BY id DESC LIMIT 5;"

# check the dashboard
curl -u youruser:yourpass https://<your-vm-ip>/ -k
```
Then visit `https://<your-vm-ip>/` from a browser on the same local network.

## Verification Checklist

The commands below confirm every major piece of the system is actually working, not just configured. Useful for a fresh setup or before submitting/demoing the project.

```bash
# Cron is scheduled correctly
sudo crontab -l

# Scans are actually running unattended (look for repeated SUCCESS entries ~5 min apart)
tail -20 /opt/netscan/scan.log

# Scan data is landing in the database
sqlite3 /opt/netscan/netscan.db "SELECT scan_time, ip_address, risk FROM scans ORDER BY id DESC LIMIT 5;"

# HTTP redirects to HTTPS (credentials should never be sent unencrypted)
curl -I http://<your-vm-ip>/

# Dashboard requires authentication
curl -I https://<your-vm-ip>/ -k          # expect 401 with no credentials

# Dashboard works with valid credentials
curl -u youruser:yourpass https://<your-vm-ip>/ -k

# Health check returns valid JSON
curl -i -u youruser:yourpass https://<your-vm-ip>/health.php -k

# Firewall only allows your local subnet — no rule should ever say "Anywhere"
sudo ufw status verbose

# Brute-force protection is active
sudo fail2ban-client status apache-auth

# Backups are not reachable over HTTP (expect 404, since they're outside the web root)
curl -u youruser:yourpass https://<your-vm-ip>/backups/ -k

# Version control history reflects real incremental work
cd /opt/netscan && git log --oneline
```

**Note on SSH:** this project does not require or enable SSH access by default. SSH (port 22) was only temporarily enabled once, during development, specifically to generate a live "High Risk" example for the project report (SSH is one of the ports classified as high-risk by `parse_netscan.py`) — it was disabled again immediately afterward. If you see `22/tcp` in your own `ufw status`, it's because SSH is being used to manage the VM remotely, scoped to the local subnet only; it is not part of the scanner's core functionality.

## Repository Structure

```
.
├── network_scan.sh       # cron-triggered scan orchestrator (nmap + parser + DB pruning)
├── parse_netscan.py      # parses nmap XML output, classifies risk, inserts into SQLite
├── backup_db.sh          # daily gzip backup of the SQLite database
├── index.php             # web dashboard (reads DB, renders table with risk highlighting)
├── health.php            # HTTP health-check endpoint (DB connectivity + disk space)
├── .gitignore             # excludes scan data, DB, config, logs, backups from version control
├── README.md              # this file
├── REFLECTION.md          # challenges faced and how they were resolved
└── docs/                  # screenshots and diagrams (proof of functionality)
```

## Security Notes

- Sensitive files (`scanner.conf`, `netscan.db`, backups) are restricted to `640`/`750` with `root:netscan` ownership — never `777`. See `REFLECTION.md` for a full explanation.
- Scan results, raw XML output, and the database are excluded from version control via `.gitignore`, since they contain real local network IPs and hostnames.
- The dashboard is only reachable from the local subnet (enforced at both the `ufw` and Apache `Require ip` layers) and requires HTTPS + per-user Basic Auth.
- Backups live in `/opt/netscan/backups/`, entirely outside the Apache web root (`/var/www/html/`) — they are unreachable over HTTP regardless of directory-listing settings, since there is no served path to them at all.
- Nmap results are inserted into SQLite using parameterized queries (`executemany()` with `?` placeholders) — never raw string concatenation — to prevent SQL injection from device hostnames.
- `health.php` returns `Content-Type: application/json` and reports live database connectivity and free disk space — it does not just confirm PHP is running.
- Every `ufw` rule should be scoped with `from <your-subnet>`. An unscoped rule (e.g. `ufw limit 80/tcp`) defaults to "Anywhere" and was caught as a real leftover bug during this project's own final review — see the Verification Checklist above and `REFLECTION.md`.

## License

This project was built as a learning exercise and is not intended for production security monitoring. Use at your own discretion on networks you own or have permission to scan.
