-- ARISE + DataPost Database Schema v1.2
-- SQLite3

-- ============================================
-- CONTENT TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    description TEXT,
    icon TEXT DEFAULT '📚',
    sort_order INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lessons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    content TEXT,
    lesson_type TEXT DEFAULT 'text',
    file_path TEXT,
    file_name TEXT,
    file_size_kb REAL,
    sort_order INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id)
);

CREATE TABLE IF NOT EXISTS quiz_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    question_type TEXT DEFAULT 'mcq',
    question TEXT NOT NULL,
    option_a TEXT,
    option_b TEXT,
    option_c TEXT,
    option_d TEXT,
    correct_option TEXT,
    explanation TEXT,
    essay_hint TEXT,
    min_words INTEGER DEFAULT 0,
    max_marks INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0,
    FOREIGN KEY (module_id) REFERENCES modules(id)
);

CREATE TABLE IF NOT EXISTS essay_responses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER,
    session_hash TEXT NOT NULL,
    question_id INTEGER NOT NULL,
    module_id INTEGER NOT NULL,
    response_text TEXT NOT NULL,
    word_count INTEGER DEFAULT 0,
    is_graded INTEGER DEFAULT 0,
    grade INTEGER,
    feedback TEXT,
    graded_by INTEGER,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    graded_at DATETIME,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id),
    FOREIGN KEY (module_id) REFERENCES modules(id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

-- ============================================
-- STUDENTS TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL,
    school_name TEXT,
    class_name TEXT,
    session_hash TEXT,
    is_active INTEGER DEFAULT 1,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- USAGE TRACKING TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT UNIQUE NOT NULL,
    student_id INTEGER,
    device_hash TEXT NOT NULL,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
    language TEXT DEFAULT 'en',
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS page_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    page_type TEXT NOT NULL,
    page_slug TEXT,
    module_id INTEGER,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    module_id INTEGER NOT NULL,
    score INTEGER NOT NULL,
    total_questions INTEGER NOT NULL,
    percentage REAL NOT NULL,
    completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS anonymous_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question TEXT NOT NULL,
    module_id INTEGER,
    is_answered INTEGER DEFAULT 0,
    answer TEXT,
    answered_by INTEGER,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    answered_at DATETIME
);

CREATE TABLE IF NOT EXISTS daily_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stat_date DATE UNIQUE NOT NULL,
    unique_devices INTEGER DEFAULT 0,
    total_sessions INTEGER DEFAULT 0,
    total_page_views INTEGER DEFAULT 0,
    total_quiz_attempts INTEGER DEFAULT 0,
    total_questions_asked INTEGER DEFAULT 0
);

-- ============================================
-- ADMIN & PERMISSIONS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    full_name TEXT,
    password_hash TEXT NOT NULL,
    role TEXT DEFAULT 'teacher',
    is_active INTEGER DEFAULT 1,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS admin_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    permission TEXT NOT NULL,
    UNIQUE(user_id, permission),
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
);

-- Available permissions:
-- dashboard        = View dashboard stats
-- content_view     = View modules/lessons
-- content_manage   = Create/edit/delete modules, lessons, quiz questions
-- questions_view   = View anonymous questions
-- questions_answer = Answer anonymous questions
-- essays_grade     = Grade essay responses
-- students_view    = View student list
-- students_manage  = Add/edit/delete students
-- users_manage     = Create/edit/delete admin users
-- setup            = Access school setup
-- datapost         = Access DataPost
-- backup           = Download/manage backups

-- ============================================
-- BACKUP LOG TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS backup_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_type TEXT NOT NULL,
    filename TEXT NOT NULL,
    file_size_kb REAL,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- DATAPOST TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS datapost_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    school_id TEXT UNIQUE NOT NULL,
    school_name TEXT NOT NULL,
    county TEXT,
    sub_county TEXT,
    contact_person TEXT,
    contact_phone TEXT,
    auto_backup_enabled INTEGER DEFAULT 1,
    auto_backup_path TEXT DEFAULT '/var/www/arise/data/backups/',
    setup_date DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS datapost_pickups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    courier_email TEXT NOT NULL,
    courier_name TEXT,
    pickup_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_from DATE NOT NULL,
    data_to DATE NOT NULL,
    bundle_size_kb INTEGER,
    bundle_hash TEXT
);

CREATE TABLE IF NOT EXISTS datapost_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    courier_email TEXT NOT NULL,
    courier_name TEXT,
    delivery_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    package_name TEXT,
    package_size_kb INTEGER,
    notes TEXT
);

-- ============================================
-- CERTIFICATES TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS certificates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cert_number TEXT UNIQUE NOT NULL,
    student_id INTEGER,
    student_name TEXT NOT NULL,
    module_id INTEGER NOT NULL,
    module_title TEXT NOT NULL,
    score INTEGER NOT NULL,
    percentage REAL NOT NULL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (module_id) REFERENCES modules(id)
);

-- ============================================
-- DEFAULT DATA
-- ============================================

