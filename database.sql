CREATE DATABASE IF NOT EXISTS task_tracker
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE task_tracker;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

ALTER TABLE users
    MODIFY COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user';

INSERT INTO users (email, password_hash, role)
VALUES
    ('demo@example.com', '$2y$12$XuSL8w3xMye58C5.2Z3kDu93umcdWqvjg/lwlp1rRmufOJGZXywkG', 'user'),
    ('demo-admin@example.com', '$2y$12$GKn9QnMpmoUMIWe4V48WI.oRreDLPCRRX8jL4ZNtXJzgGd1BrlN9C', 'admin')
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    role = VALUES(role);

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_user_status_created (user_id, status, created_at),
    KEY idx_tasks_user_title (user_id, title),
    CONSTRAINT fk_tasks_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO tasks (user_id, title, description, status)
SELECT users.id, fixtures.title, fixtures.description, fixtures.status
FROM users
CROSS JOIN (
    SELECT 'Plan the week' AS title, 'Choose three priorities and make time for focused work.' AS description, 'in_progress' AS status
    UNION ALL SELECT 'Review project notes', 'Collect open questions before the next check-in.', 'pending'
    UNION ALL SELECT 'Send the status update', 'Share the latest progress with the team.', 'completed'
) AS fixtures
WHERE users.email = 'demo@example.com'
  AND NOT EXISTS (
      SELECT 1 FROM tasks
      WHERE tasks.user_id = users.id AND tasks.title = fixtures.title
  );
