-- Схема базы ТСЖ «Пушкинская 15» для MySQL 5.7+ / MariaDB 10.3+
-- Время хранится как DATETIME по Москве (пишет PHP).

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login          VARCHAR(64)  NOT NULL UNIQUE,
  name           VARCHAR(100) NOT NULL,
  role           VARCHAR(16)  NOT NULL DEFAULT 'manager',      -- admin | manager
  password_hash  VARCHAR(255) NOT NULL,
  telegram_id    BIGINT NULL UNIQUE,
  is_on_duty     TINYINT(1) NOT NULL DEFAULT 0,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL,
  last_login_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(100) NULL,
  phone             VARCHAR(16)  NULL UNIQUE,                  -- +7XXXXXXXXXX
  telegram_id       BIGINT NULL UNIQUE,
  telegram_username VARCHAR(64)  NULL,
  email             VARCHAR(190) NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  last_activity_at  DATETIME NOT NULL,
  anonymized_at     DATETIME NULL,
  KEY idx_contacts_tgu (telegram_username),
  KEY idx_contacts_activity (last_activity_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consents (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id      INT UNSIGNED NOT NULL,
  channel         VARCHAR(8)  NOT NULL,                        -- site | bot
  action          VARCHAR(8)  NOT NULL,                        -- given | revoked
  policy_version  VARCHAR(32) NOT NULL,
  ip              VARCHAR(45) NULL,
  telegram_id     BIGINT NULL,
  created_at      DATETIME NOT NULL,
  KEY idx_consents_contact (contact_id),
  CONSTRAINT fk_consents_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deals (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  number             VARCHAR(12) NULL UNIQUE,                  -- L-000123
  contact_id         INT UNSIGNED NOT NULL,
  stage              VARCHAR(16) NOT NULL DEFAULT 'new',       -- new | contacted | invoiced | paid | lost
  manager_id         INT UNSIGNED NULL,
  amount             DECIMAL(12,2) NULL,
  subject            VARCHAR(200) NULL,
  lost_reason        VARCHAR(255) NULL,
  is_off_hours       TINYINT(1) NOT NULL DEFAULT 0,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  stage_changed_at   DATETIME NOT NULL,
  first_response_at  DATETIME NULL,
  closed_at          DATETIME NULL,
  KEY idx_deals_contact (contact_id, stage),
  KEY idx_deals_manager (manager_id, stage),
  KEY idx_deals_created (created_at),
  CONSTRAINT fk_deals_contact FOREIGN KEY (contact_id) REFERENCES contacts(id),
  CONSTRAINT fk_deals_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deal_stage_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deal_id     INT UNSIGNED NOT NULL,
  from_stage  VARCHAR(16) NULL,
  to_stage    VARCHAR(16) NOT NULL,
  user_id     INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_dsl_deal (deal_id),
  CONSTRAINT fk_dsl_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Каждое касание: заявка с сайта, /start в боте, переход с сайта в бот.
-- Первое касание контакта = самая ранняя строка по contact_id.
CREATE TABLE IF NOT EXISTS sources (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id     INT UNSIGNED NOT NULL,
  deal_id        INT UNSIGNED NULL,
  channel        VARCHAR(16) NOT NULL,                         -- site_form | bot | site_bot
  utm_source     VARCHAR(100) NULL,
  utm_medium     VARCHAR(100) NULL,
  utm_campaign   VARCHAR(100) NULL,
  utm_content    VARCHAR(100) NULL,
  utm_term       VARCHAR(100) NULL,
  referrer       VARCHAR(255) NULL,
  landing_page   VARCHAR(255) NULL,
  bot_start_tag  VARCHAR(64) NULL,
  created_at     DATETIME NOT NULL,
  KEY idx_sources_contact (contact_id, created_at),
  KEY idx_sources_deal (deal_id),
  CONSTRAINT fk_sources_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
  CONSTRAINT fk_sources_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deal_id        INT UNSIGNED NOT NULL,
  direction      VARCHAR(4) NOT NULL,                          -- in | out | note
  channel        VARCHAR(8) NOT NULL,                          -- site | bot | crm
  author         VARCHAR(8) NOT NULL,                          -- client | bot | manager | system
  user_id        INT UNSIGNED NULL,
  text           TEXT NOT NULL,
  tg_message_id  BIGINT NULL,
  delivery       VARCHAR(8) NOT NULL DEFAULT 'none',           -- none | queued | sent | failed
  created_at     DATETIME NOT NULL,
  KEY idx_messages_deal (deal_id, id),
  CONSTRAINT fk_messages_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  deal_id      INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,
  kind         VARCHAR(16) NOT NULL DEFAULT 'manual',          -- manual | callback | delete_request | question
  title        VARCHAR(255) NOT NULL,
  due_at       DATETIME NOT NULL,
  done_at      DATETIME NULL,
  done_by      INT UNSIGNED NULL,
  notified_at  DATETIME NULL,
  created_at   DATETIME NOT NULL,
  KEY idx_tasks_user (user_id, done_at, due_at),
  KEY idx_tasks_deal (deal_id),
  CONSTRAINT fk_tasks_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kb_articles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(150) NOT NULL,
  button_label  VARCHAR(40) NULL,
  keywords      VARCHAR(1000) NOT NULL DEFAULT '',
  answer        TEXT NOT NULL,
  sort          INT NOT NULL DEFAULT 100,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Очередь исходящих в Telegram: уведомления менеджерам и ответы клиентам.
CREATE TABLE IF NOT EXISTS outbox (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind         VARCHAR(24) NOT NULL,
  chat_id      BIGINT NOT NULL,
  payload      TEXT NOT NULL,                                  -- JSON для sendMessage
  message_id   INT UNSIGNED NULL,                              -- messages.id, чтобы обновить статус доставки
  attempts     INT NOT NULL DEFAULT 0,
  next_try_at  DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  failed_at    DATETIME NULL,
  last_error   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL,
  KEY idx_outbox_pending (sent_at, failed_at, next_try_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tg_updates (
  update_id    BIGINT NOT NULL PRIMARY KEY,
  received_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_sessions (
  telegram_id  BIGINT NOT NULL PRIMARY KEY,
  state        VARCHAR(32) NOT NULL DEFAULT '',
  data         TEXT NULL,
  updated_at   DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key        VARCHAR(80) NOT NULL,
  window_start  INT UNSIGNED NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rl_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  entity      VARCHAR(16) NOT NULL,                            -- deal | contact | user | kb | settings
  entity_id   INT UNSIGNED NULL,
  action      VARCHAR(32) NOT NULL,
  details     TEXT NULL,                                       -- JSON без персональных данных
  created_at  DATETIME NOT NULL,
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(64) NOT NULL PRIMARY KEY,
  value  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
