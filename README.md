# Pastebin Application

A simple, file-based pastebin application built with PHP, in use at https://paste.s3e.uk/

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
- **Custom paste IDs** - Use memorable URLs instead of random IDs
- **Paste aliases** - Multiple URLs pointing to the same paste
- **Paste deletion** - Remove pastes you no longer need
- **File uploads** - Upload text files, images, and binary files
- **Display modes** - Single-file or multi-file layouts, fixed-width or wide screen
- **File organization** - Drag-and-drop reordering, hide/collapse options
- **Multiple render modes:**
  - Plain text
  - Syntax-highlighted code (20+ languages via highlight.js)
  - Rendered markdown
  - Images (with preview)
  - File downloads
  - File links (view in browser)
  - URL lists
- **Line numbers** - Optional line numbers for syntax-highlighted code
- **Flexible file display** - Collapsed sections, custom display names, descriptions
- **Clean URLs** - SEO-friendly URLs with mod_rewrite
- **Authentication** - Simple .htpasswd-based or env-var based login, or OIDC single sign-on
- **CSRF protection** - Secure forms with CSRF tokens
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

The application can be configured via environment variables, or - when running
outside Docker - via a local config file (see
[Local Development](#local-development) below):

#### Environment Variables

**File Paths:**
- `HTPASSWD_PATH` - Path to .htpasswd file (default: `/app/config/.htpasswd`)
- `NOTES_DIR` - Path to notes storage directory (default: `/app/public/notes`)

**Authentication (optional):**
- `AUTH_USER` - Username for environment-based authentication
- `AUTH_PASSWORD_HASH` - Bcrypt password hash (recommended, takes priority over `AUTH_PASSWORD`)
- `AUTH_PASSWORD` - Plain text password (simpler, less secure)

**OIDC / single sign-on (optional):**
- `OIDC_ISSUER` - Issuer URL of the provider (e.g. `https://auth.example.com`)
- `OIDC_CLIENTID` - Client ID registered with the provider
- `OIDC_SECRET` - Client secret
- `OIDC_NAME` - Display name for the login button (e.g. `Authentik`)
- `OIDC_REDIRECT_URI` - Override the callback URL (optional, normally detected from the request)

All four of `OIDC_ISSUER`, `OIDC_CLIENTID`, `OIDC_SECRET` and `OIDC_NAME` must be set,
otherwise OIDC stays switched off.

### Authentication Methods

The application supports three authentication methods that can be used together:

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

3. **OIDC / Single Sign-On** (optional):
   ```bash
   docker run -d \
     -e OIDC_ISSUER=https://auth.example.com/application/o/notespaste/ \
     -e OIDC_CLIENTID=notespaste \
     -e OIDC_SECRET=your-client-secret \
     -e OIDC_NAME='Company SSO' \
     pastebin:latest
   ```

   Register the application with your identity provider as a **confidential client**
   using the **authorization code** flow, and set its redirect URI to:

   ```
   https://your-pastebin.example.com/login/oidc/callback
   ```

   (add your base path in front of `/login` if the app is installed in a subdirectory).
   The requested scopes are `openid profile email`. The username comes from the
   `preferred_username` claim, falling back to `email`, `name`, then `sub`.

   When OIDC is configured the login page leads with a `Login with <OIDC_NAME>`
   button, and the username/password form appears underneath it as "or local login:"
   - but only if local logins are actually available. Configure OIDC on its own and
   the password form disappears entirely.

   If the app sits behind a reverse proxy, it uses `X-Forwarded-Proto` and
   `X-Forwarded-Host` to build the callback URL. Set `OIDC_REDIRECT_URI` explicitly
   if your proxy does not send those.

All three methods can coexist - for password logins the application will check
environment variables first, then fall back to the .htpasswd file.

## Local Development

For local development it is usually easier to keep settings in a file than to
export environment variables every time. Copy the example and edit it:

```bash
composer install
cp config/config.local.example.php config/config.local.php
php -S localhost:8000 -t public/
```

`config/config.local.php` is gitignored and excluded from the Docker image, so it
only applies when you run the app directly like this.

It returns a partial config array that is merged over the defaults, so you only
list what you want to change. Nested settings are merged too - overriding one
`oidc` key leaves the others alone:

```php
<?php

return [
    'notes_dir' => __DIR__ . '/../public/notes',

    'oidc' => [
        'issuer' => 'https://auth.example.com',
        'client_id' => 'notespaste',
        'client_secret' => 'your-client-secret',
        'name' => 'Company SSO',
        'redirect_uri' => 'http://localhost:8000/login/oidc/callback',
    ],
];
```

These values take priority over the equivalent environment variables.

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
- **Custom ID** - Optional, use a memorable URL instead of random ID
- **Summary** - Optional, shown in paste listings
- **Description** - Optional, shown at the top of the paste
- **Author** - Defaults to your username
- **Public/Private** - Control if pastes are visible by default
- **Display Mode** - How to display the paste (see Display Modes below)
- **Multiple files** - Add as many files as needed
- **Aliases** - Create multiple URLs for the same paste
- **Delete** - Remove pastes you no longer need

### Simple and Advanced Editing

The paste editor opens in **Simple** mode for new pastes (and for existing
single-file pastes). Simple mode is for quick one-file pastes: you pick a
width, render mode and type, then paste content or upload a file. The file is
named `file.<ext>` automatically based on the type or upload, and is always
shown unwrapped in single-file display mode.

**Advanced** mode shows every option: multiple files, display names,
descriptions, hidden/collapsed files and so on. Simple mode is just a view
over the advanced form, so you can switch between them without losing
anything. Simple mode is unavailable once a paste has more than one file.

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
- **Show source link** - Add a "View <filename>" link above an unwrapped file (including single-file mode), which otherwise has no header to link from

### Render Modes
- **Plain** - Raw text in `<pre>` block
- **Highlighted** - Syntax highlighting (specify language in "Type"), with optional line numbers
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
- `_meta.json` and `_alias.json` files are blocked from direct access
- Directory listings are disabled
- **CSRF protection** - All forms are protected with CSRF tokens
- **OIDC hardening** - Authorization code flow with PKCE (where the provider supports it), one-shot `state` and `nonce` values, full `id_token` signature verification against the provider's JWKS, and issuer/audience/expiry checks. HMAC-signed and unsigned tokens are rejected outright.
- **Executable file protection** - While most files can be directly accessed (if the notes directory is under `/app/public`), we forcefully proxy PHP, CGI, Python, and certain other executable files through the application to prevent execution.
