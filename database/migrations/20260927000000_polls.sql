-- Umfragen (Polls): Formular, Terminfindung, anonyme Abstimmung.
--
-- Neues Modell mit gemeinsamem Poll-Header, gemeinsamen Kindtabellen
-- (PollQuestion/PollOption/PollInvite) und typspezifischen Antwort-/
-- Stimmtabellen (PollResponse/PollAnswer, PollAvailabilityVote, PollVote).
--
-- Hinweis: Diese Datei ist NICHT blind rerun-faehig. Die Legacy-Tabellen
-- PollVote, PollInvite, PollOption, PollQuestion und Poll werden gedroppt;
-- bestehende Legacy-Daten gehen dabei verloren (Datenverlust ist fuer diese
-- Umstellung akzeptiert).

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `PollVote`;
DROP TABLE IF EXISTS `PollInvite`;
DROP TABLE IF EXISTS `PollOption`;
DROP TABLE IF EXISTS `PollQuestion`;
DROP TABLE IF EXISTS `Poll`;

-- 2.1 Gemeinsamer Header
CREATE TABLE `Poll` (
  id varchar(191) NOT NULL,
  type enum('form','appointment','vote') NOT NULL,
  creatorId varchar(191) NOT NULL,
  title varchar(255) NOT NULL,
  description text NULL,
  status enum('open','closed') NOT NULL DEFAULT 'open',
  closesAt datetime(3) NULL,
  accessMode enum('invited','all_users') NOT NULL DEFAULT 'invited',
  submissionMode enum('single','multiple') NOT NULL DEFAULT 'single',
  resultsVisibility enum('creator','participants') NOT NULL DEFAULT 'creator',
  allowCounterProposals tinyint NOT NULL DEFAULT 0,
  finalizedOptionId varchar(191) NULL,
  reminderSentAt datetime(3) NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_poll_creator (creatorId),
  KEY idx_poll_type_status (type,status),
  KEY idx_poll_due (status,closesAt),
  CONSTRAINT fk_poll_creator FOREIGN KEY (creatorId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.2 Gemeinsame Kindtabellen
CREATE TABLE `PollQuestion` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  type varchar(32) NOT NULL,
  title text NOT NULL,
  description text NULL,
  isRequired tinyint NOT NULL DEFAULT 0,
  position smallint NOT NULL DEFAULT 0,
  config json NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_question_poll (pollId,position),
  CONSTRAINT fk_question_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `PollOption` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  questionId varchar(191) NULL,
  label text NULL,
  allDay tinyint NOT NULL DEFAULT 0,
  timezone varchar(64) NULL,
  startAt datetime(3) NULL,
  endAt datetime(3) NULL,
  startDate date NULL,
  endDate date NULL,
  isCounterProposal tinyint NOT NULL DEFAULT 0,
  proposedBy varchar(191) NULL,
  position smallint NOT NULL DEFAULT 0,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_option_poll (pollId),
  KEY idx_option_question (questionId),
  CONSTRAINT fk_option_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_option_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE,
  CONSTRAINT fk_option_proposer FOREIGN KEY (proposedBy) REFERENCES User(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `PollInvite` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  userId varchar(191) NOT NULL,
  isIndispensable tinyint NOT NULL DEFAULT 0,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_invite_poll_user (pollId,userId),
  CONSTRAINT fk_invite_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_invite_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.3 Typspezifisch: Formular
CREATE TABLE `PollResponse` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  userId varchar(191) NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_response_poll_user (pollId,userId),
  CONSTRAINT fk_response_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_response_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `PollAnswer` (
  id varchar(191) NOT NULL,
  responseId varchar(191) NOT NULL,
  questionId varchar(191) NOT NULL,
  value mediumtext NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_answer_response (responseId),
  CONSTRAINT fk_answer_response FOREIGN KEY (responseId) REFERENCES PollResponse(id) ON DELETE CASCADE,
  CONSTRAINT fk_answer_question FOREIGN KEY (questionId) REFERENCES PollQuestion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.4 Typspezifisch: Terminfindung
CREATE TABLE `PollAvailabilityVote` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  optionId varchar(191) NOT NULL,
  userId varchar(191) NOT NULL,
  availability enum('yes','maybe','no') NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updatedAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_avail_poll_option_user (pollId,optionId,userId),
  KEY idx_avail_poll (pollId),
  CONSTRAINT fk_avail_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_avail_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE,
  CONSTRAINT fk_avail_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.5 Typspezifisch: Anonyme Abstimmung
CREATE TABLE `PollVote` (
  id varchar(191) NOT NULL,
  pollId varchar(191) NOT NULL,
  optionId varchar(191) NOT NULL,
  participantHash char(64) NOT NULL,
  createdAt datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_vote_poll_hash_option (pollId,participantHash,optionId),
  KEY idx_vote_poll_option (pollId,optionId),
  KEY idx_vote_hash (pollId,participantHash),
  CONSTRAINT fk_pollvote_poll FOREIGN KEY (pollId) REFERENCES Poll(id) ON DELETE CASCADE,
  CONSTRAINT fk_pollvote_option FOREIGN KEY (optionId) REFERENCES PollOption(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
