-- Setup messenger_messages table for SQLite test/dev DBs.
-- Idempotent: safe to run multiple times.

CREATE TABLE IF NOT EXISTS messenger_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    body TEXT NOT NULL,
    headers TEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS IDX_75EA56E0E6D5C6AA ON messenger_messages (queue_name);
CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0 ON messenger_messages (available_at);
CREATE INDEX IF NOT EXISTS IDX_75EA56E0B84B9AA0 ON messenger_messages (delivered_at);