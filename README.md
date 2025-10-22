# Pastebin Application

A simple, file-based pastebin application built with PHP.

## Features

- **File-based storage** - No database required
- **Multi-file pastes** - Each paste can contain multiple files
- **Public/Private pastes** - Control visibility of your pastes
- **Multiple render modes:**
  - Plain text
  - Syntax-highlighted code (via highlight.js)
  - Rendered markdown
  - Images
  - File downloads
  - URL lists
- **Clean URLs** - SEO-friendly URLs with mod_rewrite
- **Authentication** - Simple .htpasswd-based login
- **Template rendering** - Pre-rendered HTML for fast delivery
- **Rerender functionality** - Update rendered output without editing

## Setup

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure authentication:**
   ```bash
   cp .htpasswd.example .htpasswd
   # Edit .htpasswd and add your users
   # Generate password hashes with: htpasswd -nbB username password
   ```

3. **Configure web server:**
   - Ensure Apache mod_rewrite is enabled
   - Point document root to this directory
   - The `.htaccess` file handles URL routing

4. **Set permissions:**
   ```bash
   chmod 755 notes/ src/ templates/ static/
   chmod 644 .htpasswd
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
- **Multiple files** - Add as many files as needed

## File Structure

```
notes/
  └── {paste-id}/              # Random 20-30 char ID
      ├── _meta.json           # Paste metadata
      ├── files/               # Uploaded files
      │   ├── file1.md
      │   └── file2.php
      └── {slug}.html          # Pre-rendered HTML
```

## Render Modes

Each file in a paste can have its own render mode:

- **plain** - Display as-is in a `<pre>` block
- **highlighted** - Syntax highlighting with highlight.js (specify language in "type")
- **rendered** - Render markdown to HTML
- **image** - Display image inline
- **file** - Provide download link for binary files
- **link** - Display list of URLs as clickable hyperlinks

## Requirements

- PHP 8.1+
- Apache with mod_rewrite
- Composer

## Security

- Private pastes require login to view
- .htpasswd files are protected from direct access
- `_meta.json` files are blocked from direct access
- Directory listings are disabled
- Sessions are used for authentication state

## Technology Stack

- **Backend**: PHP 8.1+ with Bramus Router
- **Templating**: Twig
- **Markdown**: League CommonMark
- **Syntax Highlighting**: highlight.js (client-side)
- **Styling**: Custom CSS (no framework)
