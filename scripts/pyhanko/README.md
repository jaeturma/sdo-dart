# pyhanko signing tool

`sign_pdf.py` applies one or more independent, incremental-update digital
signatures to a PDF from a single PKCS#12 credential. Each placement
becomes its own real signature (not a single shared one with cosmetic
echoes), so a document can carry any number of genuinely independent
signatures - the same document uploaded already signed (e.g. PNPKI),
the SDS's own signature, Records Section's release stamp, and multiple
placements from the same signer - without any of them invalidating the
others. See `App\Services\PyhankoPdfSigner` for how this is called from
the app, and CLAUDE.md's "Digital signing" section for the background.

## Setup (once per environment)

Requires Python 3.10+.

```
cd scripts/pyhanko
python -m venv venv
.\venv\Scripts\python.exe -m pip install -r requirements.txt # Windows (keep the .\ in PowerShell)
venv/bin/python -m pip install -r requirements.txt           # Linux/macOS
```

Point the app at this interpreter via `.env`:

```
PYHANKO_PYTHON_BINARY=C:\path\to\dart\scripts\pyhanko\venv\Scripts\python.exe
```

(defaults to relying on `python` being on `PATH` if unset - see
`config/services.php`'s `pyhanko.python_binary`.)

## Manual invocation (for debugging)

```
.\venv\Scripts\python.exe sign_pdf.py ^
  --input original.pdf --output signed.pdf ^
  --p12 credential.p12 --passfile passfile.txt ^
  --placements "[{\"page\":1,\"x_percent\":10,\"y_percent\":80,\"width_percent\":30}]" ^
  --esig-image esig.png ^
  --signer-name "Schools Division Superintendent" --reason "Approved" --location "Schools Division Office" ^
  --signed-at "2026-09-18T12:00:00+08:00"
```

Exit codes: `0` success, `10` bad P12 password/corrupt cert, `12` could not
write output, `1` unexpected failure (traceback on stderr).
