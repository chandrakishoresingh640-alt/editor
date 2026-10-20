# Quire

Quire is a PHP document toolkit. Visitors can enhance scanned PDFs, summarize a transcript, read text out of an image, build a searchable PDF, mark up a PDF, and remove a password they already have.

The site is HTML, CSS, and JavaScript in the browser, with PHP 8.2+ on the server. It does not need Node.js, a database, Composer, Docker, Redis, or a background worker. Optional programs such as Ghostscript, Tesseract, Poppler, and qpdf are used when they are installed. When they are missing, the page says so and the browser path still runs.

## Mandatory and optional

**Required to serve the site**

- PHP 8.2 or newer (tested on 8.3).
- Extensions: `gd`, `fileinfo`, `mbstring`, `json`, `session`.
- Apache with `mod_rewrite` if the domain’s document root is the project folder rather than `public/`. PHP’s built-in server can be used for a local check and does not need rewrite rules when `-t public` is set.
- HTTPS in production. The session cookie is marked Secure when the request is HTTPS.

**Required for the full server path, and often missing on shared hosting**

- Ghostscript (`gs`) renders PDF pages for enhancement.
- Tesseract OCR, with at least the `eng` language, reads images on the server.
- Poppler (`pdfinfo`) counts pages. qpdf can count pages when Poppler is absent.
- qpdf removes supported PDF passwords and permission flags. A pure-PHP reader is the fallback for standard PDF security (revisions 2 through 6, including AES-256 and RC4).
- `zip` is required for DOCX download.
- `curl` is required for YouTube metadata, Google OAuth, and the optional AI provider.
- `intl` improves Unicode password normalisation. Unlocking still runs without it.
- `proc_open` must be allowed. If the host disables it, server binaries cannot run and the browser tools remain.

Do not assume a shared host will let you install those binaries. Quire checks for them at runtime.

**Not required**

- MariaDB or MySQL. Nothing in this version stores accounts or documents in a database.
- Composer. No PHP package is installed.
- A Node build. The browser libraries in `public/assets/vendor/` are already built.

## Layout

```
public/                 document root
  index.php             homepage
  tools/                the six tools
  api/                  upload, processing, download
  assets/               CSS, JavaScript, fonts, PDF.js, pdf-lib, tesseract.js
  legal/                privacy, terms, cookies
includes/               PHP that must not be web-accessible
config/                 environment.example.php only; real config stays here
private_storage/        uploads, processed files, logs, contact messages
scripts/cleanup.php     deletes expired files
```

Uploaded bytes are stored under `private_storage/uploads/<session>/` with a random id. The public download URL checks that id against the session. Files are not executed.

If the host cannot place `private_storage` outside the document root, point the domain at `public/` (preferred) or keep the root `.htaccess`, which denies `includes`, `config`, `private_storage`, `scripts`, and `tests`.

## Install

1. Upload the project, or clone it.
2. Copy the example config. Placeholders only — do not put a live key in the example file.

   ```bash
   cp config/environment.example.php config/environment.php
   ```

3. Make the private folders writable by the PHP user:

   ```bash
   chmod 750 private_storage private_storage/uploads private_storage/processed private_storage/logs private_storage/messages
   ```

   On some shared hosts the PHP user and the FTP user differ. Use 770 only if 750 prevents PHP from creating files, and avoid 777.

4. Point the domain at `public/` when the panel allows a custom document root. Otherwise leave the document root at the project folder and keep the root `.htaccess`.

5. Turn on HTTPS in the panel, then set the site to redirect HTTP to HTTPS.

6. Raise `upload_max_filesize` and `post_max_size` in PHP so they are at least as large as `max_upload_bytes` (25 MB by default). The app uses the smaller of the config value and those PHP limits.

7. Confirm the setup:

   ```bash
   php tests/run.php
   ```

   Open the homepage and each tool. The capability note on a tool tells you which server programs that page can see.

## Environment

`config/environment.php` returns an array. Real keys belong in that file or in environment variables. Variables override the file. `config/environment.php` is gitignored.

