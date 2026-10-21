# DFIR FM - DFIR File Manager

## Overview
**DFIR FM** (Digital Forensics and Incident Response File Manager) is a web-based file manager designed to meet the unique needs of the DFIR community. Unlike other file managers, DFIR FM focuses on forensic soundness, high traceability, and granular control, ensuring that every action is auditable and accountable.

Whether managing large datasets, controlling access to sensitive evidence, or enabling dynamic collaboration during investigations, DFIR FM provides the tools needed to maintain forensic integrity while handling files efficiently.

---

## Key Features
- **Traceability:** Every action is logged and traceable to an individual, ensuring accountability.
- **Granular Access Control:** Enforce access rights at the directory level to maintain data confidentiality and security.
- **Support for Large Files:** Upload and manage large files with seamless chunked uploads.
- **File Integrity:** Automatically calculates and stores the SHA256 hash for all uploaded files.
- **SQLite Database:** Stores access rights and file metadata in a lightweight, portable database, set up automatically by the application.
- **Dynamic Contributors:** Easily add or remove collaborators with granular access rights.
- **Flexible Mail Integration:** Send invitations via built-in `mail()`, SMTP, or Sendgrid.
- **Forensic-Proof Evidence Handling:** Built with the principles of chain-of-custody and forensic soundness in mind.
- **Docker Support:** Deploy DFIR FM as a Docker or Docker Compose instance for ease of setup and scalability.

---

## Getting Started

### Prerequisites
- **Server Requirements:**
  - PHP 8.x
  - SQLite3
  - A web server (e.g., Apache, Nginx)
- **Client Requirements:**
  - Modern web browser with JavaScript enabled
- **Optional:** Docker and Docker Compose for containerized deployment

### Installation
1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/dfir-fm.git
   cd dfir-fm
   ```

2. Install dependencies via Composer:
   ```bash
   composer install
   ```

3. Configure the application:
   - Copy the `.env.sample` file to `.env`:
     ```bash
     cp .env.sample .env
     ```
   - Edit the `.env` file to configure your settings (e.g., base URL, mail settings).
   - Check that invitation e-mails can be sent:
     ```bash
     php bin/send-test-mail.php you@yourdomain.com
     ```

4. (Optional) Run the application with Docker Compose instead (no `composer install` needed on the host). Its configuration is `docker/.env`: copy `.env.sample` there, customize it as in step 3 and uncomment its "Docker" section. The three paths of the file (files, database, log) are ignored: the container keeps them under `/data`, and the "Docker" section chooses what is mounted there: `files_dir` and `log_dir`, host directories; left empty, Docker volumes. The database is always in a volume. The container's web server user is uid/gid 82, or `uid` and `gid` if set: set them to the owner of the host directories to mount them as they are.
   ```bash
   cp .env.sample docker/.env      # then edit it
   chmod 640 docker/.env           # with uid/gid unset: sudo chgrp 82 docker/.env, and directories owned by 82
   cd docker
   docker compose up -d --build
   docker compose exec -u www-data app php bin/create-admin.php you@yourdomain.com
   ```
   The application is then available at `http://127.0.0.1:8080/` (or the port set by `http_port` in the "Docker" section). Serve it to others through a TLS-terminating reverse proxy. See `DEVELOPER.md`, "Docker".

---

## Usage

### Key Concepts
1. **User Authentication:**
   - Authenticate users to trace all actions and maintain accountability.

2. **Granular Access Rights:**
   - Assign access rights (read, write, upload) at the directory level.

3. **File Integrity Checks:**
   - Every uploaded file is hashed using SHA256, and the hash is stored for verification.

4. **Dynamic Collaboration:**
   - Add or revoke access for contributors dynamically during investigations.

5. **Mail Invitation System:**
   - Use flexible mail mechanisms (`mail()`, SMTP, or Sendgrid) to send invitations to contributors.

### Example Workflow
1. **Upload Evidence Files:**
   - Users with upload permissions can securely upload files.

2. **Manage Access:**
   - Admins can assign or revoke access rights for directories.

3. **Verify Integrity:**
   - SHA256 hashes ensure the integrity of all uploaded files.

4. **Audit Actions:**
   - Every action is logged, providing a detailed audit trail.

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

For full license details, see the `LICENSE` file.

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

