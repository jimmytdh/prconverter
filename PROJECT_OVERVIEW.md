# Project Overview

## What this project is
This is a plain-PHP web app for processing Purchase Request (PR) PDFs:
- Upload PR PDF files
- Extract structured fields and item rows from PDF text/OCR
- Save parsed results to a database
- Manage item rows per PR record
- Export records to a formatted `.xlsx` file

The UI is server-rendered PHP plus jQuery-driven AJAX actions, styled with Tailwind (CDN).

## Current architecture
- `index.php`: main authenticated dashboard (upload, list, pagination, item management modals)
- `upload.php`: multi-stage endpoint for `process`, `save`, and `cancel`
- `parser.php`: PDF text extraction + PR field/item parsing logic
- `db.php`: environment loading, DB connection, and table bootstrap
- `auth.php`, `login.php`, `logout.php`: session auth + login flow
- `items.php`, `save_item.php`, `delete_item.php`: item CRUD APIs
- `delete.php`: delete PR record
- `export.php` + `xlsx_export.php`: generate and stream native XLSX output

## Runtime flow
1. User logs in via `login.php` (credentials checked against `users` table).
2. User uploads a PDF from `index.php`.
3. `upload.php?action=process` stores a temporary file in `uploads/`, extracts text, parses fields/items, and returns preview JSON.
4. User confirms:
- Continue: `upload.php?action=save` inserts PR + items into DB in a transaction.
- Cancel: `upload.php?action=cancel` deletes the temporary file.
5. Saved records appear in the paginated table (`purchase_requests`, newest first).
6. User can:
- Open/edit/delete item rows (`items.php`, `save_item.php`, `delete_item.php`)
- Delete a whole PR (`delete.php`)
- Export a PR to XLSX (`export.php?id=...`)

## Database model
Tables are created automatically by `db.php` (if missing):

- `purchase_requests`
  - Main PR metadata and parsed header fields
  - Includes `raw_text` storage and `total_cost`
- `purchase_request_items`
  - Itemized rows linked to PR by `purchase_request_id`
  - `ON DELETE CASCADE` foreign key to `purchase_requests`
  - Index: `idx_purchase_request_items_prid`

Notes:
- DB engine in code is MySQL (`PDO mysql:` DSN), not SQLite.
- Authentication expects a separate existing `users` table; this table is not auto-created in code.

## Extraction and parsing strategy
`parser.php` uses layered extraction:
1. `pdftotext -layout` (if available)
2. Python extraction (`pypdf`)
3. OCR fallback (`pymupdf` + `tesseract`) for scanned/image PDFs

If multiple extraction candidates succeed, parser scoring chooses the best based on parsed-item quality.

Parsing behavior:
- Regex-driven extraction for PR-level fields (`fund_cluster`, `pr_no`, date, names/designations, etc.)
- Table-slice parsing for item rows
- Heuristics to fix OCR/layout issues (column alignment, trailing quantity in descriptions)
- Fallback calculations:
  - PR `total_cost` from item sum when missing
  - Single-row `unit_cost = total_cost / quantity` when possible

## Frontend behavior
- Server-rendered initial table with pagination (`20` per page)
- jQuery AJAX operations for upload/process/save/cancel and item CRUD
- Modal-based confirmation for:
  - Save-vs-cancel after processing
  - PR delete
  - Item delete
- Inline status panel for success/error/info messages

## Export subsystem
`xlsx_export.php` builds XLSX files manually (XML + ZipArchive), no PhpSpreadsheet dependency.

Highlights:
- Supports multi-item export
- Applies template-like merged cells and styles
- Computes total from item totals when header total is missing
- Output streamed by `export.php` with proper XLSX headers

## Configuration and dependencies
Configuration is loaded from `.env` (or defaults) via `loadDotEnv()` in `db.php`.

Expected env vars (`.env.example`):
- DB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- Auth DB override/fallback: `TDH_USER_HOST`, `TDH_USER_PORT`, `TDH_USER_NAME`, `TDH_USER_USER`, `TDH_USER_PASS`

System/runtime dependencies:
- PHP with PDO MySQL extension
- PHP `ZipArchive` extension (for XLSX export)
- Optional but important for extraction quality:
  - `pdftotext`
  - Python 3 + `pypdf` + `pymupdf`
  - `tesseract`

Frontend CDN dependencies:
- Tailwind CDN
- jQuery CDN
- Google Fonts (Manrope)

## Directory layout
- `/` root PHP endpoints and pages
- `uploads/`: uploaded temporary and saved PDF files
- `storage/`: temp/parser helper storage
- `src/`: image assets and sample/reference PDFs

## Security and operational notes
- Session-based authentication with `session_regenerate_id()` on login
- Protected endpoints enforce `requireAuth(true|false)`
- File upload validation checks extension + MIME type
- SQL uses prepared statements throughout

Current gaps to be aware of:
- No CSRF protection on POST endpoints
- No explicit upload size/rate limiting in app code
- Error handling generally returns generic messages (good for exposure control, limited for diagnostics)

## Notable codebase reality checks
- README still describes SQLite, but code is now MySQL-oriented.
- Project has no automated test suite in repository.
- App is frameworkless and entrypoint-based; behavior is split across many endpoint scripts instead of a router/controller structure.
