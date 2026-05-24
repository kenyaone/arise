<?php
set_time_limit(120);
$target = '/home/cpmsfdav/public_html/data/arise.db';
$source = '/home/cpmsfdav/public_html/arise/data/arise.db';

$db = new SQLite3($target);
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec('PRAGMA foreign_keys=OFF;');

echo "<pre>\n";

// Attach source DB
$db->exec("ATTACH DATABASE '$source' AS src");

// ── Ensure tables/columns exist in target ────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS clusters (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)");
try { $db->exec("ALTER TABLE clusters ADD COLUMN password_hash TEXT DEFAULT ''"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE clusters ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE schools ADD COLUMN cluster_id INTEGER REFERENCES clusters(id)"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE lessons ADD COLUMN is_published INTEGER DEFAULT 1"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE quiz_questions ADD COLUMN is_published INTEGER DEFAULT 1"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE quiz_questions ADD COLUMN section TEXT DEFAULT 'lesson'"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE quiz_questions ADD COLUMN competency TEXT DEFAULT ''"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE quiz_questions ADD COLUMN difficulty TEXT DEFAULT 'MEDIUM'"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE quiz_questions ADD COLUMN option_e TEXT DEFAULT ''"); } catch(Exception $e){}
$db->exec("CREATE TABLE IF NOT EXISTS module_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module_id INTEGER NOT NULL,
    session_hash TEXT NOT NULL,
    student_id INTEGER,
    rating INTEGER NOT NULL,
    most_useful TEXT,
    unclear TEXT,
    would_recommend INTEGER DEFAULT 1,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
try { $db->exec("CREATE UNIQUE INDEX uniq_feedback ON module_feedback(module_id,session_hash)"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE modules ADD COLUMN require_pretest INTEGER DEFAULT 1"); } catch(Exception $e){}
try { $db->exec("ALTER TABLE modules ADD COLUMN require_posttest INTEGER DEFAULT 1"); } catch(Exception $e){}

// ── Step 1: Remove old test/wrong modules ─────────────────────────
$db->exec("DELETE FROM lessons WHERE module_id IN (12,13,14,15,16)");
$db->exec("DELETE FROM modules WHERE id IN (12,13,14,15,16)");
$db->exec("DELETE FROM lessons WHERE module_id=21");
$db->exec("DELETE FROM modules WHERE id=21");
echo "Step 1: Removed old test modules ✓\n";

// ── Step 2: Sync modules ──────────────────────────────────────────
$db->exec("INSERT OR REPLACE INTO modules (id,title,slug,icon,description,sort_order,is_active)
    SELECT id,title,slug,COALESCE(icon,'📚'),COALESCE(description,''),sort_order,is_active
    FROM src.modules");
$mc = $db->querySingle("SELECT COUNT(*) FROM modules");
echo "Step 2: Modules synced → $mc total ✓\n";

// ── Step 3: Sync lessons ──────────────────────────────────────────
// Target lessons may not have is_published — omit it, just use is_active
$db->exec("INSERT OR IGNORE INTO lessons (id,module_id,title,slug,lesson_type,file_path,sort_order,is_active)
    SELECT id,module_id,title,slug,lesson_type,COALESCE(file_path,''),sort_order,is_active
    FROM src.lessons WHERE is_active=1");
$lc = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1");
echo "Step 3: Lessons synced → $lc active ✓\n";

// ── Step 4: Sync clusters ─────────────────────────────────────────
$db->exec("INSERT OR IGNORE INTO clusters (id,name,password_hash)
    SELECT id,name,'' FROM src.clusters");
$cc = $db->querySingle("SELECT COUNT(*) FROM clusters");
echo "Step 4: Clusters → $cc ✓\n";

// ── Step 5: Sync schools ──────────────────────────────────────────
try { $db->exec("UPDATE schools SET cluster_id = (SELECT cluster_id FROM src.schools WHERE src.schools.id = schools.id) WHERE id IN (SELECT id FROM src.schools)"); } catch(Exception $e){}
$db->exec("INSERT OR IGNORE INTO schools (id,name,cluster_id,is_active)
    SELECT id,name,cluster_id,is_active FROM src.schools WHERE is_active=1");
$sc = $db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");
echo "Step 5: Schools → $sc active ✓\n";

// ── Step 6: Sync quiz questions ───────────────────────────────────
$existing = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
if ($existing < 100) {
    $db->exec("INSERT OR IGNORE INTO quiz_questions
        (id,module_id,question_type,question,option_a,option_b,option_c,option_d,option_e,correct_option,explanation,sort_order,is_published,section,competency,difficulty)
        SELECT id,module_id,question_type,question,option_a,option_b,option_c,option_d,COALESCE(option_e,''),correct_option,explanation,sort_order,is_published,
               COALESCE(section,'lesson'),COALESCE(competency,''),COALESCE(difficulty,'MEDIUM')
        FROM src.quiz_questions WHERE is_published=1");
    $qc = $db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1");
    echo "Step 6: Quiz questions → $qc ✓\n";
} else {
    echo "Step 6: Quiz questions already present ($existing) — skipped\n";
}

$db->exec("DETACH DATABASE src");
$db->close();
echo "\nALL DONE ✓\n</pre>";
unlink(__FILE__);
