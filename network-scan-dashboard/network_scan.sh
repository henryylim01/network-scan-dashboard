#!/bin/bash
# Network Discovery Tool - Automation Engine

umask 027
source /opt/netscan/scanner.conf
OUT_DIR="/opt/netscan"
XML_OUT="$OUT_DIR/output.xml"
LOCKFILE="/tmp/netscan.lock"

# 1. THE TRAP: This guarantees the lockfile is deleted when the script exits, even if it crashes!
trap 'rm -f "$LOCKFILE"' EXIT

# Concurrency lock
if [ -e "$LOCKFILE" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - ERROR: Scan already running." >> "$OUT_DIR/scan.log"
    exit 1
fi
touch "$LOCKFILE"

# 2. Execute nmap and capture its success/fail status
/usr/bin/nmap -F -oX "$XML_OUT" "$NETWORK_RANGE" > /dev/null 2>&1
NMAP_STATUS=$?

# 3. Log the Data-Integrity Flag
if [ $NMAP_STATUS -eq 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - SUCCESS: Nmap scan completed." >> "$OUT_DIR/scan.log"
    
    # Only parse and insert if Nmap actually succeeded
    if [ -f "$XML_OUT" ]; then
        /usr/bin/python3 /usr/local/bin/parse_netscan.py "$XML_OUT"
    fi
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') - FAILED: Nmap encountered an error." >> "$OUT_DIR/scan.log"
fi

# Prune database records older than 7 days to prevent bloat
/usr/bin/sqlite3 "$OUT_DIR/netscan.db" "DELETE FROM scans WHERE scan_time <= datetime('now', '-7 days'); PRAGMA incremental_vacuum;"