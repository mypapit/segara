CREATE TABLE admins (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    auth_epoch INTEGER NOT NULL DEFAULT 1 CHECK (auth_epoch > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE media (
    id TEXT PRIMARY KEY,
    original_name TEXT NOT NULL,
    stored_path TEXT NOT NULL UNIQUE,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL CHECK (size >= 0),
    alt_text TEXT NOT NULL DEFAULT '',
    decorative INTEGER NOT NULL DEFAULT 0 CHECK (decorative IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE pages (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    body_markdown TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    state TEXT NOT NULL DEFAULT 'draft' CHECK (state IN ('published', 'private', 'draft', 'trash')),
    schema_type TEXT NOT NULL DEFAULT 'WebPage' CHECK (schema_type IN ('WebPage', 'Article', 'ProfilePage', 'ContactPage', 'AboutPage')),
    author_id TEXT REFERENCES admins(id) ON DELETE SET NULL,
    author_name TEXT NOT NULL,
    published_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    placement TEXT NOT NULL DEFAULT 'none' CHECK (placement IN ('none', 'home', 'featured')),
    password_hash TEXT,
    featured_media_id TEXT REFERENCES media(id) ON DELETE SET NULL,
    video_path TEXT,
    show_contact_form INTEGER NOT NULL DEFAULT 0 CHECK (show_contact_form IN (0, 1)),
    buttons_json TEXT NOT NULL DEFAULT '[]'
);

CREATE TABLE tags (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE page_tags (
    page_id TEXT NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    tag_id TEXT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (page_id, tag_id)
);

CREATE TABLE menus (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE menu_items (
    id TEXT PRIMARY KEY,
    menu_id TEXT NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
    parent_id TEXT REFERENCES menu_items(id) ON DELETE CASCADE,
    target_type TEXT NOT NULL CHECK (target_type IN ('page', 'custom')),
    page_id TEXT REFERENCES pages(id) ON DELETE SET NULL,
    custom_url TEXT,
    label TEXT NOT NULL,
    position INTEGER NOT NULL
);

CREATE TABLE blocks (
    id TEXT PRIMARY KEY,
    location TEXT NOT NULL CHECK (location IN ('left', 'right', 'footer')),
    position INTEGER NOT NULL,
    type TEXT NOT NULL,
    settings_json TEXT NOT NULL DEFAULT '{}',
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0)
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    updated_at TEXT NOT NULL
);

CREATE TABLE messages (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE throttle (
    key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL CHECK (failures >= 0),
    window_started INTEGER NOT NULL,
    retry_after INTEGER NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_pages_public ON pages (state, published_at DESC);
CREATE INDEX idx_pages_placement ON pages (state, placement, published_at DESC);
CREATE INDEX idx_menu_items_order ON menu_items (menu_id, parent_id, position);
CREATE INDEX idx_menu_items_parent ON menu_items (parent_id);
CREATE INDEX idx_blocks_order ON blocks (location, position);
CREATE INDEX idx_messages_unread ON messages (created_at DESC) WHERE is_read = 0;
CREATE INDEX idx_throttle_expiry ON throttle (retry_after);
