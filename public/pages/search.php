<?php trackPageView('search'); ?>
<div class="container">
  <h1 class="page-title">🔍 Search</h1>
  <div class="search-bar-wrap">
    <input type="text" class="search-bar" id="searchInput" placeholder="Search lessons, modules, topics..." oninput="doSearch(this.value)" autofocus>
  </div>
  <div id="searchResults"></div>
</div>
<script>
var allContent = <?php
  $lessons = db()->query("SELECT l.title, l.slug, l.lesson_type, l.content, m.title as module_title, m.slug as module_slug, m.icon FROM lessons l JOIN modules m ON l.module_id=m.id WHERE l.is_active=1");
  $items = [];
  while($r = $lessons->fetchArray(SQLITE3_ASSOC)) {
    $items[] = ['title'=>$r['title'],'slug'=>$r['slug'],'type'=>$r['lesson_type'],'module'=>$r['module_title'],'mslug'=>$r['module_slug'],'icon'=>$r['icon'],'snippet'=>substr(strip_tags($r['content']??''),0,120)];
  }
  $mods = db()->query("SELECT title, slug, description, icon FROM modules WHERE is_active=1");
  while($r = $mods->fetchArray(SQLITE3_ASSOC)) {
    $items[] = ['title'=>$r['title'],'slug'=>$r['slug'],'type'=>'module','module'=>'','mslug'=>$r['slug'],'icon'=>$r['icon'],'snippet'=>substr($r['description']??'',0,120)];
  }
  echo json_encode($items);
?>;
function doSearch(q) {
  var el = document.getElementById('searchResults');
  if (!q || q.length < 2) { el.innerHTML = ''; return; }
  var ql = q.toLowerCase();
  var results = allContent.filter(function(i){ return i.title.toLowerCase().includes(ql) || (i.snippet||'').toLowerCase().includes(ql) || i.module.toLowerCase().includes(ql); });
  if (!results.length) { el.innerHTML = '<div class="dp-card" style="text-align:center;color:#999">No results for <strong>'+q+'</strong></div>'; return; }
  el.innerHTML = results.slice(0,25).map(function(r){
    var href = r.type==='module' ? '/arise/?p=module&slug='+r.slug : '/arise/?p=lesson&slug='+r.slug;
    var typeLabel = r.type==='module'?'Module':r.type==='video'?'Video':r.type==='pdf'?'PDF':'Lesson';
    var typeCls = r.type==='module'?'sr-module':r.type==='video'?'sr-video':'sr-lesson';
    return '<a href="'+href+'" style="text-decoration:none"><div class="search-result"><span class="sr-type '+typeCls+'">'+r.icon+' '+typeLabel+'</span><div class="sr-title">'+r.title+'</div>'+(r.module?'<div style="font-size:.72rem;color:var(--green);font-weight:700;margin-bottom:3px">'+r.module+'</div>':'')+(r.snippet?'<div class="sr-snippet">'+r.snippet+'…</div>':'')+'</div></a>';
  }).join('');
}
var q = new URLSearchParams(location.search).get('q');
if (q) { document.getElementById('searchInput').value=q; doSearch(q); }
</script>
