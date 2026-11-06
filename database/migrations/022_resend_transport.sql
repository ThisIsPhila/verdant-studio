-- The owner selected Resend for provider delivery on their domain; local capture remains available.
ALTER TABLE email_outbox MODIFY transport ENUM('local_capture','brevo','icloud_smtp','resend') NOT NULL DEFAULT 'local_capture';
