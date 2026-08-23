# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

# Pastebin Application - Development Documentation

This document provides technical context, architecture details, and development guidelines for the pastebin application.

## Architecture Overview

This is a file-based pastebin application built with PHP 8.1+ that prioritizes simplicity and no database dependencies. The architecture follows these key principles:

1. **File-based storage** - All data stored in `notes/` directory with JSON metadata
2. **Pre-rendered HTML** - Pastes are rendered to static HTML files for fast delivery
3. **Authentication via .htpasswd or OIDC** - Simple credential auth without database overhead, optionally backed by an identity provider
4. **Template-driven rendering** - Twig templates for all pages, enabling easy customization

## Commonly Used Commands

### Installation

```bash
# Install PHP dependencies
composer install

# Generate password hash for authentication
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"

# Or use htpasswd tool
htpasswd -nbB username password
```

### Docker

```bash
# Build Docker image
docker build -t pastebin:latest .

# Run container with authentication
docker run -d \
  -p 8080:80 \
  -e AUTH_USER=admin \
  -e AUTH_PASSWORD=password123 \
  -v ./notes:/app/public/notes \
  pastebin:latest

# Run container with password hash (more secure)
docker run -d \
  -p 8080:80 \
  -e AUTH_USER=admin \
  -e AUTH_PASSWORD_HASH='$2y$10$...' \
  -v ./notes:/app/public/notes \
  pastebin:latest

# View logs
docker logs -f <container-id>

# Access container shell
docker exec -it <container-id> bash
```

### Development

```bash
# Run PHP built-in server (for testing)
php -S localhost:8000 -t public/

# Watch Apache logs (in Docker)
docker exec <container-id> tail -f /var/log/apache2/error.log

# Check PHP syntax
php -l public/index.php
php -l src/*.php
```

### Maintenance

```bash
# Backup notes directory
tar -czf backup-notes-$(date +%Y%m%d).tar.gz public/notes/

# Backup authentication file
cp config/.htpasswd backup-htpasswd-$(date +%Y%m%d)

# Check disk usage
du -sh public/notes/

# Count total pastes
ls -1d public/notes/*/ | wc -l

# View paste metadata
cat public/notes/<paste-id>/_meta.json | jq .

# Fix permissions
chmod -R 755 public/notes/
chown -R www-data:www-data public/notes/
```

### Testing

```bash
# Currently no automated tests
# Manual testing checklist:
# 1. Create new paste (with random ID)
# 2. Create new paste with custom ID
# 3. Edit existing paste
# 4. Delete paste
# 5. Test all render modes (plain, highlighted, rendered, image, file, file-link, link)
# 6. Test file uploads
# 7. Test public/private visibility
# 8. Test login/logout
# 9. Test rerender functionality
# 10. Test line numbers (highlighted mode)
# 11. Test alias creation (random and custom)
# 12. Test alias deletion
# 13. Test promoting alias to primary
# 14. Test CSRF protection (forms should fail without token)
# 15. Test OIDC login (button appears, round trip logs you in)
# 16. Test OIDC + local login together (divider shown), and OIDC on its own (no password form)
# 17. Test OIDC callback rejection (tampered state, replayed code, expired id_token)
```

## Project Structure

```
/
├── config/                  # Configuration directory
│   ├── config.php                 # Main configuration
│   ├── config.local.php           # Local overrides (gitignored, optional)
│   ├── config.local.example.php   # Template for config.local.php
│   ├── .htpasswd                  # User credentials (bcrypt hashed)
│   └── .htpasswd.example          # Example credentials file
├── public/                 # Web root directory
│   ├── index.php          # Main routing file
│   ├── .htaccess          # Apache rewrite rules
│   ├── static/            # CSS and JavaScript assets
│   │   ├── style.css
│   │   └── edit.js        # Edit page JavaScript
│   └── notes/             # Paste storage
│       └── {paste-id}/
│           ├── _meta.json
│           ├── files/
│           └── {slug}.html
├── src/                   # PHP classes (PSR-4: App\)
│   ├── Auth.php
│   ├── Csrf.php
│   ├── Helpers.php
│   ├── Oidc.php
│   ├── Paste.php
│   ├── PasteRenderer.php
│   ├── RenderMode.php
│   └── TwigFactory.php
├── templates/             # Twig templates
│   ├── base.html.twig
│   ├── home.html.twig
│   ├── paste.html.twig
│   ├── edit.html.twig
│   ├── login.html.twig
│   └── rerender-results.html.twig
├── vendor/               # Composer dependencies
├── composer.json
├── Dockerfile
├── .github/workflows/    # CI/CD pipeline
│   └── build-and-deploy.yml
├── README.md
└── CLAUDE.md
```

## Key Design Decisions

### 1. File-Based Storage

**Why:** Simplicity, portability, no database setup required.

Each paste is stored as a directory with either a random 20-30 character alphanumeric ID or a user-specified custom ID:
```
notes/caith6XaePeeKi0queic/    # Random ID
notes/my-custom-paste/          # Custom ID (alphanumeric, hyphens, underscores, max 64 chars)
notes/alias-to-paste/           # Alias directory (contains only _alias.json)
  ├── _meta.json           # Metadata and file list
  ├── _alias.json          # Only present for aliases, points to parent paste
  ├── files/               # Uploaded files
  │   ├── example.md
  │   └── script.php
  └── my-paste-slug.html   # Pre-rendered HTML
```

