# DFIR FM - DFIR File Manager

## Overview
**DFIR FM** (Digital Forensics and Incident Response File Manager) is a web-based file manager designed to meet the unique needs of the DFIR community. Unlike other file managers, DFIR FM focuses on forensic soundness, high traceability, and granular control, ensuring that every action is auditable and accountable.

Whether managing large datasets, controlling access to sensitive evidence, or enabling dynamic collaboration during investigations, DFIR FM provides the tools needed to maintain forensic integrity while handling files efficiently.

---

## Key Features
- **Traceability:** Every action is logged and traceable to an individual, ensuring accountability. Administrators search, export and archive the events from the admin page; if an event can't be logged, the application stops until logging works again.
- **Granular Access Control:** Enforce access rights at the directory level to maintain data confidentiality and security.
- **Support for Large Files:** Upload and manage large files with seamless chunked uploads.
- **File Integrity:** Automatically calculates and stores the SHA256 hash for all uploaded files.
- **SQLite Database:** Stores access rights and file metadata in a lightweight, portable database, set up automatically by the application.
- **Dynamic Contributors:** Invite contributors into a case folder: they join the folder's own group with upload-only access by default, set their password from a single-use link, and only see their own uploads unless granted more.
- **Flexible Mail Integration:** Send invitations via the local sendmail, SMTP, or SendGrid; on air-gapped hosts (`mail_engine=none`), the invitation link is shown to the administrator instead, to be passed on through another channel.
- **Forensic-Proof Evidence Handling:** Built with the principles of chain-of-custody and forensic soundness in mind.
- **Docker Support:** Deploy DFIR FM with Docker Compose for ease of setup.

---

## Getting Started

### Prerequisites
- **Server Requirements:**
  - PHP 8.4 with the `pdo_sqlite`, `apcu`, `curl`, `gmp` and `mbstring` extensions
  - APCu enabled for the web server (`apc.enabled=1`, the default): it holds the login rate-limit counters. Without it every page answers `503` with what is missing; the `bin/` scripts don't need it.
  - Composer
  - Apache with `mod_rewrite` and `AllowOverride` for the application's `.htaccess` (`mod_headers` recommended). Other web servers need its rules translated.
- **Client Requirements:**
  - Modern web browser with JavaScript enabled
- **Or:** Docker with Docker Compose, which needs none of the above (step 4)

### Installation
1. Clone the repository:
   ```bash
   git clone https://github.com/jfstenuit/dfir-fm.git
   cd dfir-fm
   ```

2. Install dependencies via Composer:
   ```bash
   composer install --no-dev
   ```

