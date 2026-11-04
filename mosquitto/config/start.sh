#!/bin/sh
# Builds the Mosquitto password file from .env, then starts the broker.
set -eu

: "${MQTT_KEYPAD_PASSWORD:?set MQTT_KEYPAD_PASSWORD in .env}"
: "${MQTT_DOOR_PASSWORD:?set MQTT_DOOR_PASSWORD in .env}"

PASSWD=/mosquitto/data/passwd
rm -f "$PASSWD"
mosquitto_passwd -c -b "$PASSWD" das_keypad "$MQTT_KEYPAD_PASSWORD"
mosquitto_passwd -b "$PASSWD" das_door "$MQTT_DOOR_PASSWORD"
# Mosquitto reads these after dropping to the mosquitto user and wants them private,
# so copy the (read-only mounted) ACL next to the password file.
cp /mosquitto/config/acl /mosquitto/data/acl
chown mosquitto:mosquitto "$PASSWD" /mosquitto/data/acl 2>/dev/null || true
chmod 0700 "$PASSWD" /mosquitto/data/acl

exec mosquitto -c /mosquitto/config/mosquitto.conf
