#!/bin/bash
# Copies all cloud saves from the old Hostinger server straight to Backblaze (nothing lands on this VPS).
# Uses the open SSH connection to Hostinger and the Backblaze key saved in admin. Runs in the background.
set -euo pipefail
command -v rclone >/dev/null && rclone version | head -1 | grep -qE 'v1\.(6[4-9]|[7-9][0-9])' || curl -s https://rclone.org/install.sh | bash >/dev/null
ssh -p 65002 -o ControlPath=/tmp/hostinger-%r -O check u880103508@156.67.76.103 2>/dev/null || { echo "NEED-PASSWORD: the Hostinger connection closed"; exit 2; }
ENVF=/root/.b2env; umask 077
sudo -u www-data php -r 'chdir("/var/www/recompstore/public_html/admin"); $_SERVER["HTTP_HOST"]="recompstore.com"; $_SERVER["HTTPS"]="on"; require "inc/bootstrap.php"; require_once "inc/b2.php"; $c = rs_b2_cfg(); if (!$c) exit(1);
echo "export RCLONE_CONFIG_B2_TYPE=s3 RCLONE_CONFIG_B2_PROVIDER=Other RCLONE_CONFIG_B2_ENDPOINT=https://{$c["endpoint"]} RCLONE_CONFIG_B2_ACCESS_KEY_ID=", escapeshellarg($c["key_id"]), " RCLONE_CONFIG_B2_SECRET_ACCESS_KEY=", escapeshellarg($c["key"]), " B2_BUCKET=", escapeshellarg($c["bucket"]), "\n";' > $ENVF
cat > /root/b2-copy-run.sh <<'RUN'
#!/bin/bash
source /root/.b2env
export RCLONE_CONFIG_OLDS_TYPE=sftp RCLONE_CONFIG_OLDS_SSH="ssh -p 65002 -o ControlPath=/tmp/hostinger-%r u880103508@156.67.76.103"
rclone copy olds:domains/recompstore.com/recompstore-private/cloud-saves "b2:$B2_BUCKET/cloud-saves" --transfers 16 --checkers 32 --stats 30s --stats-one-line --stats-log-level NOTICE --log-file /root/b2-copy.log --log-level NOTICE
echo "EXIT $?" >> /root/b2-copy.log
RUN
chmod 700 /root/b2-copy-run.sh
systemctl reset-failed b2copy 2>/dev/null || true
systemd-run --unit=b2copy --quiet /root/b2-copy-run.sh
sleep 5; systemctl is-active b2copy; tail -2 /root/b2-copy.log 2>/dev/null
echo "STARTED. Progress: tail -1 /root/b2-copy.log"