INSERT OR IGNORE INTO modules (title, slug, description, icon, sort_order) VALUES
('Biblical Foundation', 'biblical-foundation', 'Sex and sexuality from a biblical perspective', '✝️', 1),
('Adolescent Growth', 'adolescent-growth', 'Physical, social, and emotional changes during puberty', '🌱', 2),
('Menstrual Health', 'menstrual-health', 'Understanding menstruation and wet dreams', '🩸', 3),
('Abstinence', 'abstinence', 'Preventing early pregnancy through abstinence', '🛡️', 4),
('Gender-Based Violence', 'gender-violence', 'Understanding and preventing GBV', '⚖️', 5),
('Healthy Relationships', 'healthy-relationships', 'Building and maintaining healthy relationships', '🤝', 6),
('HIV/AIDS and STIs', 'hiv-aids-stis', 'Prevention, treatment, and support', '🩺', 7),
('Mental Health & Drugs', 'mental-health-drugs', 'Stress management and substance abuse prevention', '🧠', 8),
('Life Skills', 'life-skills', 'Decision making, communication, and career planning', '💪', 9),
('Social Media & SRH', 'social-media-srh', 'Online safety and digital wellness', '📱', 10),
('Digital Solutions', 'digital-solutions', 'Using the ARISE platform effectively', '💻', 11);

-- Default superadmin (password: arise2026 — change after setup!)
INSERT OR IGNORE INTO admin_users (username, full_name, password_hash, role) VALUES
('admin', 'Super Admin', '$2y$10$default_change_me', 'superadmin');

-- Give superadmin all permissions
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'dashboard');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'content_view');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'content_manage');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'questions_view');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'questions_answer');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'essays_grade');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'students_view');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'students_manage');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'users_manage');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'setup');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'datapost');
INSERT OR IGNORE INTO admin_permissions (user_id, permission) VALUES (1, 'backup');

-- ============================================
-- LESSON PROGRESS TABLE (for interactive lessons)
-- ============================================

CREATE TABLE IF NOT EXISTS lesson_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    lesson_id INTEGER NOT NULL,
    last_slide INTEGER DEFAULT 0,
    completed INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(session_hash, lesson_id),
    FOREIGN KEY (lesson_id) REFERENCES lessons(id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

-- ============================================
-- PUBLIC FORUM TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS forum_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT NULL,
    student_id INTEGER,
    student_name TEXT NOT NULL,
    module_id INTEGER,
    title TEXT,
    body TEXT NOT NULL,
    is_pinned INTEGER DEFAULT 0,
    is_hidden INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES forum_posts(id),
    FOREIGN KEY (module_id) REFERENCES modules(id)
);

-- ============================================
-- SCHOOLS & CLASSES (structured enrollment)
-- ============================================

CREATE TABLE IF NOT EXISTS schools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    county TEXT,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    school_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    level TEXT,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id)
);

-- ============================================
-- GAMIFICATION TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS student_xp (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    xp_points INTEGER DEFAULT 0,
    level INTEGER DEFAULT 1,
    streak_days INTEGER DEFAULT 0,
    last_activity DATE,
    total_lessons_completed INTEGER DEFAULT 0,
    total_quizzes_passed INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(student_id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    icon TEXT DEFAULT '🏅',
    xp_reward INTEGER DEFAULT 50,
    condition_type TEXT,
    condition_value INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS student_badges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    badge_id INTEGER NOT NULL,
    earned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(student_id, badge_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (badge_id) REFERENCES badges(id)
);

CREATE TABLE IF NOT EXISTS xp_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    xp_earned INTEGER NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE IF NOT EXISTS lesson_interactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    module_id INTEGER NOT NULL,
    lesson_slug TEXT,
    interaction_type TEXT NOT NULL,
    score INTEGER DEFAULT 0,
    total INTEGER DEFAULT 0,
    done INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_li_session_module ON lesson_interactions(session_hash, module_id, interaction_type);

-- Default badges
INSERT OR IGNORE INTO badges (code,name,description,icon,xp_reward,condition_type,condition_value) VALUES
('first_login',   'Welcome!',         'Registered on ARISE',                    '🌟', 50,  'lessons_done', 0),
('first_lesson',  'First Step',       'Completed your first lesson',            '👣', 100, 'lessons_done', 1),
('first_pass',    'Quiz Warrior',     'Passed your first quiz',                 '⚔️', 150, 'quizzes_passed', 1),
('first_cert',    'Certified!',       'Earned your first certificate',          '🎓', 200, 'certs', 1),
('streak_3',      '3-Day Streak',     'Studied 3 days in a row',               '🔥', 100, 'streak', 3),
('streak_7',      'Week Warrior',     'Studied 7 days in a row',               '💪', 300, 'streak', 7),
('lessons_5',     'Curious Mind',     'Completed 5 lessons',                   '🧠', 200, 'lessons_done', 5),
('lessons_10',    'Knowledge Seeker', 'Completed 10 lessons',                  '📚', 400, 'lessons_done', 10),
('quizzes_5',     'Quiz Master',      'Passed 5 quizzes',                      '🏆', 300, 'quizzes_passed', 5),
('perfect_score', 'Perfectionist',    'Scored 90%+ on a quiz',                 '💯', 500, 'score_90', 1),
('all_modules',   'Champion',         'Completed all available modules',        '👑', 1000,'all_modules', 1),
('forum_post',    'Community Voice',  'Made your first forum post',            '💬', 75,  'forum_posts', 1),
('asked_q',       'Curious Cat',      'Asked an anonymous question',            '🐱', 75,  'questions', 1);
