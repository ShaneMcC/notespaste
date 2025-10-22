# Pastebin Application - Development Documentation

This document provides technical context, architecture details, and development guidelines for the pastebin application.

## Architecture Overview

This is a file-based pastebin application built with PHP 8.1+ that prioritizes simplicity and no database dependencies. The architecture follows these key principles:

1. **File-based storage** - All data stored in `notes/` directory with JSON metadata
2. **Pre-rendered HTML** - Pastes are rendered to static HTML files for fast delivery
3. **Authentication via .htpasswd** - Simple HTTP basic auth without database overhead
4. **Template-driven rendering** - Twig templates for all pages, enabling easy customization

## Project Structure

```
/
├── composer.json           # Dependencies and autoloading
├── .htaccess              # URL routing and security rules
├── .htpasswd              # User credentials (bcrypt hashed)
├── index.php              # Main routing file
├── src/                   # PHP classes (PSR-4 autoloaded as App\)
│   ├── Auth.php          # Authentication logic
│   ├── Paste.php         # Paste management
│   ├── PasteRenderer.php # HTML rendering
│   └── Helpers.php       # Utility functions
├── templates/            # Twig templates
│   ├── base.html.twig
│   ├── home.html.twig
│   ├── paste.html.twig
│   ├── edit.html.twig
│   ├── login.html.twig
│   └── rerender-results.html.twig
├── static/
│   └── style.css         # All application CSS
└── notes/                # Paste storage
    └── {paste-id}/
        ├── _meta.json
        ├── files/
        └── {slug}.html
```

## Key Design Decisions

### 1. File-Based Storage

**Why:** Simplicity, portability, no database setup required.