| Variable | Config key | Purpose |
| --- | --- | --- |
| `QUIRE_APP_NAME` | `app_name` | Name in the header |
| `QUIRE_BASE_PATH` | `base_path` | Prefix such as `/quire` when the site is not at the domain root |
| `QUIRE_TIMEZONE` | `timezone` | PHP timezone |
| `QUIRE_MAX_UPLOAD_BYTES` | `max_upload_bytes` | Upload ceiling, default 26214400 |
| `QUIRE_MAX_PDF_PAGES` | `max_pdf_pages` | Page ceiling, default 30 |
| `QUIRE_RETENTION_MINUTES` | `retention_minutes` | How long uploads are kept, default 60, minimum 5 |
| `QUIRE_RATE_UPLOAD` | `rate_upload_per_hour` | Upload rate limit |
| `QUIRE_RATE_PROCESS` | `rate_process_per_hour` | Enhancement and OCR rate limit |
| `QUIRE_RATE_UNLOCK` | `rate_unlock_per_hour` | Unlock attempts, default 12 |
| `QUIRE_RATE_AI` | `rate_ai_per_hour` | AI summary rate limit |
| `AI_API_KEY` | `ai_api_key` | Optional. Empty uses the local extractive summary |
| `AI_API_ENDPOINT` | `ai_api_endpoint` | OpenAI-compatible base URL, default `https://api.openai.com/v1` |
| `AI_MODEL` | `ai_model` | Model name sent to that endpoint |
| `AI_TIMEOUT` | `ai_timeout` | Seconds |
| `YOUTUBE_API_KEY` | `youtube_api_key` | Optional YouTube Data API v3 key |
| `GOOGLE_CLIENT_ID` | `google_client_id` | OAuth client id |
| `GOOGLE_CLIENT_SECRET` | `google_client_secret` | OAuth secret |
| `GOOGLE_REDIRECT_URI` | `google_redirect_uri` | Must match the Google Cloud client, usually `https://your-domain/api/google-callback.php` |
| `CONTACT_EMAIL` | `contact_email` | Address for contact-form mail |
| `QUIRE_TRUST_PROXY` | `trust_proxy` | `true` only behind a proxy you control |
| `QUIRE_DEBUG` | `debug` | Leave false. Visitor errors stay generic either way |

An `.env` file is not required. If you use one, the keys above can be exported by the host.

## Hostinger hPanel and cPanel

1. In the file manager, upload a zip of the project into the account and extract it. A subdirectory such as `quire/` is fine.
2. In **Domains → document root** (hPanel) or **Domains → Document Root** (cPanel), set the root to the `public` folder inside the project. That is the preferred layout.
3. If the panel cannot change the document root, set it to the project folder and leave `.htaccess` in place. Requests are rewritten into `public/`, and the private folders are denied.
4. In **PHP Configuration** or **Select PHP Version**, choose PHP 8.2 or 8.3. Enable `gd`, `fileinfo`, `mbstring`, `zip`, `curl`, and `intl` if they are listed.
5. Set `upload_max_filesize` and `post_max_size` to at least 32M, and `max_execution_time` to at least 120 if the panel allows it. Large PDFs are still capped by `max_pdf_pages`.
6. Enable the free SSL certificate and force HTTPS.
7. Create `config/environment.php` from the example in the file manager. Do not leave API keys in a file under `public/`.
8. Set `private_storage` and its four child folders to writable (750 or 770).

Shared plans usually cannot install Ghostscript, Tesseract, Poppler, or qpdf. That is expected. Enhancement, OCR, searchable PDFs, and PDF editing still run in the browser. Password removal uses the built-in PHP reader for standard encryption when qpdf is not installed. If `proc_open` is disabled, the capability API reports the binaries as unavailable.

## Another PHP host

Copy the same tree. The document root should be `public/`, or the host must honour the root `.htaccess`. PHP 8.2+ and the extensions above are the only hard requirements. Set the same environment variables in the panel if you prefer them to `config/environment.php`. Schedule `php /path/to/scripts/cleanup.php` every hour if you want cleanup even when nobody visits. A visit also runs cleanup, at most once every 10 minutes.

## Google OAuth and YouTube

YouTube public details use oEmbed with no key. A YouTube Data API v3 key (`YOUTUBE_API_KEY`) adds the official title, channel, description, and thumbnail. The key stays on the server.

Caption download is separate. Quire does not scrape timed-text or unofficial transcript endpoints. Captions are requested with the YouTube Captions API only after the visitor signs in with Google, and only for videos owned by that channel. If captions cannot be fetched, the page explains why and the visitor can paste a transcript they already have. An unpublished script is not available from a video URL.

Google Docs export uses the same OAuth client. It creates a document from text the visitor chooses to send. Images are not uploaded to Google from the image-to-PDF page.

In Google Cloud Console:

1. Create a project and enable the YouTube Data API and the Google Docs API.
2. Configure the OAuth consent screen.
3. Create an OAuth client of type Web application.
4. Add the redirect URI `https://your-domain/api/google-callback.php`.
5. Put the client id, secret, and that exact redirect URI in `config/environment.php`.

If the client is not configured, the Docs button is disabled and the caption control stays hidden. PDF and text downloads still work.