**_meta.json structure:**
```json
{
  "title": "My Paste",
  "slug": "my-paste-slug",
  "summary": "Optional summary for listings",
  "description": "Optional description shown at top",
  "author": "username",
  "public": true,
  "displayMode": "multi-normal",
  "selectedFile": "example.md",
  "createdAt": "2025-01-15T10:30:00+00:00",
  "updatedAt": "2025-01-15T14:20:00+00:00",
  "aliases": ["my-short-url", "another-alias"],
  "files": {
    "example.md": {
      "displayName": "My Example",
      "description": "This is an example file",
      "render": "rendered",
      "type": "markdown",
      "hidden": false,
      "unwrapped": false,
      "collapsed": false,
      "collapsedDescription": ""
    },
    "script.php": {
      "displayName": "",
      "description": "",
      "render": "highlighted",
      "type": "php",
      "hidden": false,
      "unwrapped": false,
      "collapsed": true,
      "collapsedDescription": "PHP utility script",
      "lineNumbers": true,
      "lineNumberStart": 1
    }
  }
}
```

**_alias.json structure (for alias directories):**
```json
{
  "parent": "caith6XaePeeKi0queic"
}
```

### 2. Pre-Rendered HTML

**Why:** Fast delivery, no processing overhead for views, works even if PHP crashes.

When a paste is saved or re-rendered:
1. `PasteRenderer` loads metadata and file contents
2. Each file is rendered according to its `render` mode
3. Complete HTML page generated via Twig template
4. Saved to `notes/{id}/{slug}.html`
5. Apache serves this static file directly

**Benefits:**
- Viewing pastes requires no PHP execution
- Direct file access through Apache is extremely fast

### 3. BASE_PATH Detection

**Why:** Support subdirectory installations without hardcoded paths.

The application detects its base path from `$_SERVER['SCRIPT_NAME']`:
```php
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$basePath = $scriptPath === '/' ? '' : $scriptPath;
define('BASE_PATH', $basePath);
```

This allows the app to work at:
- `http://example.com/` (basePath = '')
- `http://example.com/pastebin/` (basePath = '/pastebin')
- `http://localhost/notes/` (basePath = '/notes')

All internal redirects use `BASE_PATH . '/route'` and templates use `{{ basePath }}/route`.

**Exception:** Rendered HTML uses relative paths (`./files/filename`) so file links work regardless of base path, allows changing the ID of pastes easily.

### 4. Render Modes

Each file in a paste has a `render` mode that determines how it's displayed:

| Mode | Purpose | Implementation |
|------|---------|----------------|
| `plain` | Raw text | Wrapped in `<pre>` tag |
| `highlighted` | Syntax highlighting | `<pre><code class="language-{type}">` with highlight.js |
| `rendered` | Markdown to HTML | League CommonMark parser |
| `image` | Display image | `<img src="./files/{filename}">` |
| `file` | Download link | `<a href="./files/{filename}" download>` |
| `file-link` | View file link | `<a href="./files/{filename}" target="_blank">` (no download attribute) |
| `link` | List of URLs | Each line becomes `<a>` tag |

The `type` field specifies language for highlighting or additional context.

**Auto-detection for uploads:**
- Images (`image/*` MIME type) automatically set to `image` mode
- Binary files (non-text MIME) automatically set to `file` mode
- Text files keep user-selected render mode

### 5. Public/Private Visibility

**Why:** Allow users to hide pastes from public listings (security through obscurity).

- `public: true` - Visible to everyone, appears in public paste list
- `public: false` - Hidden from public listing, only shown to logged-in users in their paste list, displays 🔒 icon

**IMPORTANT SECURITY NOTE:**
Private pastes are **NOT truly private**! Because pastes are pre-rendered to static HTML files served directly by Apache, anyone with the full URL (`/notes/{id}/{slug}.html`) can view the content without authentication. The "private" flag only:
- Removes the paste from the public homepage listing
- Shows a lock icon to indicate it's unlisted
- Requires login to see it in the admin paste list

This is **security through obscurity** - the paste IDs are random and long (20-30 characters), making them hard to guess, but they are not password-protected.

Implementation:
- `Paste::listAll($publicOnly)` filters based on authentication state
- Anonymous users see only public pastes in listing
- Logged-in users see all pastes in listing
- Lock icon in title: `{% if not meta.public %}🔒 {% endif %}`
- Direct HTML file access bypasses all PHP authentication checks

### 6. Authentication Flow

Two ways in, both ending at the same session state.

**Local (password) login:**

1. User submits login form
2. `Auth::authenticate($username, $password)` checks the AUTH_* env vars, then .htpasswd (bcrypt)
3. On success, `Auth::loginAs()` regenerates the session ID and sets `$_SESSION['username']`
4. `Auth::isLoggedIn()` checks session state
5. `Auth::requireLogin()` redirects to login if not authenticated

**OIDC login** (only offered when `Oidc::isConfigured()`):

1. User clicks "Login with {OIDC_NAME}" → `GET /login/oidc`
2. `Oidc::getAuthorizationUrl()` fetches the discovery document, mints `state`/`nonce`/PKCE
   verifier into the session, and returns the provider URL to redirect to
