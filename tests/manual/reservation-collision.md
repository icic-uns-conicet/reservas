# CLI test: room reservations and collisions

The PHP script exercises the live Reservas HTTP API. It creates two
reservations and attempts a third overlapping reservation. It verifies the
events API reports the room principal as the source, then removes the test
events in its `finally` cleanup.

## Requirements

- Run the script on a machine that can reach the Nextcloud instance over HTTPS.
- PHP CLI with the `curl` extension.
- An enabled Nextcloud user with a valid profile email, access to the selected
  room, and a Nextcloud app password.
- A future date/time where the room is free from the chosen start through 2.5
  hours after it. The script aborts if a room event is already in that interval.

## Run

Find the room key in `/apps/reservas/api/rooms`. Reservations are written
directly to that room's resource-booking calendar.

```sh
cd /var/www/nextcloud/apps/reservas
read -rsp 'Nextcloud app password: ' NEXTCLOUD_APP_PASSWORD
echo
export NEXTCLOUD_APP_PASSWORD
php tests/manual/run-reservation-collision.php \
  --base-url='https://cloud.example.org' \
  --username='user-id' \
  --room-key='calendar_resource_management-room-id' \
  --date='2026-12-01' \
  --time='10:00' \
  --timezone='America/Argentina/Buenos_Aires'
unset NEXTCLOUD_APP_PASSWORD
```

Do not pass the app password as a command-line argument. Replace the example
host, user, room key, date, and time with values for the instance.

## Assertions

1. A 60-minute reservation at the selected time is created (HTTP 201) and the
   events API identifies `principals/calendar-rooms/{room-key}` as its source.
2. A second reservation starting 30 minutes later is rejected (HTTP 409) and
   does not appear in the events API.
3. A reservation starting 90 minutes after the first is created (HTTP 201) and
   the events API identifies the room principal as its source.
4. The script deletes the test reservations and verifies they no longer appear
   in the events API. If cleanup reports an error, delete any remaining
   `Reservas CLI ...` test events manually.
