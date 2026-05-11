<?php /* Module feedback poll form — included by module_detail.php */ ?>
<form method="POST" style="margin-top:14px;" id="pollForm">
    <input type="hidden" name="poll_rating" id="pollRatingInput" value="0">
    <input type="hidden" name="module_slug" value="<?= e($slug) ?>">

    <div style="margin-bottom:14px;">
        <div style="font-size:.8rem;font-weight:700;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">How useful was this module? *</div>
        <div style="display:flex;gap:6px;" id="starRow">
            <?php for($i=1;$i<=5;$i++): ?>
            <button type="button" class="star-btn" data-val="<?= $i ?>"
                style="font-size:1.7rem;background:none;border:none;cursor:pointer;padding:2px;line-height:1;filter:grayscale(1);transition:.1s;"
                onclick="setStar(<?= $i ?>)">⭐</button>
            <?php endfor; ?>
        </div>
        <div id="starLabel" style="font-size:.76rem;color:#6b7280;margin-top:4px;min-height:16px;"></div>
    </div>

    <div style="margin-bottom:12px;">
        <label style="display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:5px;">What did you find most useful? <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
        <textarea name="most_useful" rows="2" placeholder="e.g. The scenarios, the quiz questions, the DEAR method..." style="font-size:.85rem;"></textarea>
    </div>

    <div style="margin-bottom:12px;">
        <label style="display:block;font-size:.78rem;font-weight:700;color:#374151;margin-bottom:5px;">Was anything unclear or difficult? <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
        <textarea name="unclear" rows="2" placeholder="e.g. The quiz felt too hard, some terms were confusing..." style="font-size:.85rem;"></textarea>
    </div>

    <div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">
        <input type="checkbox" name="would_recommend" id="chkRecommend" value="1" checked style="width:16px;height:16px;cursor:pointer;">
        <label for="chkRecommend" style="font-size:.85rem;font-weight:600;cursor:pointer;">I would recommend this module to a friend</label>
    </div>

    <button type="submit" class="btn btn-primary btn-sm" id="pollSubmit" disabled>Submit Feedback</button>
</form>

<script>
var starLabels=['','Not useful','Somewhat useful','Useful','Very useful','Excellent!'];
function setStar(n){
    document.getElementById('pollRatingInput').value=n;
    document.querySelectorAll('.star-btn').forEach(function(b){
        b.style.filter=parseInt(b.dataset.val)<=n?'none':'grayscale(1)';
    });
    document.getElementById('starLabel').textContent=starLabels[n];
    document.getElementById('pollSubmit').disabled=false;
}
</script>