3. Provider sends the user back to `GET /login/oidc/callback?code=...&state=...`
4. `Oidc::handleCallback()` checks `state`, exchanges the code for tokens over the back
   channel, verifies the `id_token`, and returns a username
5. `Auth::loginAs($username, Auth::SOURCE_OIDC)` starts the session as above

Both methods can be enabled at once. `Auth::hasLocalLogin()` reports whether password
logins are possible at all, which is what decides if the login page shows the
"or local login:" form.

**Security notes:**
- Sessions are PHP's default (PHPSESSID cookie)
- `Auth::loginAs()` calls `session_regenerate_id(true)`, so both login paths are safe from session fixation
- Passwords stored as bcrypt hashes in .htpasswd or AUTH_USER/AUTH_PASSWORD/AUTH_PASSWORD_HASH env vars
- CSRF tokens required on all POST forms (via `Csrf` class)
- OIDC login CSRF is handled by the one-shot `state` parameter rather than a form token
- No rate limiting (could be added)

### 7. Display Modes

**Why:** Provide flexibility for different content types and presentation needs.

The application supports four display modes:

| Mode | Container Width | Files Shown | Wrapped |
|------|----------------|-------------|---------|
| `multi-normal` | Standard (800px) | All non-hidden | Yes |
| `multi-wide` | Full width | All non-hidden | Yes |
| `single-normal` | Standard (800px) | Selected file only | No (forced unwrapped) |
| `single-wide` | Full width | Selected file only | No (forced unwrapped) |

**Implementation:**
- Set via dropdown in edit form: `$_POST['displayMode']`
- Stored in `_meta.json`: `"displayMode": "multi-normal"`
- Single-file mode requires `selectedFile` to be set
- CSS media query applies `.container { max-width: 100%; }` for wide modes
- In `PasteRenderer.php`, single-file mode forces `unwrapped: true` on the selected file

### 8. File Options

Each file in `_meta.json` has additional metadata beyond filename and render mode:

```json
{
  "filename.txt": {
    "displayName": "My Custom Name",        // Optional: Override filename in UI
    "description": "File description",      // Optional: Shown above content
    "type": "javascript",                   // Language for highlighting
    "render": "highlighted",                // How to render the file
    "hidden": false,                        // Hide in multi-file mode
    "unwrapped": false,                     // Render without wrapper/header
    "collapsed": true,                      // Start collapsed (multi-file)
    "collapsedDescription": "Click to expand", // Shown when collapsed
    "lineNumbers": true,                    // Show line numbers (highlighted mode only)
    "lineNumberStart": 1                    // Starting line number
  }
}
```

**Usage:**
- `hidden: true` - File exists but doesn't appear in multi-file view (useful for data files referenced by other files)
- `unwrapped: true` - Removes file header and wrapper, content flows directly into page (good for narrative content)
- `collapsed: true` - File section starts collapsed, user can expand by clicking header
- `collapsedDescription` - Brief summary visible even when collapsed
- `displayName` - Override filename in headers (useful for long/technical filenames)
- `description` - Explanatory text shown above file content
- `lineNumbers: true` - Show line numbers in the gutter (only for `highlighted` render mode)
- `lineNumberStart` - Start line numbering from a specific number (default: 1)

### 9. Configuration System

**Why:** Support different deployment environments without code changes.

Configuration is loaded from `config/config.php` which reads environment variables:

```php
return [
    'htpasswd_path' => getenv('HTPASSWD_PATH') ?: __DIR__ . '/.htpasswd',
    'notes_dir' => getenv('NOTES_DIR') ?: __DIR__ . '/../public/notes',
    'oidc' => [
        'issuer' => getenv('OIDC_ISSUER') ?: '',
        'client_id' => getenv('OIDC_CLIENTID') ?: '',
        'client_secret' => getenv('OIDC_SECRET') ?: '',
        'name' => getenv('OIDC_NAME') ?: '',
        'redirect_uri' => getenv('OIDC_REDIRECT_URI') ?: '',
    ],
];
```

This allows:
- Docker deployments to mount volumes at custom paths
- Development environments to use different locations
- Testing environments to use temporary directories

**Local overrides:**

`config/config.php` then merges in `config/config.local.php` if that file exists.
It is gitignored and listed in `.dockerignore`, so it only ever applies when the
app is run directly (`php -S localhost:8000 -t public/`) rather than in a container.

```php
// config/config.local.php
return [
    'oidc' => ['issuer' => 'https://auth.example.com', 'name' => 'Local SSO'],
];
```

The merge uses `array_replace_recursive()`, so a partial nested array only replaces
the keys it names - the example above leaves `oidc.client_id` and friends intact.
Values here win over the environment variables. A `config.local.php` that returns
anything other than an array throws, rather than being silently ignored.

`config/config.local.example.php` is the tracked template users copy from.

**Initialization:**
```php
// In public/index.php
$config = require __DIR__ . '/../config/config.php';
Auth::init($config['htpasswd_path']);
Oidc::init($config['oidc']);
Paste::setNotesDir($config['notes_dir']);
```

### 10. Paste Aliases and Custom IDs

**Why:** Allow memorable URLs and multiple access points to the same paste.

**Custom Paste IDs:**
When creating a paste, users can specify a custom ID instead of accepting a random one:
- Must be alphanumeric with hyphens and underscores only
- Maximum 64 characters
- Must be unique (not conflict with existing paste IDs or aliases)

