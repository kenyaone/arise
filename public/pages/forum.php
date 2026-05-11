<?php
/**
 * ARISE Public Forum — Students post and reply openly
 */

// Ensure forum table exists
db()->exec("CREATE TABLE IF NOT EXISTS forum_posts (
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
)");

$student = getStudentBySession();
$msg = '';

// Upvote endpoint
if (isset($_GET['upvote'])) {
    $pid = intval($_GET['upvote']);
    $hash = getSessionHash();
    $already = db()->querySingle("SELECT id FROM forum_upvotes WHERE post_id=$pid AND session_hash='".SQLite3::escapeString($hash)."'");
    if ($already) {
        db()->exec("DELETE FROM forum_upvotes WHERE post_id=$pid AND session_hash='".SQLite3::escapeString($hash)."'");
        $action = 'removed';
    } else {
        $st=db()->prepare('INSERT OR IGNORE INTO forum_upvotes (post_id,session_hash) VALUES (:p,:h)');
        $st->bindValue(':p',$pid);$st->bindValue(':h',$hash);$st->execute();
        $action = 'added';
    }
    $count = (int)db()->querySingle("SELECT COUNT(*) FROM forum_upvotes WHERE post_id=$pid");
    header('Content-Type: application/json');
    echo json_encode(['action'=>$action,'count'=>$count]);
    exit;
}

// Post new thread or reply
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['post_body'])) {
    $body = trim($_POST['post_body']??'');
    $title = trim($_POST['post_title']??'');
    $modId = intval($_POST['module_id']??0) ?: null;
    $parentId = intval($_POST['parent_id']??0) ?: null;
    $name = $student ? $student['full_name'] : trim($_POST['anon_name']??'Anonymous');
    if (!$name) $name = 'Anonymous';
    if ($body) {
        $stmt = db()->prepare('INSERT INTO forum_posts (parent_id,student_id,student_name,module_id,title,body) VALUES (:p,:sid,:name,:mod,:title,:body)');
        $stmt->bindValue(':p',$parentId);
        $stmt->bindValue(':sid',$student?$student['id']:null);
        $stmt->bindValue(':name',$name);
        $stmt->bindValue(':mod',$modId);
        $stmt->bindValue(':title',$title?:null);
        $stmt->bindValue(':body',$body);
        $stmt->execute();
        $msg = '✅ Posted!';
        // Redirect to avoid repost on refresh
        header('Location: /arise/?p=forum&posted=1');
        exit;
    }
}

// Hide post (admin only — check session)
if (isset($_GET['hide']) && isset($_SESSION['arise_admin_id'])) {
    db()->exec("UPDATE forum_posts SET is_hidden=1 WHERE id=".intval($_GET['hide']));
    header('Location: /arise/?p=forum'); exit;
}

trackPageView('forum');
?>

