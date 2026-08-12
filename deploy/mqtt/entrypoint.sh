#!/bin/sh
set -e

PASSWD=/mosquitto/creds/mosquitto.passwd

# Persistence dir is owned by the mosquitto user so the daemon (started below
# as uid 1883) can write without ever running as root.
chown -R mosquitto:mosquitto /mosquitto/data

# The credential store is shared with device-mgmt, whose www-data user is a
# member of the mqtt group (gid 1883). Group-write with the setgid bit keeps
# the files away from other readers while still allowing both containers.
if [ -d /mosquitto/creds ]; then
    chown mosquitto:mosquitto /mosquitto/creds
    chmod 2770 /mosquitto/creds
fi

# Seed service accounts on first boot only, so restarts don't churn the file
# (mosquitto_passwd warns about owner/group when run as root). Device
# credentials are provisioned later by device-mgmt as devices are created.
seed_user() {
    user="$1"
    pass="$2"
    if [ -n "$user" ] && [ -n "$pass" ] && ! grep -q "^${user}:" "$PASSWD"; then
        mosquitto_passwd -b "$PASSWD" "$user" "$pass"
    fi
}

if [ ! -f "$PASSWD" ]; then
    : > "$PASSWD"
fi
seed_user "$MOSQUITTO_USER" "$MOSQUITTO_PASSWORD"
seed_user "$CHIRPSTACK_MQTT_USERNAME" "$CHIRPSTACK_MQTT_PASSWORD"
seed_user "$INGESTION_MQTT_USERNAME" "$INGESTION_MQTT_PASSWORD"
seed_user "$DEVICE_MGMT_MQTT_USERNAME" "$DEVICE_MGMT_MQTT_PASSWORD"
chown mosquitto:mosquitto "$PASSWD"
chmod 0640 "$PASSWD"

# Keep the ACL out of world-readable range too (Mosquitto 2.1.x warns on that).
chown mosquitto:mosquitto /mosquitto/config/acl
chmod 0640 /mosquitto/config/acl

# Reload credentials the instant device-mgmt rewrites the password file
# (mosquitto_passwd writes atomically via rename). Event-driven via inotify, so
# a freshly provisioned credential is usable immediately.
inotifywait -m -e close_write -e moved_to /mosquitto/creds 2>/dev/null |
    while read -r _ _ file; do
        if [ "$file" = "mosquitto.passwd" ]; then
            pkill -HUP -x mosquitto 2>/dev/null || true
        fi
    done &
inotify_pid=$!

# Run Mosquitto in the foreground as the unprivileged mosquitto user. Starting
# it as root would trip the strict root-only checks on the passwd file.
exec su -s /bin/sh mosquitto -c 'exec mosquitto -c /mosquitto/config/mosquitto.conf'
