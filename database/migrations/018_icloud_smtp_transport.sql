-- The owner selected their existing iCloud SMTP service; local capture remains available.
ALTER TABLE email_outbox MODIFY transport ENUM('local_capture','brevo','icloud_smtp') NOT NULL DEFAULT 'local_capture';
