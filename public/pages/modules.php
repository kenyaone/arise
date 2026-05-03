<?php trackPageView('modules'); ?>
<div class="container">
    <div class="breadcrumb">
        <a href="/">Home</a> <span class="sep">›</span> <span>Modules</span>
    </div>
    <h1 class="page-title">📚 Learning Modules</h1>
    <p class="text-muted mb-2">Choose a topic to start learning. Each module has interactive lessons, videos and certificates.</p>
    <div class="modules-grid">
        <?php foreach ($modules as $m): 
            $lessonCount = db()->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id = {$m['id']} AND is_active = 1");
            $thumb = !empty($m['thumbnail']) ? '/arise/uploads/' . $m['thumbnail'] : null;
        ?>
            <a href="?p=module&slug=<?= e($m['slug']) ?>" class="module-card">
                <?php if ($thumb): ?>
                    <div class="card-thumb">
                        <img src="<?= e($thumb) ?>" alt="<?= e($m['title']) ?>" loading="lazy">
                    </div>
                <?php else: ?>
                    <div class="card-icon"><?= $m['icon'] ?></div>
                <?php endif; ?>
                <h3><?= e($m['title']) ?></h3>
                <p><?= e($m['description']) ?></p>
                <div class="card-meta">
                    <span>📖 <?= $lessonCount ?> lesson<?= $lessonCount !== 1 ? 's' : '' ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<style>
.card-thumb {
    width: calc(100% + 2.4rem);
    height: 160px;
    overflow: hidden;
    border-radius: 10px 10px 0 0;
    margin: -1.2rem -1.2rem 1rem -1.2rem;
}
.card-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}
.module-card:hover .card-thumb img {
    transform: scale(1.05);
}
</style>
