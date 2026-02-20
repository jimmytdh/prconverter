# PR Converter (PHP + jQuery + Tailwind + SQLite)

Upload a Purchase Request PDF, extract required fields, and store them in SQLite.

## Stack
- PHP (plain PHP, no framework)
- jQuery (AJAX upload)
- TailwindCSS (premium UI)
- SQLite (persistent storage)

## Extracted fields
- Fund Cluster
- PR No.
- Responsibility Center Code
- Date
- Unit
- Item Description
- Quantity
- Unit Cost
- Total Cost
- Requested by
- Designation1
- Approved by
- Designation2

## Files
- `index.php` - UI and records table
- `upload.php` - upload + extract + parse + insert endpoint
- `export.php` - download one record as `.xlsx` template
- `parser.php` - PDF text extraction and PR field parsing
- `db.php` - SQLite connection and schema creation
- `xlsx_export.php` - native XLSX generator (no external PHP library)
- `storage/app.sqlite` - SQLite database (auto-created)
- `storage/blank.sqlite` - blank DB snapshot/template copy
- `uploads/` - uploaded PDFs (auto-created)

## Database tables
- `purchase_requests` - PR header-level data
- `purchase_request_items` - one row per extracted line item (linked by `purchase_request_id`)

## Run
From project root:

```bash
php -S 127.0.0.1:8000 -t .
```

Open: `http://127.0.0.1:8000`

Click a value in the `PR No.` column to download that record as a formatted `.xlsx` file.

## PDF extraction strategy
1. Try `pdftotext` if installed.
2. Fallback to Python `pypdf` text extraction.
3. If scanned/image-only PDF, fallback to OCR using Python `pymupdf` + `tesseract`.

## OCR dependencies (for scanned PDFs)
Install:

```bash
python -m pip install pypdf pymupdf
```

And ensure `tesseract` is available in PATH.

## Notes
- For some noisy OCR files, `Unit` or `Responsibility Center Code` can be empty if not reliably detected.
- `Unit Cost` is computed as `Total Cost / Quantity` when explicit unit cost is not clearly detected.

## DB snapshot helper
To refresh `storage/blank.sqlite` from your current DB:

```bash
copy storage\\app.sqlite storage\\blank.sqlite /Y
```
