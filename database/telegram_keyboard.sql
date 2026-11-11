USE school;

ALTER TABLE telegram_codes
 MODIFY purpose ENUM('signin','link','keyboard','keyboard_device') NOT NULL;