<div class="container">
    <div class="breadcrumb">
        <a href="/arise/">Home</a> <span class="sep">›</span>
        <span>Community Forum</span>
    </div>

    <h1 class="page-title">💬 Community Forum</h1>
    <p class="text-muted" style="margin-top:-16px;margin-bottom:24px;">
        Ask questions, share ideas, and support each other. Be respectful — this is a safe space.
    </p>

    <?php if (isset($_GET['posted'])): ?>
        <div class="alert alert-success">✅ Your post was published!</div>
    <?php endif; ?>

    <!-- New Thread Form -->
    <div class="dp-card" style="border-left:4px solid var(--primary);margin-bottom:28px;">
        <h2 class="section-title">✏️ Start a New Discussion</h2>
        <form method="POST">
            <?php if (!$student): ?>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Your Name (optional)</label>
                <input type="text" name="anon_name" placeholder="Leave blank to post as Anonymous" style="max-width:300px;">
            </div>
            <?php endif; ?>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Title</label>
                <input type="text" name="post_title" placeholder="e.g. Question about stress management..." required>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Related Module (optional)</label>
                <?php
                // Pre-select module if coming from module page
                $preModSlug = $_GET['module'] ?? '';
                $preModId = $preModSlug ? db()->querySingle("SELECT id FROM modules WHERE slug='".SQLite3::escapeString($preModSlug)."'") : 0;
                ?>
                <select name="module_id" style="max-width:300px;">
                    <option value="">— General Discussion —</option>
                    <?php foreach($modules as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $preModId==$m['id']?'selected':'' ?>><?= $m['icon'] ?> <?= e($m['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:6px;text-transform:uppercase;">Your Message *</label>
                <textarea name="post_body" rows="4" placeholder="Share your thoughts, ask a question, or start a discussion..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">💬 Post to Forum</button>
        </form>
    </div>

    <!-- Thread List -->
    <?php
    $threads = db()->query("SELECT fp.*,m.title AS module_title,m.icon AS module_icon,
        (SELECT COUNT(*) FROM forum_posts r WHERE r.parent_id=fp.id AND r.is_hidden=0) AS reply_count
        FROM forum_posts fp
        LEFT JOIN modules m ON fp.module_id=m.id
        WHERE fp.parent_id IS NULL AND fp.is_hidden=0
        ORDER BY fp.is_pinned DESC, fp.created_at DESC
        LIMIT 50");
    $threadList = [];
    while ($t = $threads->fetchArray(SQLITE3_ASSOC)) $threadList[] = $t;
    ?>

    <?php if (count($threadList) === 0): ?>
        <div class="dp-card text-center" style="padding:40px;">
            <div style="font-size:2.5rem;margin-bottom:12px;">💬</div>
            <h3>No discussions yet</h3>
            <p class="text-muted">Be the first to start a conversation!</p>
        </div>
    <?php endif; ?>

    <?php foreach($threadList as $thread):
        // Get replies
        $replies = db()->query("SELECT * FROM forum_posts WHERE parent_id={$thread['id']} AND is_hidden=0 ORDER BY created_at ASC");
        $replyList = [];
        while ($r = $replies->fetchArray(SQLITE3_ASSOC)) $replyList[] = $r;
        $threadId = $thread['id'];
    ?>
    <div class="dp-card" style="margin-bottom:16px;<?= $thread['is_pinned']?'border-left:4px solid var(--accent);':'' ?>">

        <!-- Thread header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
            <div style="flex:1;">
                <?php if($thread['is_pinned']): ?><span class="badge badge-amber" style="margin-bottom:6px;">📌 Pinned</span><?php endif; ?>
                <?php if($thread['module_title']): ?>
                    <span class="badge badge-green" style="margin-bottom:6px;"><?= $thread['module_icon'] ?> <?= e($thread['module_title']) ?></span>
                <?php endif; ?>
                <h3 style="font-size:1.05rem;font-weight:700;margin:6px 0 4px;"><?= e($thread['title']?:'Discussion') ?></h3>
                <p style="margin:0 0 10px;line-height:1.6;"><?= nl2br(e($thread['body'])) ?></p>
                <div style="font-size:.78rem;color:var(--mid);">
                    👤 <strong><?= e($thread['student_name']) ?></strong>
                    &nbsp;·&nbsp; 🕐 <?= date('M j, Y g:i A', strtotime($thread['created_at'])) ?>
                    &nbsp;·&nbsp; 💬 <?= $thread['reply_count'] ?> repl<?= $thread['reply_count']!=1?'ies':'y' ?>
                    &nbsp;·&nbsp;
                    <?php
                    $upvotes = (int)db()->querySingle("SELECT COUNT(*) FROM forum_upvotes WHERE post_id=$threadId");
                    $myVote = db()->querySingle("SELECT id FROM forum_upvotes WHERE post_id=$threadId AND session_hash='".SQLite3::escapeString(getSessionHash())."'");
                    ?>
                    <button class="upvote-btn <?= $myVote?'voted':'' ?>" onclick="upvotePost(<?= $threadId ?>,this)" data-id="<?= $threadId ?>">
                      👍 <span class="uv-count"><?= $upvotes ?></span>
                    </button>
                    <?php if(isset($_SESSION['arise_admin_id'])): ?>
                        &nbsp;·&nbsp; <a href="/arise/?p=forum&hide=<?= $threadId ?>" style="color:var(--danger);font-size:.75rem;" onclick="return confirm('Hide this post?')">🗑 Hide</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Replies -->
        <?php if (count($replyList) > 0): ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
            <?php foreach($replyList as $reply): ?>
            <div style="display:flex;gap:12px;margin-bottom:14px;">
                <div style="width:32px;height:32px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:var(--primary-dark);flex-shrink:0;">
                    <?= strtoupper(substr($reply['student_name'],0,1)) ?>
                </div>
                <div style="flex:1;background:var(--light);border-radius:10px;padding:12px 14px;">
                    <div style="font-size:.78rem;color:var(--mid);margin-bottom:6px;">
                        <strong style="color:var(--dark);"><?= e($reply['student_name']) ?></strong>
                        &nbsp;·&nbsp; <?= date('M j, g:i A', strtotime($reply['created_at'])) ?>
                        <?php if(isset($_SESSION['arise_admin_id'])): ?>
                            &nbsp;<a href="/arise/?p=forum&hide=<?= $reply['id'] ?>" style="color:var(--danger);font-size:.72rem;" onclick="return confirm('Hide?')">🗑</a>
                        <?php endif; ?>
                    </div>
                    <p style="margin:0;line-height:1.6;"><?= nl2br(e($reply['body'])) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Reply form -->
        <div style="margin-top:14px;padding-top:12px;border-top:1px dashed var(--border);">
            <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="parent_id" value="<?= $threadId ?>">
                <div style="flex:1;min-width:200px;">
                    <textarea name="post_body" rows="2" placeholder="Write a reply..." required style="margin-bottom:0;"></textarea>
                </div>
                <?php if (!$student): ?>
                <input type="text" name="anon_name" placeholder="Your name (optional)" style="width:160px;">
                <?php endif; ?>
                <button type="submit" class="btn btn-secondary btn-sm">↩ Reply</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

</div>