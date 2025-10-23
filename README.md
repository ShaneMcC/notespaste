# Pastebin Application

A simple, file-based pastebin application built with PHP.

## Features

- **File-based storage** - No database required
- **Multi-file pastes** - Each paste can contain multiple files
- **Public/Private pastes** - Control visibility of your pastes
- **File uploads** - Upload text files, images, and binary files
- **Display modes** - Single-file or multi-file layouts, normal or wide
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
- **Authentication** - Simple .htpasswd-based login
- **Template rendering** - Pre-rendered HTML for fast delivery
- **Rerender functionality** - Update rendered output without editing
- **Docker support** - Easy containerized deployment
- **Dark mode** - Automatic theme based on system preference

## Setup

### Standard Installation

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure authentication:**
   ```bash
   cp config/.htpasswd.example config/.htpasswd
   # Edit config/.htpasswd and add your users
   # Generate password hashes with: htpasswd -nbB username password
   ```

3. **Configure web server:**
   - Ensure Apache mod_rewrite is enabled
   - Point document root to the `public/` directory
   - The `.htaccess` file handles URL routing

4. **Set permissions:**
   ```bash
   chmod 755 public/notes/ src/ templates/ public/static/
   chmod 644 config/.htpasswd
   chmod 644 public/.htaccess
   ```

### Docker Deployment

#### Building the Image

```bash
docker build -t pastebin .
```

#### Running with Docker

```bash
docker run -d \
  -p 8080:80 \
  -v /path/to/notes:/app/public/notes \
  -v /path/to/config:/app/config \
  --name pastebin \
  pastebin:latest
```

#### Required Volumes

- `/app/public/notes` - Paste data persistence (required)
- `/app/config` - Configuration files including `.htpasswd` (optional)

#### Configuration via Environment Variables

```bash
docker run -d \
  -e HTPASSWD_PATH=/app/config/.htpasswd \
  -e NOTES_DIR=/app/public/notes \
  -p 8080:80 \
  pastebin:latest
```

## Configuration

The application can be configured via environment variables or the `config/config.php` file.

### Environment Variables

- `HTPASSWD_PATH` - Path to .htpasswd file (default: `config/.htpasswd`)
- `NOTES_DIR` - Path to notes storage directory (default: `public/notes`)

### Example config/config.php

```php
<?php
return [
    'htpasswd_path' => getenv('HTPASSWD_PATH') ?: __DIR__ . '/.htpasswd',
    'notes_dir' => getenv('NOTES_DIR') ?: __DIR__ . '/../public/notes',
];
```

## Default Credentials

- Username: `admin`
- Password: `password123`

**⚠️ Change these immediately after setup!**

## Usage

### For Anonymous Users
- View public pastes on the homepage at `/`
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
- **Public/Private** - Control who can view the paste
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
1. Click "Upload File" button for any file entry
2. Select a file from your computer
3. The application auto-detects:
   - **Images** - Automatically set to "Image" render mode
   - **Binary files** - Automatically set to "File Download" mode
   - **Text files** - Keep current render mode

### Binary File Handling
- Binary files are indicated with "(binary file)" in the edit interface
- Images show a preview thumbnail when editing
- Binary content is never loaded into textarea (only metadata shown)

## File Structure

```
/
├── config/                  # Configuration directory
│   ├── config.php          # Main configuration
│   ├── .htpasswd           # User credentials
│   └── .htpasswd.example   # Example credentials file
├── public/                 # Web root directory
│   ├── index.php          # Main routing file
│   ├── .htaccess          # Apache rewrite rules
│   ├── static/            # CSS and static assets
│   │   └── style.css
│   └── notes/             # Paste storage
│       └── {paste-id}/
│           ├── _meta.json
│           ├── files/
│           └── {slug}.html
├── src/                   # PHP classes (PSR-4: App\)
│   ├── Auth.php
│   ├── Paste.php
│   ├── PasteRenderer.php
│   └── Helpers.php
├── templates/             # Twig templates
├── vendor/               # Composer dependencies
├── composer.json
├── Dockerfile
└── README.md
```

## Requirements

- PHP 8.1+
- Apache with mod_rewrite
- Composer
- Docker (optional, for containerized deployment)

## Security

### Current Security Measures
- **Private pastes are security through obscurity** - Private pastes are not password protected. They are only hidden from the homepage listing. Anyone with the full URL can view the pre-rendered HTML file directly.
- `.htpasswd` files are protected from direct access
- `_meta.json` files are blocked from direct access
- Directory listings are disabled
- Sessions are used for authentication state
- **Executable file protection** - PHP, CGI, Python, and other executable files in paste storage are forced through a proxy to prevent direct execution
- Binary content detection prevents accidental rendering of binary data as text

### Important Security Note
⚠️ **Private pastes are NOT truly private!** The "private" flag only removes them from the public listing on the homepage. Because pastes are pre-rendered to static HTML files, anyone who knows or guesses the paste ID and slug can view the content directly by accessing `/notes/{id}/{slug}.html`.

If you need truly private content, use additional security measures such as:
- HTTP basic auth at the web server level
- VPN or firewall restrictions
- A different application with proper authentication on every request

## Technology Stack

- **Backend**: PHP 8.1+ with Bramus Router
- **Templating**: Twig
- **Markdown**: League CommonMark
- **Syntax Highlighting**: highlight.js (client-side)
- **Styling**: Custom CSS with dark mode support
- **Containerization**: Docker