## Optional AI provider

Set `AI_API_KEY` and, if you are not using OpenAI, `AI_API_ENDPOINT` to an OpenAI-compatible base URL. Quire sends `POST {endpoint}/chat/completions` with a bearer token from PHP. The key is never placed in HTML or browser JavaScript.

The summarizer calls that provider only when the visitor ticks the consent box. With the box off, or with no key, the summary is extractive: it selects sentences from the transcript on the server. A failed AI call falls back to that extractive summary and says so.

To point at another provider later, change the endpoint and model. The request shape stays the chat-completions API.

## OCR languages

Server languages are whatever `tesseract --list-langs` prints. Browser OCR uses `*.traineddata` or `*.traineddata.gz` files in `public/assets/vendor/tesseract/lang/`. English is included. Add another language by placing its traineddata file in that folder and reloading the tool. The language menus list only those files.

## Limits and cleanup

- `max_upload_bytes` caps each upload. The effective limit is the minimum of that value, `upload_max_filesize`, and `post_max_size`.
- `max_pdf_pages` caps PDF pages and the number of images combined into one server-built PDF.
- `retention_minutes` is how long files under `private_storage/uploads` and `private_storage/processed` are kept. Dotfiles such as `.gitkeep` and `.htaccess` are left in place.
- Rate limits are counted per IP in `private_storage/logs`. Unlock attempts stay low so the tool cannot be used as a password search.

Run cleanup by hand:

```bash
php scripts/cleanup.php
```

## Diagnosing a missing tool

Open `/api/capabilities.php` in a browser after loading the homepage (so the session exists), or read the note at the top of each tool.

| Symptom | What it means |
| --- | --- |
| `ghostscript: false` | Enhancement on the server is off. Use **Enhance in the browser**. Deskew is disabled because it needs Ghostscript and GD. |
| `tesseract: false` | Server OCR and server searchable PDFs are off. Browser OCR still runs. |
| `qpdf: false` | Unlock uses the built-in PHP reader. Unusual encryption handlers can fail with a clear error. |
| `proc_open: false` | No server binary can run. |
| `gd: false` | Server image filters and server image-to-PDF are off. |
| `zip: false` | DOCX download is disabled. TXT and PDF still work. |
| `curl: false` | YouTube details, Google, and AI cannot leave the server. Pasted transcripts can still be summarized locally. |
| Upload rejected | The file signature was not PDF, JPEG, PNG, GIF, or WebP. The browser’s MIME type is not trusted on its own. |
| “Form expired” | The CSRF token did not match. Reload the page. |
| Password not accepted | The supplied password was wrong. The tool does not try others. |

PHP errors are written to the server log without the document contents, passwords, or API keys. The page shows a short message and does not include a filesystem path or a stack trace.

## What each tool does

| Path | Behaviour |
| --- | --- |
| `/tools/enhance-pdf.php` | Preview, grayscale, brightness, contrast, sharpen, noise reduction, threshold, rotation, and optional searchable text. Deskew runs on the server when Ghostscript and GD exist. |
| `/tools/youtube-summarizer.php` | Validates a YouTube URL, loads public metadata, and summarizes pasted or owned-caption text. |
| `/tools/image-to-editable-text.php` | Crop, rotate, filters, OCR, an editable transcript, and a reconstruction mode whose font size follows each line’s height. |
| `/tools/image-to-pdf-docs.php` | Reorder and rotate images, choose A4, Letter, or fit, set margins and orientation, and add an invisible text layer when OCR runs. |
| `/tools/pdf-editor.php` | View with PDF.js. Add text, highlights, ink, lines, and shapes. The overlay eraser is a white rectangle. Permanent redaction, or a flattened eraser, rebuilds the page as an image so the covered text is not left in the file. |
| `/tools/unlock-pdf.php` | Reports encryption, accepts one password, and writes a new PDF. Permission-only files (empty user password) can have those flags cleared after confirmation. |
| `/tools/todo.php` | Task lists stored in the browser: several lists, priorities, due dates, notes, subtasks, search, and TXT or PDF export. |

A white rectangle is not described as permanent redaction. Strong encryption is not removed without the password. The original upload is kept until retention deletes it.

## Legal pages

Privacy, terms, and cookies are under `public/legal/`. The contact form is `public/contact.php`. They describe retention, optional third parties, and deletion requests. They do not claim a compliance certification.

## Local check

```bash
php -S 127.0.0.1:8080 -t public -d upload_max_filesize=32M -d post_max_size=32M
php tests/run.php
```

## Licences

See [LICENSES.md](LICENSES.md).
