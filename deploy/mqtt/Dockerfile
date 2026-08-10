# Mosquitto broker with inotify support so credential/ACL changes from
# device-mgmt trigger an instant SIGHUP reload (no polling delay).
FROM eclipse-mosquitto:2
RUN apk add --no-cache inotify-tools
