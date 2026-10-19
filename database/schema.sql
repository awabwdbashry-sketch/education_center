-- =====================================================================
-- نظام إدارة السنتر التعليمي — مخطط قاعدة البيانات الكامل
-- قاعدة البيانات: education_center_management  |  utf8mb4  |  InnoDB
-- متوافق مع MySQL 8.x و MariaDB 10.4+  (WAMP)
-- يمكن استيراده عبر phpMyAdmin، أو تنفيذه تلقائياً من setup/index.php
-- لا يحتوي على طلاب أو مدفوعات أو كلمات مرور. المدير الأول يُنشأ من صفحة الإعداد.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `education_center_management`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `education_center_management`;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- الإعدادات والتسلسلات
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عدّادات الأرقام المتسلسلة (رقم الطالب، الإيصال، الفاتورة) — تُقفل داخل المعاملات
CREATE TABLE IF NOT EXISTS sequences (
  name       VARCHAR(50) NOT NULL PRIMARY KEY,
  `last_value` BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- الصلاحيات والأدوار والمستخدمون
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(60)  NOT NULL,
  label      VARCHAR(150) NOT NULL,
  group_name VARCHAR(60)  NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60)  NOT NULL,
  label       VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المواد والمراحل والمدرسون
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academic_levels (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  stage      VARCHAR(60)  NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_levels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  code       VARCHAR(30)  NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subjects_name (name),
  UNIQUE KEY uq_subjects_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teachers (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(150) NOT NULL,
  phone          VARCHAR(30)  NULL,
  email          VARCHAR(150) NULL,
  address        VARCHAR(255) NULL,
  specialization VARCHAR(150) NULL,
  hire_date      DATE NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  comp_type      ENUM('none','fixed_monthly','per_session') NOT NULL DEFAULT 'none',
  comp_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes          TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_teachers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  teacher_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, subject_id),
  CONSTRAINT fk_ts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  teacher_id    INT UNSIGNED NULL,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  CONSTRAINT fk_users_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(60) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_user (username, attempted_at),
  KEY idx_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- الطلاب وأولياء الأمور
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_no    VARCHAR(20)  NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  phone         VARCHAR(30)  NULL,
  address       VARCHAR(255) NULL,
  birth_date    DATE NULL,
  level_id      INT UNSIGNED NULL,
  photo_path    VARCHAR(255) NULL,
  status        ENUM('active','suspended','withdrawn') NOT NULL DEFAULT 'active',
  registered_at DATE NOT NULL,
  notes         TEXT NULL,
  qr_token      CHAR(48) NOT NULL,
  qr_status     ENUM('active','revoked') NOT NULL DEFAULT 'active',
  qr_issued_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_no (student_no),
  UNIQUE KEY uq_students_qr (qr_token),
  KEY idx_students_name (full_name),
  KEY idx_students_phone (phone),
  KEY idx_students_status (status),
  CONSTRAINT fk_students_level FOREIGN KEY (level_id) REFERENCES academic_levels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guardians (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name  VARCHAR(150) NOT NULL,
  phone      VARCHAR(30)  NULL,
  relation   VARCHAR(50)  NULL,
  notes      VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_guardians_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_guardians (
  student_id  INT UNSIGNED NOT NULL,
  guardian_id INT UNSIGNED NOT NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (student_id, guardian_id),
  CONSTRAINT fk_sg_student  FOREIGN KEY (student_id)  REFERENCES students(id)  ON DELETE CASCADE,
  CONSTRAINT fk_sg_guardian FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communication_notes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id   INT UNSIGNED NOT NULL,
  guardian_id  INT UNSIGNED NULL,
  channel      ENUM('whatsapp','phone','in_person','other') NOT NULL DEFAULT 'whatsapp',
  template_key VARCHAR(50) NULL,
  message      TEXT NOT NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cn_student (student_id, created_at),
  CONSTRAINT fk_cn_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المجموعات والجداول والحصص
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS class_groups (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(30)  NOT NULL,
  name         VARCHAR(150) NOT NULL,
  level_id     INT UNSIGNED NULL,
  subject_id   INT UNSIGNED NOT NULL,
  teacher_id   INT UNSIGNED NOT NULL,
  room         VARCHAR(100) NULL,
  max_students SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  start_date   DATE NULL,
  end_date     DATE NULL,
  status       ENUM('active','paused','finished') NOT NULL DEFAULT 'active',
  notes        TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_groups_code (code),
  KEY idx_groups_teacher (teacher_id),
  KEY idx_groups_status (status),
  CONSTRAINT fk_groups_level   FOREIGN KEY (level_id)   REFERENCES academic_levels(id) ON DELETE SET NULL,
  CONSTRAINT fk_groups_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_groups_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_students (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id   INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status     ENUM('active','left') NOT NULL DEFAULT 'active',
  joined_at  DATE NOT NULL,
  left_at    DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gs_group_student (group_id, student_id),
  KEY idx_gs_student (student_id, status),
  CONSTRAINT fk_gs_group   FOREIGN KEY (group_id)   REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_gs_student FOREIGN KEY (student_id) REFERENCES students(id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نظام الدفع الخاص بكل مجموعة (شهري ثابت أو حسب الحصص)
CREATE TABLE IF NOT EXISTS group_payment_settings (
  group_id            INT UNSIGNED NOT NULL PRIMARY KEY,
  mode                ENUM('monthly','per_session') NOT NULL DEFAULT 'monthly',
  monthly_fee         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  session_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  due_days            SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  settlement_sessions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  allow_advance       TINYINT(1) NOT NULL DEFAULT 1,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gps_group FOREIGN KEY (group_id) REFERENCES class_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الجدول الأسبوعي: weekday بنظام ISO (1=الاثنين ... 7=الأحد)
CREATE TABLE IF NOT EXISTS schedules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id   INT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  room       VARCHAR(100) NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_schedule_slot (group_id, weekday, start_time),
  CONSTRAINT fk_sched_group FOREIGN KEY (group_id) REFERENCES class_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الحصص الفعلية: لا يُنشأ حضور تلقائي لأي حصة
CREATE TABLE IF NOT EXISTS class_sessions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id      INT UNSIGNED NOT NULL,
  schedule_id   INT UNSIGNED NULL,
  session_date  DATE NOT NULL,
  start_time    TIME NOT NULL,
  end_time      TIME NOT NULL,
  teacher_id    INT UNSIGNED NOT NULL,
  room          VARCHAR(100) NULL,
  status        ENUM('scheduled','held','cancelled') NOT NULL DEFAULT 'scheduled',
  cancel_reason VARCHAR(255) NULL,
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session_slot (group_id, session_date, start_time),
  KEY idx_sessions_date (session_date, status),
  KEY idx_sessions_teacher (teacher_id, session_date),
  CONSTRAINT fk_sess_group    FOREIGN KEY (group_id)    REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sess_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id)    ON DELETE SET NULL,
  CONSTRAINT fk_sess_teacher  FOREIGN KEY (teacher_id)  REFERENCES teachers(id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status     ENUM('present','absent','late','excused') NOT NULL,
  method     ENUM('manual','qr') NOT NULL DEFAULT 'manual',
  marked_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  marked_by  INT UNSIGNED NULL,
  notes      VARCHAR(255) NULL,
  UNIQUE KEY uq_attendance (session_id, student_id),
  KEY idx_att_student (student_id, marked_at),
  CONSTRAINT fk_att_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students(id)       ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- الاشتراكات والفوترة والدفعات
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NOT NULL,
  group_id        INT UNSIGNED NOT NULL,
  mode            ENUM('monthly','per_session') NOT NULL,
  unit_price      DECIMAL(12,2) NOT NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  start_date      DATE NOT NULL,
  end_date        DATE NULL,
  status          ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sub_student (student_id, status),
  KEY idx_sub_group (group_id, status),
  CONSTRAINT fk_sub_student FOREIGN KEY (student_id) REFERENCES students(id)     ON DELETE RESTRICT,
  CONSTRAINT fk_sub_group   FOREIGN KEY (group_id)   REFERENCES class_groups(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الفترات الشهرية التي جرى توليد استحقاقاتها (YYYY-MM)
CREATE TABLE IF NOT EXISTS billing_periods (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  period_ym    CHAR(7) NOT NULL,
  period_start DATE NOT NULL,
  period_end   DATE NOT NULL,
  generated_by INT UNSIGNED NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_billing_period (period_ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- monthly_key فريد: يمنع تكرار استحقاق الاشتراك الشهري لنفس الفترة عند إعادة التشغيل
CREATE TABLE IF NOT EXISTS invoices (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_no        VARCHAR(30) NOT NULL,
  student_id        INT UNSIGNED NOT NULL,
  subscription_id   INT UNSIGNED NULL,
  group_id          INT UNSIGNED NULL,
  billing_period_id INT UNSIGNED NULL,
  kind              ENUM('monthly','sessions','manual') NOT NULL,
  monthly_key       VARCHAR(60) NULL,
  issue_date        DATE NOT NULL,
  due_date          DATE NOT NULL,
  total             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status            ENUM('open','partial','paid','void') NOT NULL DEFAULT 'open',
  notes             VARCHAR(255) NULL,
  void_reason       VARCHAR(255) NULL,
  voided_by         INT UNSIGNED NULL,
  voided_at         DATETIME NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_no (invoice_no),
  UNIQUE KEY uq_invoice_monthly_key (monthly_key),
  KEY idx_inv_student (student_id, status),
  KEY idx_inv_due (status, due_date),
  KEY idx_inv_group (group_id),
  CONSTRAINT fk_inv_student FOREIGN KEY (student_id)        REFERENCES students(id)        ON DELETE RESTRICT,
  CONSTRAINT fk_inv_sub     FOREIGN KEY (subscription_id)   REFERENCES subscriptions(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_inv_group   FOREIGN KEY (group_id)          REFERENCES class_groups(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_inv_period  FOREIGN KEY (billing_period_id) REFERENCES billing_periods(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- attendance_id فريد: لا تُحتسب حصة حضور مرتين في فاتورتين
CREATE TABLE IF NOT EXISTS invoice_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id    INT UNSIGNED NOT NULL,
  item_type     ENUM('charge','adjustment') NOT NULL DEFAULT 'charge',
  description   VARCHAR(255) NOT NULL,
  quantity      DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  unit_price    DECIMAL(12,2) NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  attendance_id BIGINT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_item_attendance (attendance_id),
  KEY idx_items_invoice (invoice_id),
  CONSTRAINT fk_items_invoice    FOREIGN KEY (invoice_id)    REFERENCES invoices(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_items_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الدفعة المعتمدة لا تُحذف: تُلغى بسبب موثق (status = voided)
CREATE TABLE IF NOT EXISTS payments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  method          VARCHAR(30) NOT NULL,
  reference       VARCHAR(120) NULL,
  paid_at         DATETIME NOT NULL,
  received_by     INT UNSIGNED NULL,
  status          ENUM('valid','voided') NOT NULL DEFAULT 'valid',
  notes           VARCHAR(255) NULL,
  void_reason     VARCHAR(255) NULL,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME NULL,
  idempotency_key CHAR(40) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_idem (idempotency_key),
  KEY idx_pay_student (student_id, status),
  KEY idx_pay_date (paid_at, status),
  CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT chk_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_allocations (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  amount     DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alloc (payment_id, invoice_id),
  KEY idx_alloc_invoice (invoice_id),
  CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT chk_alloc_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_no  VARCHAR(30) NOT NULL,
  payment_id  INT UNSIGNED NOT NULL,
  issued_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by   INT UNSIGNED NULL,
  print_count INT UNSIGNED NOT NULL DEFAULT 0,
  status      ENUM('valid','void') NOT NULL DEFAULT 'valid',
  UNIQUE KEY uq_receipt_no (receipt_no),
  UNIQUE KEY uq_receipt_payment (payment_id),
  CONSTRAINT fk_receipt_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المصروفات ومستحقات المدرسين
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expense_categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  is_system  TINYINT(1) NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_expcat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id     INT UNSIGNED NOT NULL,
  expense_date    DATE NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  description     VARCHAR(255) NOT NULL,
  payee           VARCHAR(150) NULL,
  attachment_path VARCHAR(255) NULL,
  recorded_by     INT UNSIGNED NULL,
  status          ENUM('valid','voided') NOT NULL DEFAULT 'valid',
  void_reason     VARCHAR(255) NULL,
  voided_by       INT UNSIGNED NULL,
  voided_at       DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_exp_date (expense_date, status),
  CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT chk_exp_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- المستحق (accrual) للمدرس؛ ليس مصروفاً إلا عند الصرف الفعلي (teacher_payouts → expenses)
CREATE TABLE IF NOT EXISTS teacher_compensations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT UNSIGNED NOT NULL,
  period_ym   CHAR(7) NOT NULL,
  kind        ENUM('fixed','session','adjustment') NOT NULL,
  description VARCHAR(255) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  session_id  INT UNSIGNED NULL,
  accrual_key VARCHAR(60) NULL,
  status      ENUM('valid','voided') NOT NULL DEFAULT 'valid',
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comp_session (session_id),
  UNIQUE KEY uq_comp_key (accrual_key),
  KEY idx_comp_teacher (teacher_id, period_ym),
  CONSTRAINT fk_comp_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id)       ON DELETE RESTRICT,
  CONSTRAINT fk_comp_session FOREIGN KEY (session_id) REFERENCES class_sessions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الصرف للمدرس يُنشئ سجل مصروف واحداً مرتبطاً (expense_id) فلا يتكرر القيد
CREATE TABLE IF NOT EXISTS teacher_payouts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_id  INT UNSIGNED NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  method      VARCHAR(30) NOT NULL,
  reference   VARCHAR(120) NULL,
  paid_date   DATE NOT NULL,
  notes       VARCHAR(255) NULL,
  paid_by     INT UNSIGNED NULL,
  expense_id  INT UNSIGNED NULL,
  status      ENUM('valid','voided') NOT NULL DEFAULT 'valid',
  void_reason VARCHAR(255) NULL,
  voided_by   INT UNSIGNED NULL,
  voided_at   DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payout_expense (expense_id),
  KEY idx_payout_teacher (teacher_id, status),
  CONSTRAINT fk_payout_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payout_expense FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_payout_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- الاختبارات والنتائج
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exams (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(150) NOT NULL,
  group_id    INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  exam_date   DATE NOT NULL,
  max_score   DECIMAL(7,2) NOT NULL,
  notes       VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exams_group (group_id, exam_date),
  CONSTRAINT fk_exams_group   FOREIGN KEY (group_id)   REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exams_subject FOREIGN KEY (subject_id) REFERENCES subjects(id)     ON DELETE RESTRICT,
  CONSTRAINT chk_exams_max CHECK (max_score > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_results (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  exam_id    INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  score      DECIMAL(7,2) NOT NULL,
  notes      VARCHAR(255) NULL,
  entered_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_result (exam_id, student_id),
  CONSTRAINT fk_er_exam    FOREIGN KEY (exam_id)    REFERENCES exams(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_er_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- التنبيهات وسجلات التدقيق والنسخ الاحتياطي
-- ---------------------------------------------------------------------
-- dedupe_key فريد: لا يتكرر التنبيه نفسه لنفس الحدث
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type        VARCHAR(40) NOT NULL,
  severity    ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(500) NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  link        VARCHAR(255) NULL,
  dedupe_key  VARCHAR(120) NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  read_at     DATETIME NULL,
  read_by     INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notif_key (dedupe_key),
  KEY idx_notif_state (is_read, resolved_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  username    VARCHAR(60) NULL,
  action      VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  details     TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('backup','restore') NOT NULL DEFAULT 'backup',
  filename   VARCHAR(200) NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256     CHAR(64) NULL,
  status     ENUM('success','failed') NOT NULL,
  message    VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_backup_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- البيانات الابتدائية الضرورية فقط (صلاحيات، أدوار، إعدادات افتراضية)
-- =====================================================================
INSERT IGNORE INTO permissions (code, label, group_name, sort_order) VALUES
('dashboard.view',      'عرض لوحة التحكم',                       'عام', 1),
('dashboard.finance',   'عرض الأرقام المالية في لوحة التحكم',    'عام', 2),
('groups.view_all',     'الوصول إلى كل المجموعات (بدلاً من مجموعات المدرس فقط)', 'عام', 3),
('students.view',       'عرض الطلاب',                            'الطلاب', 10),
('students.manage',     'إضافة وتعديل الطلاب وإسنادهم للمجموعات','الطلاب', 11),
('students.qr',         'إيقاف وإعادة إصدار QR الطالب',           'الطلاب', 12),
('teachers.view',       'عرض المدرسين',                          'المدرسون', 20),
('teachers.manage',     'إضافة وتعديل المدرسين',                 'المدرسون', 21),
('academics.view',      'عرض المواد والمجموعات والجداول',         'الأكاديمي', 30),
('academics.manage',    'إدارة المواد والمراحل والمجموعات والجداول','الأكاديمي', 31),
('sessions.manage',     'إنشاء وتعديل وإلغاء الحصص',             'الأكاديمي', 32),
('attendance.view',     'عرض الحضور',                            'الحضور', 40),
('attendance.mark',     'تسجيل الحضور (يدوي وQR)',               'الحضور', 41),
('attendance.edit',     'تعديل سجل حضور مسجّل',                  'الحضور', 42),
('billing.view',        'عرض الاشتراكات والفواتير',              'المالية', 50),
('billing.manage',      'إدارة الاشتراكات وتوليد الاستحقاقات',   'المالية', 51),
('invoices.adjust',     'تعديلات الفواتير (خصم/تسوية) وإلغاؤها', 'المالية', 52),
('payments.collect',    'تسجيل الدفعات وطباعة الإيصالات',        'المالية', 53),
('payments.void',       'إلغاء الدفعات المعتمدة',                'المالية', 54),
('expenses.view',       'عرض المصروفات',                         'المالية', 55),
('expenses.manage',     'تسجيل المصروفات',                       'المالية', 56),
('expenses.void',       'إلغاء المصروفات',                       'المالية', 57),
('teacher_pay.view',    'عرض مستحقات المدرسين',                  'المالية', 58),
('teacher_pay.manage',  'احتساب المستحقات وصرفها للمدرسين',      'المالية', 59),
('reports.view',        'عرض التقارير العامة',                   'التقارير', 60),
('reports.finance',     'عرض التقارير المالية',                  'التقارير', 61),
('reports.export',      'تصدير التقارير إلى CSV',                'التقارير', 62),
('exams.view',          'عرض الاختبارات والنتائج',               'الاختبارات', 70),
('exams.manage',        'إدارة الاختبارات وتسجيل الدرجات',       'الاختبارات', 71),
('comms.view',          'التواصل مع أولياء الأمور',              'التواصل', 80),
('notifications.view',  'عرض التنبيهات',                         'التنبيهات', 85),
('users.manage',        'إدارة المستخدمين والأدوار والصلاحيات',  'الإدارة', 90),
('settings.manage',     'إدارة إعدادات السنتر',                  'الإدارة', 91),
('backup.manage',       'النسخ الاحتياطي والاستعادة',            'الإدارة', 92),
('audit.view',          'عرض سجل العمليات',                      'الإدارة', 93);

INSERT IGNORE INTO roles (name, label, description, is_system) VALUES
('admin',      'مدير النظام',               'كامل الصلاحيات', 1),
('reception',  'موظف استقبال وتسجيل طلاب',  'تسجيل الطلاب وإسنادهم والحضور والتواصل', 1),
('cashier',    'محصل مالي',                 'التحصيل والإيصالات والاشتراكات', 1),
('teacher',    'مدرس',                      'مجموعاته وحضورها واختباراتها فقط', 1),
('supervisor', 'مشرف / مسؤول حضور',         'الحضور والحصص وQR', 1);

-- المدير: كل الصلاحيات
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.name = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'reception' AND p.code IN
('dashboard.view','groups.view_all','students.view','students.manage','teachers.view','academics.view',
 'attendance.view','attendance.mark','billing.view','comms.view','notifications.view','exams.view','reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'cashier' AND p.code IN
('dashboard.view','dashboard.finance','groups.view_all','students.view','academics.view','billing.view','billing.manage',
 'payments.collect','expenses.view','reports.view','reports.finance','reports.export','comms.view','notifications.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'teacher' AND p.code IN
('dashboard.view','students.view','academics.view','attendance.view','attendance.mark','exams.view','exams.manage','reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'supervisor' AND p.code IN
('dashboard.view','groups.view_all','students.view','students.qr','academics.view','sessions.manage',
 'attendance.view','attendance.mark','attendance.edit','notifications.view','reports.view');

INSERT IGNORE INTO sequences (name, `last_value`) VALUES ('student', 0), ('receipt', 0), ('invoice', 0);

INSERT IGNORE INTO expense_categories (name, is_system) VALUES
('مستحقات المدرسين', 1),
('إيجار', 0), ('كهرباء ومياه وإنترنت', 0), ('مستلزمات ومطبوعات', 0),
('رواتب إدارية', 0), ('صيانة', 0), ('دعاية وتسويق', 0), ('مصروفات أخرى', 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('center_name',            'السنتر التعليمي'),
('center_logo',            ''),
('center_phone',           ''),
('center_email',           ''),
('center_address',         ''),
('currency',               'جنيه'),
('phone_country_code',     '249'),
('default_due_days',       '7'),
('subscription_alert_days','7'),
('absence_alert_count',    '3'),
('backup_alert_days',      '7'),
('charge_late_sessions',   '1'),
('allow_advance_payments', '1'),
('exams_enabled',          '0'),
('session_timeout_minutes','120'),
('receipt_footer',         'شكراً لتعاملكم معنا'),
('payment_methods',        '[{"code":"cash","label":"نقداً","enabled":true,"requires_ref":false},{"code":"bank_transfer","label":"تحويل بنكي","enabled":true,"requires_ref":true}]'),
('msg_tpl_subscription',   'السلام عليكم {guardian}، نود تذكيركم بأن اشتراك الطالب/ة {student} في {group} يستحق السداد، والمبلغ المتبقي {amount}. شاكرين تعاونكم — {center}'),
('msg_tpl_dues',           'السلام عليكم {guardian}، نفيدكم بأن المتبقي على الطالب/ة {student} هو {amount}. نرجو التكرم بالسداد في أقرب وقت. — {center}'),
('msg_tpl_absence',        'السلام عليكم {guardian}، نود إبلاغكم بغياب الطالب/ة {student} عن حصة {group} بتاريخ {date}. نأمل الاطمئنان عليه. — {center}');