Each paste is stored as a directory with a random 20-30 character alphanumeric ID:
```
notes/caith6XaePeeKi0queic/
  ├── _meta.json           # Metadata and file list
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
  "createdAt": "2025-01-15T10:30:00+00:00",
  "updatedAt": "2025-01-15T14:20:00+00:00",
  "files": [
    {
      "filename": "example.md",
      "render": "rendered",
      "type": "markdown"
    },
    {
      "filename": "script.php",
      "render": "highlighted",
      "type": "php"
    }
  ]
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
- Template changes can be applied via "Rerender" without editing paste
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

**Exception:** Rendered HTML uses relative paths (`./files/filename`) so file links work regardless of base path.

### 4. Render Modes

Each file in a paste has a `render` mode that determines how it's displayed:

| Mode | Purpose | Implementation |
|------|---------|----------------|
| `plain` | Raw text | Wrapped in `<pre>` tag |
| `highlighted` | Syntax highlighting | `<pre><code class="language-{type}">` with highlight.js |
| `rendered` | Markdown to HTML | League CommonMark parser |
| `image` | Display image | `<img src="./files/{filename}">` |
| `file` | Download link | `<a href="./files/{filename}" download>` |
| `link` | List of URLs | Each line becomes `<a>` tag |

The `type` field specifies language for highlighting or additional context.

### 5. Public/Private Visibility

**Why:** Allow users to create private notes visible only when logged in.

- `public: true` - Visible to everyone, appears in public paste list
- `public: false` - Only visible to logged-in users, shown with 🔒 icon

Implementation:
- `Paste::listAll($publicOnly)` filters based on authentication state
- Anonymous users see only public pastes
- Logged-in users see all pastes
- Lock icon in title: `{% if not meta.public %}🔒 {% endif %}`

### 6. Authentication Flow

Simple session-based auth with .htpasswd:

1. User submits login form
2. `Auth::login($username, $password)` checks against .htpasswd (bcrypt)
3. On success, `$_SESSION['authenticated'] = true; $_SESSION['username'] = $username`
4. `Auth::isLoggedIn()` checks session state
5. `Auth::requireLogin()` redirects to login if not authenticated

**Security notes:**
- Sessions are PHP's default (PHPSESSID cookie)
- Passwords stored as bcrypt hashes in .htpasswd
- No CSRF tokens (could be added)
- No rate limiting (could be added)

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

1. **No CSRF protection** - Forms don't have CSRF tokens (could add)
2. **No rate limiting** - Login attempts not throttled (could add)
3. **No file uploads** - Files must be pasted as text (could add multipart upload)
4. **No paste deletion** - Once created, pastes exist forever (could add delete route)
5. **No edit history** - Edits overwrite previous version (could add versioning)
6. **No search** - No way to search paste content (could add with grep or search index)
7. **No pagination** - All pastes load on homepage (could add pagination)
8. **No API** - Only web interface available (could add JSON API)
9. **Single user tier** - All logged-in users have same permissions (could add roles)
10. **No paste expiration** - Pastes don't auto-delete (could add TTL)

## Security Considerations

### Current Security Measures

1. **Private pastes require authentication** - Enforced in router before serving HTML
2. **_meta.json blocked** - .htaccess prevents direct access: `RewriteRule ^notes/.*/_meta\.json$ - [F,L]`
3. **Directory indexes disabled** - `Options -Indexes` in .htaccess
4. **Password hashing** - bcrypt via .htpasswd
5. **Session-based auth** - PHP sessions for authentication state
6. **File path validation** - Filenames sanitized to prevent directory traversal

### Potential Vulnerabilities

1. **XSS in paste content** - Markdown and plain text are not sanitized (by design for code sharing)
2. **Session fixation** - No session regeneration on login (should add `session_regenerate_id(true)`)
3. **Timing attacks** - Password comparison may be vulnerable (PHP's password_verify is safe, but htpasswd parsing isn't)
4. **No HTTPS enforcement** - Credentials sent in plaintext over HTTP (should use HTTPS)
5. **No content-type validation** - Files accepted with any extension (could restrict)

### Recommendations for Production

1. **Enable HTTPS** - Use Let's Encrypt, enforce HTTPS redirect
2. **Add session_regenerate_id()** - In Auth::login() after successful auth
3. **Add CSRF tokens** - Use hidden input in forms, validate on POST
4. **Sanitize HTML output** - In rendered markdown mode, use HTML Purifier
5. **Add rate limiting** - Throttle login attempts (e.g., 5 per minute per IP)
6. **Change default credentials** - Update .htpasswd immediately after install
7. **Restrict file types** - Only allow text-based files in uploads
8. **Add paste deletion** - Allow users to delete their own pastes
9. **Implement CSP headers** - Content Security Policy to prevent XSS
10. **Regular backups** - Backup notes/ directory and .htpasswd

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

1. **Paste deletion** - Allow users to delete their own pastes
2. **Edit history** - Version control for pastes (store previous _meta.json)
3. **Search** - Full-text search across paste content
4. **Pagination** - Limit homepage to 20 pastes, add pagination
5. **File uploads** - Support binary file uploads (images, PDFs, etc.)
6. **Paste cloning** - "Fork" existing paste to create new version
7. **Syntax themes** - Multiple highlight.js themes
8. **Export** - Download paste as .zip archive
9. **API** - JSON API for programmatic access
10. **Webhooks** - Notify external services on paste create/update
11. **Markdown preview** - Live preview when editing markdown
12. **Drag-and-drop upload** - Drag files into edit page
13. **Paste templates** - Pre-defined file structures
14. **Collaboration** - Multiple users can edit same paste
15. **Comments** - Allow commenting on pastes

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
- **Dependencies**: bramus/router, twig/twig, league/commonmark
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
- **checkPassword()**: Parses .htpasswd, verifies bcrypt hashes
- **login()**: Authenticates user, sets session variables
- **logout()**: Destroys session
- **isLoggedIn()**: Checks session state
- **requireLogin()**: Redirects to login if not authenticated
- **getUsername()**: Returns current username from session

### src/Paste.php
- **generateId()**: Creates random 20-30 char alphanumeric ID
- **__construct()**: Loads existing paste or creates new
- **save()**: Writes metadata and files to disk, triggers render
- **render()**: Calls PasteRenderer to generate HTML
- **listAll()**: Returns all pastes (with public/private filtering)
- **getHtmlUrl()**: Returns URL to rendered HTML
- **delete()**: Not implemented (future enhancement)

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
- **Simple form**: Username and password fields
- **Error display**: Shows authentication errors
- **POST target**: /login

### templates/rerender-results.html.twig
- **Results table**: Shows each paste with success/failure status
- **Error messages**: Displays rendering errors
- **Actions**: Back to home button

### static/style.css
- **No inline styles**: All styling externalized
- **Component styles**: Buttons, forms, paste lists, headers
- **Button fixes**: Ensures `<button>` and `<a.button>` same height
- **Responsive**: Basic mobile-friendly styles

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

- [ ] Change default credentials in .htpasswd
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
- [ ] Implement CSRF protection
- [ ] Add Content Security Policy headers
- [ ] Test all render modes work correctly
- [ ] Verify public/private paste visibility
- [ ] Test rerender functionality
- [ ] Check for XSS vulnerabilities in content

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

---

**Last Updated**: 2025-01-15
**Version**: 1.0.0
**Maintainer**: See .htpasswd for current admin
