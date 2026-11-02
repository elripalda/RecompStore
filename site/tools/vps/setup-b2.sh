#!/bin/bash
# Saves the Backblaze key on this server (outside the website) and tests it. Nothing is shown on screen.
set -euo pipefail
CFG=/var/www/recompstore/recompstore-b2.php
read -r -p "Paste the keyID, then press Enter: " KID
read -r -s -p "Paste the applicationKey (it won't show), then press Enter: " KEY; echo
[ -n "$KID" ] && [ -n "$KEY" ] || { echo "Both are needed."; exit 1; }
KID="$KID" KEY="$KEY" php -r 'file_put_contents(getenv("CFGF") ?: "/var/www/recompstore/recompstore-b2.php.tmp", "<?php\n// Backblaze B2 login for cloud saves. Keep private.\nreturn " . var_export(["endpoint" => "s3.us-west-004.backblazeb2.com", "bucket" => "recompstore-saves", "key_id" => getenv("KID"), "key" => getenv("KEY")], true) . ";\n");'
chown root:www-data $CFG.tmp; chmod 640 $CFG.tmp; mv $CFG.tmp $CFG
mkdir -p /root/.config/rclone
printf '[b2]\ntype = s3\nprovider = Other\nendpoint = https://s3.us-west-004.backblazeb2.com\nacl = private\naccess_key_id = %s\nsecret_access_key = %s\n' "$KID" "$KEY" > /root/.config/rclone/rclone.conf
chmod 600 /root/.config/rclone/rclone.conf
unset KEY
echo "== Testing upload, download and delete =="
cd /var/www/recompstore/public_html/admin
sudo -u www-data php -r 'chdir("/var/www/recompstore/public_html/admin"); $_SERVER["HTTP_HOST"]="recompstore.com"; $_SERVER["HTTPS"]="on"; require "inc/bootstrap.php"; require_once "inc/b2.php";
$t = tempnam(sys_get_temp_dir(), "b2t"); file_put_contents($t, "recomp test ".time());
rs_b2_put("test/hello.txt", $t); echo "upload: ok\n";
$body = @file_get_contents(rs_b2_link("test/hello.txt", "hello.txt", 60)); echo "download: ", (is_string($body) && str_starts_with($body, "recomp test") ? "ok" : "FAILED"), "\n";
rs_b2_delete("test/hello.txt"); echo "delete: ok\n"; @unlink($t);'
echo "== B2 READY =="
