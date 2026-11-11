USE school;

ALTER TABLE attempts
 MODIFY status ENUM('started','pending_review','graded','stopped') NOT NULL DEFAULT 'started';
