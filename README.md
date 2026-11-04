# Hotel Booking Application

Development setup for the PHP/Apache and MySQL application.

## Requirements

- Docker Desktop with Docker Compose
- Git

## First-time setup

Clone the repository and enter the project directory:

```powershell
git clone <repository-url>
cd project-tester-v3
Copy-Item .env.example .env
```

Edit `.env` and replace the database password placeholders with local development values. Keep `.env` private; it is ignored by Git.

สำหรับ Windows สามารถใช้คำสั่งเดียวแทนขั้นตอนด้านบนได้ (สคริปต์จะสร้าง `.env` พร้อมค่าลับแบบสุ่มและเริ่มระบบ):

```powershell
powershell -ExecutionPolicy Bypass -File .\setup.ps1
```

## Run the application

```powershell
docker compose up -d
docker compose ps
```

Open <http://localhost:8080> in a browser. The PHP source directory is bind-mounted from `php/src`, so normal code changes are reflected without rebuilding. On a new database volume, MySQL automatically creates the base tables, six sample rooms, and runs migrations `001` and `002` in order.

To rebuild after changing the Dockerfile:

```powershell
docker compose up -d --build
```

View recent logs:

```powershell
docker compose logs --tail=100
docker compose logs --tail=100 php-apache-environment
docker compose logs --tail=100 db
```

Stop the development containers without deleting database data:

```powershell
docker compose down
```

## Database

PHP connects to the Compose service named `db` using PDO and the `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` variables. MySQL is not published to the host or the Internet. Its data is stored in the Docker volume selected by `MYSQL_VOLUME_NAME`, which survives `docker compose down` and container recreation.

Do not run `docker compose down -v` unless you intentionally want to reset the local database and understand that all data in the named volume will be deleted.

The optional phpMyAdmin service is available only with the `admin` profile and is bound to localhost:

```powershell
docker compose --profile admin up -d
```

Open <http://localhost:8000> and sign in with the `MYSQL_USER` and `MYSQL_PASSWORD` values from `.env`. The server is `db` and the database is the value of `MYSQL_DATABASE`. It is not needed for normal development and must not be exposed publicly.

This workspace is configured to use the existing v1 volume through `MYSQL_VOLUME_NAME=project-tester-v1_mysql_data`. If you are setting up a clean copy, leave the default `project-tester-v3_mysql_data`.

If you already have a database from v1, do not delete the volume. Start the services first and apply only the new migration manually:

```powershell
Get-Content php/src/migrations/002_payment_approval_and_door.sql | docker compose exec -T db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

The automatic init scripts run only when the MySQL volume is empty. Existing data is never migrated or overwritten automatically.

## Payment approval and digital door lock (Senior Project 2)

### Upgrade an existing setup

1. Add the new values from `.env.example` to `.env`: `DOOR_DEVICE_KEY`, `MQTT_KEYPAD_PASSWORD`, `MQTT_DOOR_PASSWORD`, `WEB_BIND`, `MQTT_BIND`.
2. Run the migration once (only when upgrading a database created by v1):

   ```powershell
   Get-Content php/src/migrations/002_payment_approval_and_door.sql | docker compose exec -T db sh -c 'mysql -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
   ```

3. Rebuild, because the Dockerfile now enables Apache's WebSocket proxy and Compose adds the `mqtt` service:

   ```powershell
   docker compose up -d --build
   ```

### How it works

- Booking is saved as `pending_payment`, and the guest goes to the PromptPay QR page (simulated payment).
- **ยืนยันชำระเงิน** calls `api/payment-request.php`, and the booking becomes `awaiting_approval`.
- `admin.php` shows the request in **คำขอชำระเงินรอยืนยัน** (refreshed every 5 seconds). **ยืนยัน** sets the booking to `active`; **ปฏิเสธ** sets it to `rejected` and frees the room.
- Only `active` bookings can create room codes on the dashboard during the stay.
- `door-index.html` publishes the 6-digit code to MQTT topic `das/door/<room>/code`. The Raspberry Pi (`raspberry-pi/door_lock.py`) checks it with `api/door-authenticate.php` and publishes the result to `das/door/<room>/result`.
- Apache forwards `/mqtt` to Mosquitto's WebSocket listener, so the keypad page and the Pi can use the same address as the website, including the Cloudflare tunnel. Set `MQTT_BIND=0.0.0.0` only if the Pi should use plain MQTT on port 1883 over the LAN.

Raspberry Pi setup is described in `raspberry-pi/README.md`.

## Team workflow

Create a branch for each task, make small focused commits, and open a pull request for review. Pull the latest default branch before starting work and resolve conflicts locally. Commit source code, migrations, Docker files, Compose configuration, `.env.example`, and this README. Never commit `.env`, passwords, webhook secrets, generated logs, or local editor files.

## Publishing to GitHub

Before the first push, verify that `.env` and `raspberry-pi/door.env` are not staged:

```powershell
git status --short --ignored
git check-ignore .env raspberry-pi/door.env
```

Use `.env.example` and `raspberry-pi/door.env.example` as templates only. Replace every placeholder in local copies, and rotate any credential that has ever been shared outside the intended deployment.

GitHub supports **Public** and **Private** repositories. It does not provide an unlisted, link-only visibility mode:

- **Public**: anyone can view the repository if they find it or receive its link.
- **Private**: only the owner and explicitly invited collaborators can view it.

For a private project, keep the repository private and invite reviewers as collaborators instead of sharing deployment secrets.

## Optional Cloudflare Quick Tunnel

Start the normal application first. Then run a temporary tunnel in a separate terminal:

```powershell
docker run --rm --name hotel-booking-cloudflared cloudflare/cloudflared:latest tunnel --no-autoupdate --url http://host.docker.internal:8080
```

Cloudflare will print a temporary HTTPS URL. Share it only with the intended teammate. This command forwards only the local web application on port 8080; it does not publish MySQL, the Docker daemon, SSH, or phpMyAdmin. Stop it with `Ctrl+C`. The URL is temporary and changes when the tunnel is restarted.

## Important warnings

- Never commit `.env` or share its database and webhook secrets.
- Never run `docker compose down -v` unless intentionally resetting the database.
- Do not publish port 3306 or expose phpMyAdmin through a tunnel.
- Keep `DOOR_DEVICE_KEY` and `MQTT_DOOR_PASSWORD` secret; they let a device open doors.