**Aliases:**
A paste can have multiple aliases that redirect to the same content:
- Each alias is a directory containing only `_alias.json`
- The `_alias.json` contains `{"parent": "real-paste-id"}`
- Aliases can be promoted to become the primary ID
- When promoted, the old primary ID becomes an alias

**Implementation:**
- `Paste::idExists($id)` - Checks if any ID (paste or alias) exists
- `Paste::exists($id)` - Checks if ID is a real paste (has `_meta.json`)
- `Paste::isAlias($id)` - Checks if ID is an alias (has `_alias.json`)
- `Paste::resolveAlias($id)` - Returns parent ID for an alias
- `Paste::getRealId($id)` - Resolves alias to real ID, or returns same ID
- `$paste->addAlias($aliasId)` - Add a new alias to a paste
- `$paste->removeAlias($aliasId)` - Remove an alias
- `$paste->makePrimary($aliasId)` - Promote an alias to be the primary ID
- `$paste->getAliases()` - Get all aliases for a paste

### 11. Theme Support

**Why:** Provide comfortable viewing in different lighting conditions.

The application uses CSS custom properties for theming:

```css
:root {
    --bg-primary: #f5f5f5;
    --text-primary: #333;
    --link-color: #3498db;
    /* ... 20+ more variables */
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg-primary: #1a1a1a;
        --text-primary: #e0e0e0;
        --link-color: #4dabf7;
        /* ... dark mode overrides */
    }
}
```

**Implementation:**
- All colors defined as CSS variables in `public/static/style.css`
- No hardcoded colors in styles
- Automatic theme switching based on browser/OS preference
- 20+ languages supported for syntax highlighting via highlight.js

## Common Development Tasks

### Adding a New Route

In [index.php](index.php), add before `$router->run()`:

```php
$router->get('/my-route', function() use ($twig) {
    Auth::requireLogin(); // Optional: require authentication
    echo $twig->render('my-template.html.twig', [
        'data' => 'value',
        'isLoggedIn' => Auth::isLoggedIn(),
    ]);
});
```

### Creating a New Template

In [templates/my-template.html.twig](templates/my-template.html.twig):

```twig
{% extends 'base.html.twig' %}

{% block title %}My Page - Pastebin{% endblock %}

{% block content %}
<header>
    <h1>My Page</h1>
</header>

<p>Content goes here</p>
{% endblock %}
```

### Adding a New Paste Metadata Field

