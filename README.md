# Pastebin Application

A simple, file-based pastebin application built with PHP.

## ⚠ Note - AI Generated
Most (Basically all) of this was written using Claude Code with heavy prompting and mostly passive supervision, however I have not vetted absolutely everything it has written.

This was created to scratch a personal itch, not to be a shining example of good code, so AI was used to speed up the delivery.

## Fixes / Improvements / Features / PRs

I generally welcome fixes/improvements/features via PR as long as they allow the application to keep scratching the itch it was written for.

I will generally look favourably upon PRs as long as they don't harm my personal use of the application or add things outside of the scope I have defined for it.

Feel free to raise issues for things and I may in time decide to get to it.

# Features

- **File-based storage** - No database required
- **Multi-file pastes** - Each paste can contain multiple files
- **Public/Private pastes** - Control visibility of your pastes
- **File uploads** - Upload text files, images, and binary files
- **Display modes** - Single-file or multi-file layouts, fixed-width or wide screen.
- **File organization** - Drag-and-drop reordering, hide/collapse options
- **Multiple render modes:**
  - Plain text
  - Syntax-highlighted code (20+ languages via highlight.js)
  - Rendered markdown
  - Images (with preview)
  - File downloads
  - File links (view in browser)
  - URL lists
- **Flexible file display** - Collapsed sections, custom display names, descriptions
- **Clean URLs** - SEO-friendly URLs with mod_rewrite
- **Authentication** - Simple .htpasswd-based or env-var based login
- **Template rendering** - Pre-rendered HTML for fast delivery
- **Docker support** - Easy containerized deployment
- **Dark mode** - Automatic theme based on system preference

## Docker Deployment

```bash
docker run -d \
  -p 8080:80 \
  -e AUTH_USER=admin \
  -e AUTH_PASSWORD=password123 \
  -v /path/to/notes:/app/public/notes \
  ghcr.io/shanemcc/notespaste:latest
```
### Required Volumes

- `/app/public/notes` - Paste data persistence (required)
- `/app/config` - Configuration files including `.htpasswd` (optional)

### Configuration

The application can be configured via environment variables:

#### Environment Variables

**File Paths:**
- `HTPASSWD_PATH` - Path to .htpasswd file (default: `/app/config/.htpasswd`)
- `NOTES_DIR` - Path to notes storage directory (default: `/app/public/notes`)

**Authentication (optional):**
- `AUTH_USER` - Username for environment-based authentication
- `AUTH_PASSWORD_HASH` - Bcrypt password hash (recommended, takes priority over `AUTH_PASSWORD`)
- `AUTH_PASSWORD` - Plain text password (simpler, less secure)

### Authentication Methods

The application supports two authentication methods that can be used together:

1. **Environment Variables** (recommended for Docker):
   ```bash
   # Using bcrypt hash (secure)
   docker run -d \
     -e AUTH_USER=admin \
     -e AUTH_PASSWORD_HASH='$2y$10$...' \
     pastebin:latest

   # Or using plain text password (simpler)
   docker run -d \
     -e AUTH_USER=admin \
     -e AUTH_PASSWORD=mypassword \
     pastebin:latest
   ```

   Generate a bcrypt hash with:
   ```bash
   php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
   ```

2. **.htpasswd File** (traditional method):
   - Place bcrypt-hashed credentials in a file mounted at `/app/config/.htpasswd` (or wherever `HTPASSWD_PATH` pints)
   - Format: `username:$2y$10$...` (one per line)
   - Generate with: `htpasswd -nbB username password`

Both methods can coexist - the application will check environment variables first, then fall back to the .htpasswd file.

## Usage

### For Anonymous Users
- View public pastes on the homepage
- Public pastes are visible to everyone

### For Logged-In Users
1. Login at `/login`
2. View all pastes (public and private) on the homepage
3. Create new pastes at `/notes/new`
4. Edit pastes with the Edit button
5. Rerender pastes after template changes
6. Rerender all pastes with one click

### Paste Features
- **Title** - Required, used in URL slug
- **Summary** - Optional, shown in paste listings
- **Description** - Optional, shown at the top of the paste
- **Author** - Defaults to your username
- **Public/Private** - Control if pastes are visible by default
- **Display Mode** - How to display the paste (see Display Modes below)
- **Multiple files** - Add as many files as needed

## Display Modes

Pastes can be displayed in different layouts:

### Multi-File Modes
- **Multi File: Normal** - Standard container width, all files shown
- **Multi File: Wide** - Full-width container for large content

### Single-File Modes
- **Single File: Normal** - Shows only the selected file, standard width
- **Single File: Wide** - Shows only the selected file, full width

In single-file mode, select which file to display from the "Display File" dropdown. The selected file is rendered without wrapper/header for cleaner presentation.

## File Options

Each file in a paste can be configured with these options:

### Display Options
- **Display Name** - Override the filename in the UI (optional)
- **Description** - Show explanatory text above the file content
- **Hidden** - Hide file in multi-file mode (useful for data files)
- **Unwrapped** - Render without file header/wrapper (cleaner display)
- **Collapsed** - Start collapsed in multi-file mode (click header to expand)
- **Collapsed Description** - Brief text shown when file is collapsed

### Render Modes
- **Plain** - Raw text in `<pre>` block
- **Highlighted** - Syntax highlighting (specify language in "Type")
- **Rendered** - Render markdown to HTML
- **Image** - Display image inline
- **File Download** - Provide download link with download attribute
- **File Link** - Clickable link to view file (opens in new tab)
- **List of Links** - Convert each line to a clickable hyperlink

## File Uploads

In addition to pasting text content, you can upload files directly:

### Upload Process
1. Click "Upload File" button for any file entry and select a file from your computer, or drag+drop a file onto the file entry
2. The application auto-detects:
   - **Images** - Automatically set to "Image" render mode
   - **Binary files** - Automatically set to "File Download" mode
   - **Text files** - Keep current render mode

### Binary File Handling
- Binary files are indicated with "(binary file)" in the edit interface
- Images show a preview thumbnail when editing
- Binary content is never loaded into textarea (only metadata shown)

## Security

### Current Security Measures
- **Private pastes are security through obscurity** - Private pastes are not password protected. They are only hidden from the homepage listing. Anyone with the full URL can view the pre-rendered HTML file directly.
- `_meta.json` files are blocked from direct access
- Directory listings are disabled
- **Executable file protection** - While most files can be directly accessed (if the notes directory is under `/app/public`), we forcefully proxy PHP, CGI, Python, and certain other executable files through the application to prevent execution.
