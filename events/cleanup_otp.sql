-- MySQL Event: Clean up expired OTP tokens
-- Runs every hour, deletes expired tokens to keep the table small

DELIMITER //

CREATE EVENT IF NOT EXISTS clean_expired_otp_tokens
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP
COMMENT 'Removes expired and used OTP codes and Discord pairing codes'
DO
BEGIN
    DELETE FROM OtpToken
    WHERE expiresAt < NOW();
END //

DELIMITER ;
