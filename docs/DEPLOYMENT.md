# DepEd DART — SDO Deployment Checklist

For the ICT staff installing DART at a Schools Division Office. Work through the sections in order; each one assumes the one before it is done.

---

## 1. Server prerequisites

| Requirement | Notes |
|---|---|
| PHP 8.3+ | With the usual Laravel extensions, plus `openssl` (signing) and `pdo_mysql`. |
| Composer | For PHP dependencies. |
| MySQL 8 | The production database. (SQLite is only used by the automated tests.) |
| Node.js + npm | Only needed to build the frontend once per release; not needed at runtime. |
| Python 3.10+ with pyHanko | Applies the digital signatures. **Signing and release stamping fail without it.** See step 2.4. |
| mutool (MuPDF) | Cleans up uploaded PDFs. Windows: `winget install --id ArtifexSoftware.mutool -e --source winget`; Linux: the `mupdf-tools` package. If it's missing, uploads still work — the file is just stored unoptimized. |

No queue worker is needed: notifications are sent immediately.

## 2. Install

### 2.1 Code and dependencies
```
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 2.2 Environment file
Copy `.env.example` to `.env`, then set at least:

```
APP_NAME="DepEd DART"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dart.your-sdo.deped.gov.ph
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dart
DB_USERNAME=...
DB_PASSWORD=...

MUTOOL_BINARY=/usr/bin/mutool          # or the full path to mutool.exe
PYHANKO_PYTHON_BINARY=/path/to/dart/scripts/pyhanko/venv/bin/python   # full path; set up in step 2.4
```

Then generate the app key: `php artisan key:generate`.

Keep `APP_ENV=production`. Besides being safer, it stops the seeder from creating the demo `admin@drs.local` account (which has the password `password`).

### 2.3 Database
```
php artisan migrate --force
php artisan db:seed --force
```

**Run `db:seed` only once, on the empty database.** It rebuilds the office list from scratch (OSDS, CID, SGOD), so running it again later would erase any offices you've added.

### 2.4 Signing tool (pyHanko)
DART applies the SDS's signature and the Records Section release stamp by running a Python script, `scripts/pyhanko/sign_pdf.py`, which uses the pyHanko library. The script needs its own Python virtual environment ("venv") inside the project folder.

Set it up once per server. Redo it only if you move the project folder, because a venv stops working when moved.

#### Windows
1. Install Python 3.10 or newer, either from [python.org](https://www.python.org/downloads/) (tick **Add python.exe to PATH** in the installer) or with `winget install -e --id Python.Python.3.12`. Open a new terminal and check it with `python --version`.
2. From the project folder, create the venv and install pyHanko. Keep the leading `.\` on the last two lines: PowerShell won't run a program from a relative path without it, and fails with *"The module 'venv' could not be loaded"*.
   ```
   cd scripts\pyhanko
   python -m venv venv
   .\venv\Scripts\python.exe -m pip install --upgrade pip
   .\venv\Scripts\python.exe -m pip install -r requirements.txt
   ```
   After `python -m venv venv`, a `venv` folder should appear in `scripts\pyhanko`. If it doesn't, the rest of the steps can't work, so fix that first.
3. In `.env`, set the **full** path to the venv's Python:
   ```
   PYHANKO_PYTHON_BINARY=C:\path\to\dart\scripts\pyhanko\venv\Scripts\python.exe
   ```

#### Linux (Ubuntu/Debian)
1. Install Python, the venv module, and a font for the signature text:
   ```
   sudo apt install python3 python3-venv python3-pip fonts-dejavu-core
   ```
2. From the project folder, create the venv and install pyHanko:
   ```
   cd scripts/pyhanko
   python3 -m venv venv
   venv/bin/python -m pip install --upgrade pip
   venv/bin/python -m pip install -r requirements.txt
   ```
3. In `.env`, set the **full** path to the venv's Python:
   ```
   PYHANKO_PYTHON_BINARY=/var/www/dart/scripts/pyhanko/venv/bin/python
   ```
4. Make sure the user PHP runs as (usually `www-data`) can read and run the venv. Either create the venv as that user, or give it ownership afterward: `sudo chown -R www-data:www-data scripts/pyhanko/venv`.

#### Things to watch
- **Use the full path.** A relative path like `./scripts/pyhanko/venv/...` breaks signing: during web requests PHP runs from a different folder, so the path no longer points at the venv.
- **Internet access:** `pip install` downloads pyHanko (version 0.37.0, pinned in `requirements.txt`) and its dependencies. Behind a proxy, add `--proxy http://proxy-host:port` to the `pip install` commands.
- **Fonts:** the text beside the signature uses Arial on Windows, or DejaVu Sans or Liberation Sans on Linux. If none of these is installed, it falls back to a very small built-in font, which is why step 1 on Linux installs `fonts-dejavu-core`.
- **Cached config:** if you've run `php artisan config:cache`, run it again after editing `.env`, or the old value stays in effect.

#### Check that it works
From `scripts/pyhanko`:
```
.\venv\Scripts\python.exe -c "import pyhanko, PIL; print('pyHanko OK')"    # Windows
venv/bin/python -c "import pyhanko, PIL; print('pyHanko OK')"              # Linux
```
This should print `pyHanko OK`. Running the script with `--help` (for example `.\venv\Scripts\python.exe sign_pdf.py --help` on Windows, or `venv/bin/python sign_pdf.py --help` on Linux) should print its usage. The full check is signing a real test document in step 4.