1. Update [src/Paste.php](src/Paste.php) `save()` method to include new field in metadata
2. Update [templates/edit.html.twig](templates/edit.html.twig) to add input field
3. Update [templates/paste.html.twig](templates/paste.html.twig) or [templates/home.html.twig](templates/home.html.twig) to display field
4. Run "Rerender All" to update existing pastes (they'll get default value)

Example (adding "tags" field):
```php
// In Paste.php save() method:
'tags' => $data['tags'] ?? [],

// In edit.html.twig:
<div class="form-group">
    <label for="tags">Tags (comma-separated)</label>
    <input type="text" id="tags" name="tags" value="{{ meta.tags|join(', ') }}">
</div>

// In paste.html.twig:
{% if meta.tags %}
    <div class="tags">
        {% for tag in meta.tags %}
            <span class="tag">{{ tag }}</span>
        {% endfor %}
    </div>
{% endif %}
```

### Adding a New Render Mode

1. Update [templates/edit.html.twig](templates/edit.html.twig) to add new option to render mode dropdown
2. Update [src/PasteRenderer.php](src/PasteRenderer.php) `renderFile()` method with new case:

```php
case 'custom':
    // Your custom rendering logic
    $content = file_get_contents($filePath);
    $processed = myCustomProcessor($content);
    return [
        'filename' => $file['filename'],
        'type' => $file['type'] ?? '',
        'render' => 'custom',
        'content' => $processed,
    ];
```

3. Update [templates/paste.html.twig](templates/paste.html.twig) to handle display:

```twig
{% elseif file.render == 'custom' %}
    <div class="custom-content">
        {{ file.content|raw }}
    </div>
{% endif %}
```

### Handling File Uploads

File uploads use multipart form data. The `processFileData()` helper function in `public/index.php`:

1. Checks for uploaded file: `$_FILES['file_uploads']['tmp_name'][$index]`
2. Auto-detects render mode:
   - Images (`image/*` MIME) → `render: 'image'`
   - Binary (non-text MIME) → `render: 'file'`
3. Falls back to text content from textarea if no upload
4. Sanitizes filename via `Helpers::sanitizeFilename()`

**Example:**
```php
// In form
<input type="file" name="file_uploads[0]">

// In route handler (processFileData helper)
if (isset($_FILES['file_uploads']['tmp_name'][0]) &&
    $_FILES['file_uploads']['error'][0] === UPLOAD_ERR_OK) {
    $content = file_get_contents($_FILES['file_uploads']['tmp_name'][0]);
    $mimeType = mime_content_type($_FILES['file_uploads']['tmp_name'][0]);

    // Auto-detect render mode based on MIME type
    if (strpos($mimeType, 'image/') === 0) {
        $render = 'image';
    } elseif (strpos($mimeType, 'text/') !== 0) {
        $render = 'file';
    }
}
```

### Changing Paste ID Generation

In [src/Paste.php](src/Paste.php), modify `generateId()` method:

```php
private static function generateId(): string
{
    // Current: 20-30 random alphanumeric
    $length = rand(20, 30);
    return bin2hex(random_bytes($length / 2));

    // Alternative: UUID-based
    // return str_replace('-', '', uniqid('', true));

    // Alternative: Shorter IDs
    // return substr(base64_encode(random_bytes(12)), 0, 16);
}
```

### Updating CSS Styles

All styles are in [static/style.css](static/style.css). No inline styles are used.

Common patterns:
- `.button` - Primary button style (blue)
- `.button.secondary` - Secondary button (gray)
- `.paste-*` - Paste-related components
- `.form-group` - Form field wrapper
- `.checkbox-field` - Checkbox with custom styling

### Adding User Management

Currently, users are managed via .htpasswd file. To add web-based user management:

1. Create admin routes (e.g., `/admin/users`)
2. Add admin check to Auth.php (e.g., admin username)
3. Create templates for user list, add user, delete user
4. Use `password_hash()` and `password_verify()` with BCRYPT
5. Write to .htpasswd in htpasswd format: `username:$2y$...`

**Note:** .htpasswd format is `username:hash`, one per line.

## Debugging

### Enable Error Display

In [index.php](index.php) at the top (before any output):
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Check Permissions

Common permission issues:
```bash
# Directories should be 755 (rwxr-xr-x)
chmod 755 notes/ src/ templates/ static/

# PHP files should be 644 (rw-r--r--)
chmod 644 src/*.php index.php

# .htpasswd should be 644
chmod 644 .htpasswd
```

### View Paste Metadata

```bash
cat notes/caith6XaePeeKi0queic/_meta.json | jq .
```

### Check .htaccess Routing

If routes aren't working:
1. Verify mod_rewrite is enabled: `apache2ctl -M | grep rewrite`
2. Check Apache error log: `tail -f /var/log/apache2/error.log`
3. Ensure AllowOverride is set (in Apache config): `AllowOverride All`

### Test Authentication

```bash
# Verify .htpasswd format
cat .htpasswd

# Generate new password hash
htpasswd -nbB username password

# Should output: username:$2y$...
```

### Clear Session Data

```bash
# Find PHP session storage
php -i | grep session.save_path

# Remove session files (logout all users)
rm /var/lib/php/sessions/sess_*
```

## Known Limitations

1. **No rate limiting** - Login attempts not throttled (could add)
2. **No edit history** - Edits overwrite previous version (could add versioning)
3. **No search** - No way to search paste content (could add with grep or search index)
4. **No pagination** - All pastes load on homepage (could add pagination)
5. **No API** - Only web interface available (could add JSON API)
6. **Single user tier** - All logged-in users have same permissions (could add roles); OIDC has no group/claim filtering, so restrict access at the provider
7. **No paste expiration** - Pastes don't auto-delete (could add TTL)
8. **No file size limits** - Large files can cause memory issues (could add validation)
9. **No dark mode toggle** - Theme follows system preference only (could add manual toggle)
10. **No content-type restrictions** - Any file type accepted (could whitelist extensions)

## Security Considerations

### Current Security Measures

1. **Private pastes are security through obscurity** - Pre-rendered HTML is accessible to anyone with the URL. "Private" only hides from homepage listing.
2. **_meta.json and _alias.json blocked** - .htaccess prevents direct access to metadata files
3. **Directory indexes disabled** - `Options -Indexes` in .htaccess
4. **Password hashing** - bcrypt via .htpasswd
5. **Session-based auth** - PHP sessions for authentication state (only affects admin UI, not paste viewing)
6. **File path validation** - Filenames sanitized to prevent directory traversal
7. **Executable file proxy** - .htaccess forces PHP, CGI, Python, and other executable extensions through PHP proxy route to prevent direct execution
8. **Binary content detection** - Helper function identifies binary content to prevent display issues
9. **CSRF protection** - All POST forms require valid CSRF tokens via the `Csrf` class
10. **Session fixation** - `Auth::loginAs()` regenerates the session ID on every successful login
11. **OIDC** - Authorization code flow with PKCE where supported, one-shot state/nonce, full id_token signature verification against the provider's JWKS, and an asymmetric-only algorithm allowlist

### Potential Vulnerabilities

1. **XSS in paste content** - Markdown and plain text are not sanitized (by design for code sharing)
2. **Timing attacks** - Password comparison may be vulnerable (PHP's password_verify is safe, but htpasswd parsing isn't)
3. **No content-type validation** - Files accepted with any extension (could restrict)
4. **No OIDC authorization** - Anyone who can authenticate with the provider gets full access; there is no group or claim filtering

### Recommendations for Production

1. **Enable HTTPS** - Use Let's Encrypt, enforce HTTPS redirect (required in practice for OIDC)
2. **Sanitize HTML output** - In rendered markdown mode, use HTML Purifier
3. **Add rate limiting** - Throttle login attempts (e.g., 5 per minute per IP)
4. **Change default credentials** - Update .htpasswd immediately after install
5. **Restrict file types** - Only allow text-based files in uploads
6. **Implement CSP headers** - Content Security Policy to prevent XSS
7. **Regular backups** - Backup notes/ directory and .htpasswd
8. **Scope OIDC access** - Restrict who can log in from the provider side, since the app authorizes every authenticated user

## Testing

Currently no automated tests. To add testing:

1. Install PHPUnit: `composer require --dev phpunit/phpunit`
2. Create `tests/` directory with test files
3. Add test cases for:
   - Auth::login() with valid/invalid credentials
   - Paste::create() and Paste::save()
   - PasteRenderer::render() for each render mode
   - Helpers::sanitizeFilename() for path traversal

Example test structure:
```php
// tests/AuthTest.php
use PHPUnit\Framework\TestCase;
use App\Auth;

class AuthTest extends TestCase
{
    public function testLoginWithValidCredentials()
    {
        $this->assertTrue(Auth::login('admin', 'password123'));
    }

    public function testLoginWithInvalidCredentials()
    {
        $this->assertFalse(Auth::login('admin', 'wrongpassword'));
    }
}
```

## Future Enhancements

Potential features to add:

1. **Edit history** - Version control for pastes (store previous _meta.json)
2. **Search** - Full-text search across paste content
3. **Pagination** - Limit homepage to 20 pastes, add pagination
4. **Paste cloning** - "Fork" existing paste to create new version
5. **Syntax themes** - Multiple highlight.js themes
6. **Export** - Download paste as .zip archive
7. **API** - JSON API for programmatic access
8. **Webhooks** - Notify external services on paste create/update
9. **Markdown preview** - Live preview when editing markdown
10. **Paste templates** - Pre-defined file structures
11. **Collaboration** - Multiple users can edit same paste
12. **Comments** - Allow commenting on pastes

## Contributing Guidelines

When modifying this application:

1. **Maintain simplicity** - Avoid adding database dependencies
2. **Keep files small** - Split large classes if they exceed 300 lines
3. **Use PSR-4 autoloading** - New classes go in `src/` with `App\` namespace
4. **External CSS only** - Never use inline styles
5. **Document metadata changes** - Update this file if adding fields to _meta.json
6. **Test subdirectory installs** - Ensure BASE_PATH works correctly
7. **Test both auth states** - Verify features work logged in and logged out
8. **Update README.md** - Keep user-facing docs in sync with changes

## Architecture Diagram

```
┌─────────────┐
│   Browser   │
└──────┬──────┘
       │
       ▼
┌─────────────────────────────────────┐
│         Apache + mod_rewrite        │
│  .htaccess routes requests          │
└──────┬──────────────────────────────┘
       │
       ├─────────────┐
       │             │
       ▼             ▼
  Static Files   index.php
  *.html         (Router)
  *.css              │
  files/*            ├──► Auth::requireLogin()
                     │         │
                     │         ▼
                     │    Session Check
                     │
                     ├──► Paste::create()
                     │    Paste::save()
                     │         │
                     │         ▼
                     │    notes/{id}/_meta.json
                     │    notes/{id}/files/*
                     │
                     ├──► PasteRenderer::render()
                     │         │
                     │         ▼
                     │    Twig Templates
                     │         │
                     │         ▼
                     │    notes/{id}/{slug}.html
                     │
                     └──► Response (HTML/Redirect)
```

## File-by-File Breakdown

### composer.json
- **Dependencies**: bramus/router, twig/twig, league/commonmark, firebase/php-jwt (id_token verification for OIDC)
- **Autoloading**: PSR-4 maps `App\` to `src/`
- **Purpose**: Dependency management and class autoloading

### .htaccess
- **Line 1-4**: Enable rewrite engine, disable directory indexes
- **Line 7**: Block access to `_meta.json` files (403 Forbidden)
- **Line 10-12**: Allow direct access to files in `notes/*/files/` (images, downloads)
- **Line 15-17**: Allow access to pre-rendered `.html` files
- **Line 20-21**: Allow static assets (CSS, JS)
- **Line 24-25**: Route everything else through index.php

### index.php
- **Line 1-5**: Error handling, session start, autoload
- **Line 7-10**: BASE_PATH detection for subdirectory support
- **Line 12-17**: Initialize Twig with template directory and basePath global
- **Line 19-20**: Initialize router
- **Line 22-40**: Route definitions (GET /login, POST /login, /logout, etc.)
- **Line 42-70**: Note routes (/notes/new, /notes/{id}/edit, etc.)
- **Line 72-95**: Rerender routes
- **Line 97**: Run router

### src/Auth.php
- **authenticate()**: Checks AUTH_* env vars then .htpasswd (bcrypt), logs the user in on success
- **loginAs()**: Starts a session for an already-authenticated user (regenerates the session ID); used by both the password and OIDC paths
- **hasLocalLogin()**: Whether password logins are possible at all (AUTH_* set, or .htpasswd has entries)
- **htpasswdEntries()**: Parses .htpasswd into [username, hash] pairs, skipping comments and malformed lines
- **logout()**: Destroys session
- **isLoggedIn()**: Checks session state
- **getCurrentUser()**: Returns current username from session
- **getAuthSource()**: How the current user logged in (`SOURCE_LOCAL` or `SOURCE_OIDC`)
- **requireLogin()**: Redirects to login if not authenticated

### src/Csrf.php
- **generateToken()**: Creates a new CSRF token and stores in session
- **getToken()**: Returns current token or generates new one
- **validateToken()**: Validates a token against session token
- **validateRequest()**: Validates CSRF token from POST data or header
- **requireValidToken()**: Halts execution if token is invalid
- **getHiddenInput()**: Returns HTML hidden input element with token

### src/Oidc.php
OpenID Connect authorization code flow. Everything is static and driven by the config
array passed to `init()`; the whole feature stays dormant unless all four required
settings are present.

- **init()**: Stores the config (issuer, client_id, client_secret, name, redirect_uri)
- **isConfigured()**: True only when issuer, client_id, client_secret and name are all non-empty
- **getName()**: Provider display name, used for the login button
- **getAuthorizationUrl()**: Builds the provider URL, stashing `state`, `nonce` and the PKCE verifier in the session
- **handleCallback()**: Validates `state`, exchanges the code, verifies the id_token, returns a username
- **getRedirectUri()**: The callback URL - `OIDC_REDIRECT_URI` if set, otherwise derived from the request (honouring `X-Forwarded-Proto`/`X-Forwarded-Host`)
- **exchangeCode()**: Back-channel token request, using `client_secret_basic` or `client_secret_post` depending on what discovery advertises
- **verifyIdToken()**: Pins the algorithm to an asymmetric allowlist, verifies the signature against the JWKS, then checks iss/aud/azp/nonce/sub (exp/nbf/iat come from the JWT library)
- **fetchUserInfo()**: Optional extra claims from the userinfo endpoint; a network failure degrades gracefully, but a `sub` mismatch is fatal
- **resolveUsername()**: Picks `preferred_username`, `email`, `name` or `sub`, in that order
- **discover()**: Fetches and session-caches the `.well-known/openid-configuration` document (1 hour TTL)

**Notes for future changes:**
- Requested scopes are fixed at `openid profile email` (`Oidc::SCOPE`)
- Never widen `ALLOWED_ALGS` to include HMAC algorithms - the JWKS is public, so an HS256 id_token would be forgeable
- The callback route is `/login/oidc/callback`; changing it means re-registering the redirect URI with every provider

### src/Paste.php
- **generateId()**: Creates random 20-30 char alphanumeric ID
- **create()**: Creates new paste with optional custom ID
- **__construct()**: Loads existing paste or creates new
- **save()**: Writes metadata and files to disk, triggers render
- **render()**: Calls PasteRenderer to generate HTML
- **listAll()**: Returns all pastes (with public/private filtering)
- **getHtmlUrl()**: Returns URL to rendered HTML
- **delete()**: Deletes paste and all its aliases
- **idExists()**: Checks if ID exists as paste or alias
- **exists()**: Checks if ID is a real paste
- **isAlias()**: Checks if ID is an alias
- **resolveAlias()**: Resolves alias to parent paste ID
- **getRealId()**: Returns real ID, resolving aliases
- **addAlias()**: Creates a new alias for the paste
- **removeAlias()**: Removes an alias
- **makePrimary()**: Promotes an alias to be the primary ID
- **getAliases()**: Returns all aliases for the paste
- **syncFiles()**: Handles file adds, updates, renames, removes in one operation

### src/PasteRenderer.php
- **render()**: Main rendering entry point, returns HTML
- **renderToFile()**: Saves rendered HTML to disk
- **renderFile()**: Handles individual file rendering by mode
- **Plain mode**: Wraps in `<pre>` tag
- **Highlighted mode**: Adds highlight.js classes
- **Rendered mode**: Uses League CommonMark for markdown
- **Image mode**: Creates `<img>` tag with relative path
- **File mode**: Creates download link
- **Link mode**: Converts lines to `<a>` tags

### src/Helpers.php
- **sanitizeFilename()**: Prevents directory traversal attacks
- **getMimeType()**: Determines MIME type from filename
- **redirect()**: HTTP redirect helper
- **errorPage()**: Renders error page with code and message
- **isBinaryContent()**: Detects if content is binary data

### src/RenderMode.php
- PHP enum defining all render modes (`plain`, `highlighted`, `rendered`, `image`, `file`, `file-link`, `link`)
- **label()**: Human-readable label for each mode
- **hidesTypeSelector()**: Whether mode hides the type dropdown
- **hidesContentField()**: Whether mode hides the content textarea
- **showsLineNumbers()**: Whether mode supports line numbers option
- **autoType()**: Auto-set type value for certain modes
- **options()**: Returns all modes as array for select elements
- **values()**: Returns all mode values as array
- **isValid()**: Validates if a string is a valid mode

### src/TwigFactory.php
- Singleton factory for Twig template engine
- **create()**: Creates or returns Twig instance with optional basePath
- **getInstance()**: Returns existing Twig instance
- **setTemplatesDir()**: Override templates directory

### templates/base.html.twig
- **Base layout**: HTML structure, CSS link, blocks for title/content/scripts
- **Purpose**: DRY template inheritance for all pages

### templates/home.html.twig
- **Shows**: Paste listing with title, summary, author, timestamps
- **Differentiates**: "My Pastes" (logged in) vs "Public Pastes" (anonymous)
- **Actions**: View, Edit, Rerender buttons per paste
- **Header actions**: New Paste, Rerender All, Login/Logout

### templates/paste.html.twig
- **Shows**: Rendered paste with title, description, files
- **Lock icon**: Displayed if paste is private
- **File rendering**: Different markup for each render mode
- **Actions**: Home, Edit buttons (Edit only if logged in)

### templates/edit.html.twig
- **Form fields**: Title, summary, slug, description, author, public checkbox
- **File inputs**: Filename, content, render mode, type
- **Dynamic**: JavaScript to add more files
- **Submit**: POST to /notes/new or /notes/{id}/edit

### templates/login.html.twig
- **OIDC button**: "Login with {OIDC_NAME}" shown first when `oidcEnabled`
- **Divider**: "or local login:" between the two, only when both are available
- **Simple form**: Username and password fields, shown when `localLoginEnabled`
- **Error display**: Shows authentication errors
- **POST target**: /login
- Renders "No login methods are configured." if neither is set up

### templates/rerender-results.html.twig
- **Results table**: Shows each paste with success/failure status
- **Error messages**: Displays rendering errors
- **Actions**: Back to home button

### static/style.css
- **No inline styles**: All styling externalized
- **Component styles**: Buttons, forms, paste lists, headers
- **Button fixes**: Ensures `<button>` and `<a.button>` same height
- **Responsive**: Basic mobile-friendly styles
- **Line numbers**: Styling for code line numbers in highlighted mode

### static/edit.js
- **File editor management**: Add, remove, reorder files via drag-and-drop
- **Render mode handling**: Show/hide fields based on render mode selection
- **File uploads**: Handle file selection and drag-drop uploads
- **Alias management**: Add, remove, and promote aliases
- **Dynamic form generation**: Create file entry HTML from templates
- **Language type selector**: Populated from `hljs.listLanguages()`

## Performance Considerations

### Current Performance

- **Fast paste viewing** - Static HTML served directly by Apache
- **Slow paste creation** - Must render HTML, write files to disk
- **Slow rerender all** - Iterates all pastes, renders each (can take 30+ seconds for 100 pastes)
- **No caching** - Every paste list loads all _meta.json files

### Optimization Opportunities

1. **Paste list caching** - Cache `Paste::listAll()` results in session or file
2. **Background rerendering** - Use queue system for "Rerender All"
3. **Lazy loading** - Load paste list 20 at a time with AJAX
4. **Static asset versioning** - Cache bust CSS/JS with `?v=timestamp`
5. **Gzip compression** - Enable in Apache for HTML/CSS/JS
6. **OpCache** - Enable PHP OpCache for better performance
7. **Index file** - Maintain `notes/index.json` for fast listings

## Deployment Checklist

Before deploying to production:

- [ ] Change default credentials in config/.htpasswd
- [ ] Enable HTTPS and enforce redirect
- [ ] Set `display_errors = 0` in php.ini
- [ ] Enable error logging to file
- [ ] Set proper file permissions (755 dirs, 644 files)
- [ ] Test subdirectory paths work correctly
- [ ] Verify .htaccess rules are active
- [ ] Enable PHP OpCache
- [ ] Set up automated backups of notes/ directory
- [ ] Configure firewall rules
- [ ] Add rate limiting to login endpoint
- [ ] Add Content Security Policy headers
- [ ] Test all render modes work correctly
- [ ] Verify public/private paste visibility
- [ ] Test rerender functionality
- [ ] Check for XSS vulnerabilities in content
- [ ] Test paste deletion
- [ ] Test alias functionality
- [ ] Verify the OIDC redirect URI registered with the provider matches the deployed URL
- [ ] Confirm the reverse proxy sends X-Forwarded-Proto/Host, or set OIDC_REDIRECT_URI

### Docker Deployment

The included Dockerfile provides containerized deployment:

**Dockerfile:**
1. Uses `shanemcc/docker-apache-php-base:latest` as base
2. Copies application to `/app`
3. Symlinks `/app/public` to `/var/www/html`
4. Installs Composer dependencies
5. Sets ownership to `www-data` user

**Required Volumes:**
- `/app/public/notes` - **Required** for paste data persistence
- `/app/config` - **Optional** for custom configuration and `.htpasswd`

**CI/CD Pipeline:**

GitHub Actions workflow (`.github/workflows/build-and-deploy.yml`):
1. Triggers on push to `master` or manual dispatch
2. Builds Docker image
3. Publishes to GitHub Container Registry (ghcr.io)
4. Publishes to private Docker registry (if configured)

**Secrets Required:**
- `GITHUB_TOKEN` (automatic)
- `DOCKER_REGISTRY`, `DOCKER_REPO`, `DOCKER_USERNAME`, `DOCKER_PASSWORD` (for private registry)

## Support and Maintenance

### Backup Strategy

Critical files to backup:
```bash
# Backup notes directory (all pastes)
tar -czf backup-notes-$(date +%Y%m%d).tar.gz notes/

# Backup authentication
cp .htpasswd backup-htpasswd-$(date +%Y%m%d)

# Backup configuration
cp .htaccess backup-htaccess-$(date +%Y%m%d)
```

### Log Monitoring

Check these logs regularly:
```bash
# Apache error log
tail -f /var/log/apache2/error.log

# Apache access log
tail -f /var/log/apache2/access.log

# PHP error log (if configured)
tail -f /var/log/php-errors.log
```

### Maintenance Tasks

Regular maintenance:
1. **Weekly**: Check disk usage of notes/ directory
2. **Monthly**: Review access logs for suspicious activity
3. **Quarterly**: Update dependencies with `composer update`
4. **Yearly**: Review and update .htpasswd credentials