3. Configure the application:
   - Copy the `env.sample` file to `.env`, readable by the web server but not by everyone:
     ```bash
     cp env.sample .env
     sudo chgrp www-data .env && chmod 640 .env
     ```
   - Edit the `.env` file to configure your settings (mail, storage directory, database and log paths, reverse proxy). The storage directory must exist; it and the directories of the databases and the log file must be writable by the web server user and not web-accessible.
   - **Logs:** every event is stored in the events database (`events_database_path`), which the admin page's Logs tab reads. `log_engine` adds a copy: a log file (`file`, the default), `syslog`, `stderr`, or `none` when the Logs tab is enough. Back up the events database like the application database: it is the audit trail.
   - **Keep the databases and the log file out of the storage directory.** The storage directory holds the evidence and nothing else: give it a directory of its own, not a parent of the databases' or the log's. Beware of the defaults: the database is `storage/database/app.sqlite` and the events database `storage/events/events.sqlite` unless `database_path` and `events_database_path` say otherwise, so `base_storage_directory=storage` would put them inside. For example:
     ```
     base_storage_directory=/srv/dfir-fm/files
     database_path=/srv/dfir-fm/database/app.sqlite
     events_database_path=/var/log/dfir-fm/events.sqlite
     log_file_path=/var/log/dfir-fm/dfir-fm.log
     ```
     If they are inside anyway, the application protects them: their paths are reserved (uploads and new folders with those names are refused, and "Refresh from storage" doesn't offer to import them), and `bin/check-integrity.php` prints a `RESERVED` line. But an operator who copies, restores or re-hashes the storage then handles the live databases and log along with the evidence. Moving them: stop the web server, move the files, update `.env`, start it again.
   - Check that the web server refuses `.env` (and the rest of the application's files): `.htaccess` blocks them, but only if Apache reads it (`AllowOverride All` for the application's directory, and `mod_rewrite` enabled):
     ```bash
     curl -s -o /dev/null -w '%{http_code}\n' https://your.host/dfir-fm/.env   # 404 (or 403) expected
     curl -s -o /dev/null -w '%{http_code}\n' https://your.host/dfir-fm/src/Core/Config.php   # 404 expected
     ```
     A `200` means that the configuration, secrets included, is readable by anyone. If `.htaccess` can't be enabled, block these paths in the virtual host, or, with PHP-FPM, move the settings out of the web root: every key can be set as a `DFIRFM_` variable in the pool configuration (`env[DFIRFM_SMTP_PASSWORD] = …`), and `.env` can then be deleted. Apache's `SetEnv` doesn't work for this with mod_php.
   - Allow PHP requests of a few MiB: uploads arrive in chunks of 2 MiB. Set `upload_max_filesize` and `post_max_size` to e.g. `8M` (PHP's default `upload_max_filesize=2M` is too small), and raise any body size limit of a reverse proxy (nginx: `client_max_body_size`).
   - Check that invitation e-mails can be sent:
     ```bash
     sudo -u www-data php bin/send-test-mail.php you@yourdomain.com
     ```
     On a host that can't send mail, set `mail_engine=none`: invitation links are then shown to the administrator, who passes them on through another channel.
   - Create the first administrator (prints a random password once):
     ```bash
     sudo -u www-data php bin/create-admin.php you@yourdomain.com
     ```
   - Optionally, add a cron job that expires abandoned uploads (the application also does it when an upload starts), and run `php bin/check-integrity.php` from time to time to re-hash the stored files against the database.
   - **Upgrading from a version without the events database:** the events logged before it existed are in the log file only. To see them in the Logs tab too, import the log file and its rotated copies (`.gz` included, in any order; running it twice imports nothing):
     ```bash
     sudo -u www-data php bin/import-log.php /var/log/dfir-fm/dfir-fm.log*
     ```
     CSV logs (`log_format=csv`) can't be read back; keep them as they are.

4. (Optional) Run the application with Docker Compose instead (no `composer install` needed on the host). Its configuration is `docker/.env`: copy `env.sample` there, customize it as in step 3 and uncomment its "Docker" section. The paths of the file (files, databases, log) are ignored: the container keeps them under `/data`, and the "Docker" section chooses what is mounted there: `files_dir` and `log_dir`, host directories; left empty, Docker volumes. The database is always in a volume. The events database is in `log_dir` (or its volume), with the log file: back it up, it is the audit trail. `log_engine=none` keeps the events database only; `log_engine=stderr` also sends the events to the container output. The container's web server user is uid/gid 82, or `uid` and `gid` if set: set them to the owner of the host directories to mount them as they are.
   ```bash
   cp env.sample docker/.env      # then edit it
   chmod 640 docker/.env           # with uid/gid unset: sudo chgrp 82 docker/.env, and directories owned by 82
   cd docker
   docker compose up -d --build
   docker compose exec -u www-data app php bin/create-admin.php you@yourdomain.com
   ```
   The application is then available at `http://127.0.0.1:8080/` (or the port set by `http_port` in the "Docker" section). Serve it to others through a TLS-terminating reverse proxy. See `DEVELOPER.md`, "Docker".

### Air-gapped deployments
Once installed, DFIR FM needs no Internet access: Bootstrap, its icons and Dropzone are served by the application itself from `vendor/`, never from a CDN (the Content-Security-Policy only allows the application's own origin), and the application makes no outbound connections except to send mail. Use `mail_engine=none` (invitation links are shown to the administrator), or `mail`/`smtp` with a relay on the isolated network; `sendgrid` needs the Internet.

Installing it is another matter: `composer install` downloads the dependencies, the front-end libraries included (`vendor/` is not in the repository), and building the Docker image downloads the base images, Alpine packages, APCu and the dependencies. Prepare the installation on a connected machine and carry it over:

- **Docker:** build the image on the connected machine, from a checkout of the same version, and export it:
  ```bash
  cd docker
  docker compose build
  docker compose config --images   # the image's name, e.g. docker-app
  docker save docker-app | gzip > dfir-fm-image.tar.gz
  ```
  On the air-gapped host, with the same checkout: `docker load < dfir-fm-image.tar.gz`, then `docker compose up -d` from `docker/`, **without** `--build`, which would try to rebuild it.
- **Without Docker:** run `composer install --no-dev` on a connected machine with PHP 8.4, then copy the whole directory, `vendor/` included, to the air-gapped host and continue with step 3. The host still needs PHP and its extensions, from its own package mirror.

Until the first release, the image has to be built this way. From then on, ready-made Docker images will be provided with each release, and only developers will need the build step: an air-gapped installation will then be a `docker save` of the published image (or a download of its archive) and a `docker load`.

---

## Usage

### Key Concepts
1. **User Authentication:**
   - Authenticate users to trace all actions and maintain accountability.

2. **Granular Access Rights:**
   - Assign access rights (read, write, upload) at the directory level.

3. **File Integrity Checks:**
   - Every uploaded file is hashed using SHA256, and the hash is stored for verification.
   - "Refresh" (administrators) re-hashes a folder's stored files against their records: a file that differs is flagged in red, a missing one in yellow, and both are logged. The records are never changed. `bin/check-integrity.php` checks the whole storage from the command line.

4. **Dynamic Collaboration:**
   - Add or revoke access for contributors dynamically during investigations.

5. **Audit Log:**
   - Every event (logins, uploads with size and SHA-256, downloads, deletions, rights changes, invitations) is stored in the events database. The admin page's **Logs** tab searches it by time, action, user, IP or any text (a file name, a SHA-256), shows each event's full record, and exports a search as JSONL or CSV; viewing and exporting are logged too.
   - **No logging, no service:** if an event can't be logged (full disk, unwritable log), the application stops serving (HTTP 503) until logging works again, then records how long it was stopped. The action being logged when it happened stays done.
   - Events are kept until an administrator archives them (a JSONL file of every event before a date, with its SHA-256, for cold storage) and then purges that archive. The purge deletes exactly the archived events, only if they are unchanged since. `bin/archive-events.php` does the same from the command line.

6. **Invitations:**
   - Contributors are invited into a case folder (never the root) and join that folder's default group, `<folder> contributors`, which gets upload-only access when created. The invitation link is single-use, valid one hour, and asks the contributor to choose a password (at least 12 characters).
   - Links are sent by mail (local sendmail, SMTP or SendGrid) or, with `mail_engine=none`, shown to the administrator to pass on.
   - "Resend invitation" (Users tab) sends a new link, which also serves as a password reset; Lock / Unlock / Delete manage the account.

### Example Workflow
1. **Open a Case and Invite Contributors:**
   - An administrator creates a folder for the case, opens it and invites contributors by e-mail address. Each one gets a link, sets a password and lands in the folder.

2. **Upload Evidence Files:**
   - Users with upload permissions can securely upload files.
   - **Files too large for the browser, or placed in storage another way** (copied with `rsync`, from a disk of an air-gapped host, restored from a backup) are imported with **Refresh**. Copy them into the folder's directory in storage (`base_storage_directory` + the folder's path; create the directory if the folder has none yet), wait until the copy is complete, then open the folder in DFIR FM as an administrator and choose Refresh, then Start. Each new file is hashed from storage and recorded, as uploaded by you from your address and marked "imported"; new subdirectories become folders without access rights of their own. Refresh covers the open folder only: open each subfolder to refresh it. Not imported, with the reason shown: symbolic links, special files, names the application doesn't accept, a name already used, and content already in the folder. A file still being copied while it's hashed is skipped: refresh again once the copy is done. The copied files must be readable by the web server user.

3. **Manage Access:**
   - Admins can assign or revoke access rights for directories, e.g. let the case's contributors see each other's files by giving its default group the read right.

4. **Verify Integrity:**
   - SHA256 hashes ensure the integrity of all uploaded files.

5. **Audit Actions:**
   - Every action is logged, providing a detailed audit trail. In the admin page's Logs tab, search the case's events (e.g. the text of the case folder's path, or a file's SHA-256) and export them to attach to the case file: the export's SHA-256 is logged, so the file can be matched to the log.

---

## Future Features
- **Abstracted Back-End Storage:**
  - Transparent support for Azure Blob Storage or Amazon S3.
- **Enhanced Collaboration Features:**
  - Improved user management and real-time activity tracking.
- **OIDC Integration:**
  - Single sign-on for administrators

---

## Contribution Guidelines
We welcome contributions to DFIR FM! To get started:
1. Fork the repository.
2. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature
   ```
3. Commit your changes:
   ```bash
   git commit -m "Add your feature description"
   ```
4. Push your branch:
   ```bash
   git push origin feature/your-feature
   ```
5. Create a pull request on GitHub.

---

## License
DFIR FM is licensed under the **GNU Affero General Public License (AGPL)**. This license ensures that modifications to DFIR FM, whether distributed or hosted, must be shared under the same license. While commercial use is permitted, embedding DFIR FM in proprietary, off-the-shelf products is not allowed.

For full license details, see the `LICENSE.md` file.

---

## Acknowledgments
We appreciate the tools and libraries that made this project possible, including:
- [Dropzone.js](https://www.dropzone.dev/) for file uploads
- [Bootstrap](https://getbootstrap.com/) for the user interface
- [Bootstrap Icons](https://icons.getbootstrap.com/) for the icons

---

## Contact
For questions or support, feel free to open an issue on the GitHub repository.

---

**Built with forensic soundness in mind by the DFIR community, for the DFIR community.**