#### If signing fails in the app
| Message shown to the user | Likely cause |
|---|---|
| "…the Python interpreter is missing" | `PYHANKO_PYTHON_BINARY` points to a file that doesn't exist. Check the path, and re-run `config:cache` if you use it. |
| "Digital signing is temporarily unavailable…" | Python ran but the script failed, for example pyHanko isn't installed in that venv or the PHP user can't run it. Details are in `storage/logs/laravel-*.log`, under "pyhanko signing failed." |
| "Invalid P12 password or corrupted certificate file." | Not a setup problem: the wrong certificate password was entered, or the uploaded `.p12` file is damaged. |

### 2.5 First admin account
Self-registration is disabled, so create the first admin from the command line:
```
php artisan users:create-admin ict@your-sdo.deped.gov.ph --name="SDO ICT Unit"
```
It asks for a password (at least 10 characters, with upper- and lowercase letters, a number, and a symbol). Two-factor authentication is set up on the first sign-in.

### 2.6 Scheduler
DART has one scheduled job: every night at midnight, it deletes the stored files of documents that were deleted more than `DOCUMENT_FILE_RETENTION_DAYS` days ago (default 90). The system has to call `php artisan schedule:run` **every minute**, and Laravel decides when the job is due. You set this up once. Don't run the command by hand: that only runs whatever happens to be due at that moment.

If this step is skipped, nothing breaks. Deleted documents' files just stay on disk.

#### Windows (Task Scheduler)
1. Find the real `php.exe`. It must be the actual program, not a wrapper script such as Laravel Herd's `php.bat`:
   ```
   php -r "echo PHP_BINARY;"
   ```
2. In a PowerShell window opened with **Run as administrator**, create a task that runs every minute. Put in your own `php.exe` path from step 1 and your project folder:
   ```
   schtasks /create /tn "DART Scheduler" /sc minute /mo 1 /ru SYSTEM /tr "C:\path\to\php.exe C:\path\to\dart\artisan schedule:run"
   ```
   Running it as `SYSTEM` keeps it running when nobody is logged in, and stops a console window from popping up every minute. If either path contains spaces, run the command from **Command Prompt** (also as administrator) instead of PowerShell, which mangles nested quotes, and wrap that path in `\"...\"` inside the `/tr` value, for example `/tr "\"C:\Program Files\PHP\php.exe\" C:\dart\artisan schedule:run"`.
3. Check it: `schtasks /query /tn "DART Scheduler"` should show the task as **Ready**, and `php artisan schedule:list` shows when the job next runs.

#### Linux (cron)
Add this line with `crontab -e`, as the same user the web server's PHP runs as:
```
* * * * * cd /path/to/dart && php artisan schedule:run >> /dev/null 2>&1
```

### 2.7 Web server
Point the web root at `public/`. Make sure `storage/` and `bootstrap/cache/` are writable by the web server user. Uploaded documents and signing certificates are stored under `storage/app` and are never publicly served, so include `storage/app` in your backups along with the database.

### 2.8 Office branding
Replace `public/images/office-seal.png` with your SDO's seal. **It currently contains the DepEd Region XI seal**, which appears on the login page, the landing page, and the weekend notice.

## 3. Set up the office in the app

Sign in as the admin, then:

1. **Offices**: add the sub-offices under OSDS, CID, and SGOD (for example Records Section, Legal Unit, ICT Unit, Personnel, Budget, Accounting, and the SGOD/CID sections).
2. **Designate the Records Section**: edit that office and tick **This Office Is the Records Section**. Until you do, the Offices page shows a warning and signed documents cannot be released.
3. **Users**: create the accounts.
   - One **Schools Division Superintendent** and, if the SDO has one, the **ASDS**. If there is no ASDS, click **Mark Unavailable** under *ASDS Availability* on the Users page so documents skip that stage.
   - **Secretary to the SDS**: assign it only to the SDS's own secretary, in the OSDS or one of its sub-offices. Anyone with this role can sign documents on the SDS's behalf.
   - Division chiefs, unit/section heads, staff (tick **KMT Focal** for the staff allowed to originate outgoing documents), and the Records Section staff (assigned to the Records Section office).
4. **Outgoing uploads**: if every staff member (not only KMT Focal) should be able to create outgoing documents, click **Allow All Originators** on the Users page.
5. **Signing certificates**: the SDS uploads their P12 certificate and e-signature image under **My Signature**. Each Records Section staff member who applies release stamps uploads their own.
6. Optional check: `php artisan documents:diagnose-auto-forward` confirms the Records Section is designated and has staff assigned.

## 4. Verify signing before go-live

Run one test document through the whole chain: submit, review, SDS approval, sign as the secretary, release stamp as Records Section. Download the released PDF and open it in **Adobe Acrobat Reader**. Both signatures (the SDS's and the release stamp) should show as valid with no warnings. No automated test can check what a PDF reader displays, so don't skip this.

## 5. Trial period → go-live

If you run a trial with test documents first, clear them before going live:
```
php artisan documents:reset-test-data
```
This deletes every document, routing, signature, audit log, notification, and stored file, and restarts control numbers at 001. Users, offices, signing certificates, and settings are kept.
