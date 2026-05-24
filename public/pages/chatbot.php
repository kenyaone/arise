<?php
/**
 * ARISE AI Chatbot
 * Handles chat API endpoint and renders the floating chatbot widget
 */

// API endpoint
if (isset($_GET['action']) && $_GET['action'] === 'chat') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $message = trim($body['message'] ?? '');
    $history = $body['history'] ?? [];

    if (!$message) { echo json_encode(['reply' => '']); exit; }

    // Build context from ARISE DB
    $modules = [];
    $mq = db()->query("SELECT title, slug, icon FROM modules WHERE is_active=1 ORDER BY sort_order");
    while ($r = $mq->fetchArray(SQLITE3_ASSOC)) $modules[] = $r['icon'] . ' ' . $r['title'];

    $student = getStudentBySession();
    $studentCtx = $student
        ? "The student's name is " . $student['full_name'] . ", class: " . ($student['class_name'] ?? 'unknown') . "."
        : "The student has not registered yet.";

    $moduleList = implode(', ', $modules);

    $systemPrompt = "You are ARISE — a friendly, supportive AI health education assistant for Kenyan adolescents aged 12-19. 
Your role is to help students learn about reproductive health, mental health, life skills, and personal development.

ARISE platform info:
- Available modules: $moduleList
- $studentCtx
- Language: respond in English, Kiswahili or Sheng depending on the student's language
- Tone: warm, non-judgmental, age-appropriate, encouraging
- Always remind students that for serious health concerns they should speak to a trusted adult, counsellor or doctor

Rules:
- Never share personal student data
- Keep responses concise (2-4 sentences unless explaining a topic)
- Use emojis sparingly to feel friendly
- If asked about something outside health education, gently redirect to ARISE topics
- Never give medical diagnoses or prescriptions
- For sensitive topics (abuse, mental health crisis), always direct to a trusted adult or counsellor";

    // Build messages for API
    $messages = [];
    foreach (array_slice($history, -6) as $h) { // last 6 messages for context
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Call Ollama if available, else use simple keyword responses
    $reply = '';

    // Try Ollama first
    $ollamaUrl = defined('OLLAMA_URL') ? OLLAMA_URL : 'http://127.0.0.1:11434';
    $ollamaPayload = json_encode([
        'model'    => 'phi3',
        'messages' => array_merge([['role'=>'system','content'=>$systemPrompt]], $messages),
        'stream'   => false,
    ]);

    $ch = curl_init($ollamaUrl . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $ollamaPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $ollamaResp = curl_exec($ch);
    $ollamaErr  = curl_error($ch);
    curl_close($ch);

    if (!$ollamaErr && $ollamaResp) {
        $ollamaData = json_decode($ollamaResp, true);
        $reply = $ollamaData['message']['content'] ?? '';
    }

    // Fallback: smart keyword responses
    if (!$reply) {
        $lower = strtolower($message);
        $reply = getKeywordReply($lower, $moduleList, $student);
    }

    echo json_encode(['reply' => $reply ?: "Pole, sijaelewa swali lako. Jaribu tena! 😊"]);
    exit;
}

// Smart keyword fallback responses
function getKeywordReply($msg, $moduleList, $student) {
    $name = $student ? $student['full_name'] : 'rafiki';
    $firstName = explode(' ', $name)[0];

    $responses = [
        ['keys'=>['habari','hujambo','mambo','sasa','hi','hello','hey','niaje'],
         'reply'=>"Mambo safi $firstName! 👋 Mimi ni ARISE — msaidizi wako wa afya. Naweza kukusaidia na afya ya uzazi, afya ya akili, na ujuzi wa maisha. Una swali gani leo?"],
        ['keys'=>['period','hedhi','siku za mwezi','menstrual','damu'],
         'reply'=>"Hedhi ni mchakato wa kawaida kabisa! 🌸 Mwili wako unajitayarisha kila mwezi. Kama una maumivu makali au hedhi isiyo ya kawaida, zungumza na daktari. Angalia module ya **Menstrual Health** kwa maelezo zaidi!"],
        ['keys'=>['puberty','balehe','kukua','growth','body change'],
         'reply'=>"Mabadiliko ya mwili wakati wa ujana ni ya kawaida kabisa! 💪 Kila mtu hukua kwa wakati wake. Angalia module ya **Adolescent Growth & Development** — ina maelezo mazuri sana."],
        ['keys'=>['hiv','aids','ukimwi','std','sti','ngono'],
         'reply'=>"Swali zuri sana! 🎯 Kujua kuhusu HIV/STIs ni muhimu sana kwa usalama wako. Tumia kondomu, epuka syndaha zinazoshirikishwa, na pima mara kwa mara. Angalia module ya **HIV & STIs** kwa maelezo kamili."],
        ['keys'=>['stress','msongo','anxiety','wasiwasi','mental','akili','depressed','depression'],
         'reply'=>"Hisia hizo ni za kawaida! 💚 Stress inaweza kudhibitiwa. Pumzika vizuri, zungumza na mtu unayemwamini, na fanya mazoezi. Kama hisia hizi ni kali, tafadhali zungumza na mshauri au mzazi. Angalia module ya **Mental Health**."],
        ['keys'=>['drugs','dawa za kulevya','bhang','bangi','alcohol','pombe','miraa','shisha','cigarette','sigara','smoking'],
         'reply'=>"Ni muhimu kujua hatari za dawa za kulevya! ⚠️ Zinaathiri ubongo, masomo na afya yako. Ikiwa unahitaji msaada wa kuacha, zungumza na mtu unayemwamini. ARISE ina modules kuhusu **Cannabis, Alcohol, Shisha na Miraa**."],
        ['keys'=>['pregnant','mimba','ujauzito','baby','mtoto'],
         'reply'=>"Hii ni mada muhimu. 💭 Mimba ya mapema inaweza kuathiri masomo na afya yako. Kama una wasiwasi, zungumza na daktari au mzazi haraka. Kujizuia na kutumia uzazi wa mpango ni njia za kujilinda."],
        ['keys'=>['gbv','gender','violence','unyanyasaji','abuse','ubakaji','rape'],
         'reply'=>"Hii si kosa lako. 💚 Unyanyasaji wa aina yoyote si sawa na si wa kukubali. Tafadhali zungumza na mtu unayemwamini — mwalimu, mzazi, au mshauri — au piga simu ya dharura. Uko salama kuomba msaada."],
        ['keys'=>['certificate','cheti','pass','grade','score','alama'],
         'reply'=>"Vizuri sana! 🎓 Pata alama 60% au zaidi kwenye quiz ya module yoyote na utapata cheti. Soma vizuri na jaribu tena ukishindwa — unaweza kufanya hivyo $firstName!"],
        ['keys'=>['help','msaada','how','jinsi','nini','what','nifanye'],
         'reply'=>"Ninaweza kukusaidia na: 📚 Maswali ya afya ya ujana | 💆 Afya ya akili | 🌸 Afya ya uzazi | 💪 Ujuzi wa maisha. Uliza swali lolote — niko hapa!"],
        ['keys'=>['modules','lessons','masomo','topic','mada'],
         'reply'=>"ARISE ina masomo mengi! 📖 Modules zetu ni: $moduleList. Bonyeza yoyote kwenye ukurasa wa Modules kuanza!"],
    ];

    foreach ($responses as $r) {
        foreach ($r['keys'] as $k) {
            if (str_contains($msg, $k)) return $r['reply'];
        }
    }

    return "Asante kwa swali lako $firstName! 😊 Sijui jibu sahihi kwa hilo, lakini naweza kukusaidia na afya ya uzazi, afya ya akili, na ujuzi wa maisha. Una swali lingine?";
}
?>

// Not an API call - render widget
?>

<!-- ARISE Chatbot Widget -->
<style>
/* ── CHATBOT WIDGET ── */
#arise-chat-btn {
  position: fixed; bottom: 24px; right: 24px; z-index: 9000;
  width: 58px; height: 58px;
  background: linear-gradient(135deg, #3D6318, #4F7E20);
  border-radius: 50%; border: none; cursor: pointer;
  box-shadow: 0 6px 24px rgba(61,99,24,.45);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem; color: #fff; transition: all .2s;
  animation: chatPulse 3s ease-in-out infinite;
}
#arise-chat-btn:hover { transform: scale(1.1); box-shadow: 0 8px 32px rgba(61,99,24,.55); }
@keyframes chatPulse {
  0%,100%{box-shadow:0 6px 24px rgba(61,99,24,.45);}
  50%{box-shadow:0 6px 32px rgba(61,99,24,.7),0 0 0 8px rgba(61,99,24,.08);}
}
.chat-badge {
  position: absolute; top: -3px; right: -3px;
  width: 18px; height: 18px; background: #FF9700;
  border-radius: 50%; border: 2px solid #fff;
  font-size: .65rem; font-weight: 800; color: #fff;
  display: flex; align-items: center; justify-content: center;
}

#arise-chat-box {
  position: fixed; bottom: 92px; right: 24px; z-index: 9000;
  width: 340px; max-height: 520px;
  background: #fff; border-radius: 20px;
  box-shadow: 0 16px 60px rgba(0,0,0,.2);
  display: none; flex-direction: column; overflow: hidden;
  border: 1.5px solid rgba(61,99,24,.15);
  animation: chatSlideUp .25s ease;
}
@keyframes chatSlideUp {
  from{opacity:0;transform:translateY(20px);}
  to{opacity:1;transform:translateY(0);}
}
#arise-chat-box.open { display: flex; }

.chat-header {
  background: linear-gradient(135deg, #2D4A12, #3D6318);
  padding: 14px 16px; display: flex; align-items: center; gap: 10px;
}
.chat-avatar {
  width: 36px; height: 36px; background: #FF9700;
  border-radius: 50%; display: flex; align-items: center;
  justify-content: center; font-size: 1.1rem; flex-shrink: 0;
}
.chat-title { flex: 1; }
.chat-title h4 { font-size: .88rem; font-weight: 800; color: #fff; margin-bottom: 1px; }
.chat-title span { font-size: .68rem; color: rgba(255,255,255,.6); }
.chat-status { width: 8px; height: 8px; background: #6EE7B7; border-radius: 50%; box-shadow: 0 0 6px #6EE7B7; }
.chat-close { background: none; border: none; color: rgba(255,255,255,.7); font-size: 1.2rem; cursor: pointer; padding: 4px; }
.chat-close:hover { color: #fff; }

.chat-messages {
  flex: 1; overflow-y: auto; padding: 14px;
  display: flex; flex-direction: column; gap: 10px;
  background: #F7FAF2; min-height: 200px; max-height: 300px;
}

.chat-msg { display: flex; gap: 8px; align-items: flex-end; }
.chat-msg.user { flex-direction: row-reverse; }
.msg-bubble {
  max-width: 78%; padding: 9px 13px; border-radius: 16px;
  font-size: .84rem; line-height: 1.55; word-wrap: break-word;
}
.chat-msg.bot .msg-bubble {
  background: #fff; color: #1A2E08;
  border-radius: 4px 16px 16px 16px;
  box-shadow: 0 1px 4px rgba(0,0,0,.08);
  border: 1px solid rgba(61,99,24,.1);
}
.chat-msg.user .msg-bubble {
  background: linear-gradient(135deg, #3D6318, #4F7E20);
  color: #fff; border-radius: 16px 4px 16px 16px;
}
.msg-avatar {
  width: 26px; height: 26px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; flex-shrink: 0;
}
.chat-msg.bot .msg-avatar { background: #FF9700; }
.chat-msg.user .msg-avatar { background: #3D6318; color: #fff; }

.typing-dots { display: flex; gap: 4px; padding: 8px 13px; }
.typing-dots span {
  width: 7px; height: 7px; background: #8A9E70;
  border-radius: 50%; animation: dot .9s ease-in-out infinite;
}
.typing-dots span:nth-child(2){animation-delay:.15s;}
.typing-dots span:nth-child(3){animation-delay:.3s;}
@keyframes dot{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-6px);}}

.chat-suggestions {
  padding: 8px 12px; display: flex; gap: 6px;
  flex-wrap: wrap; background: #fff; border-top: 1px solid rgba(61,99,24,.08);
}
.chat-sugg {
  padding: 4px 10px; background: #F0F8E8;
  border: 1px solid rgba(61,99,24,.2); border-radius: 20px;
  font-size: .72rem; font-weight: 600; color: #3D6318;
  cursor: pointer; transition: all .15s; white-space: nowrap;
}
.chat-sugg:hover { background: #3D6318; color: #fff; }

.chat-input-row {
  display: flex; gap: 8px; padding: 12px 14px;
  background: #fff; border-top: 1px solid rgba(61,99,24,.1);
}
#chatInput {
  flex: 1; padding: 9px 13px; border: 1.5px solid rgba(61,99,24,.2);
  border-radius: 20px; font-size: .85rem; font-family: inherit;
  outline: none; background: #F7FAF2; color: #1A2E08;
  transition: all .2s;
}
#chatInput:focus { border-color: #3D6318; background: #fff; box-shadow: 0 0 0 3px rgba(61,99,24,.1); }
#chatSend {
  width: 36px; height: 36px; background: #3D6318;
  border: none; border-radius: 50%; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; color: #fff; transition: all .2s; flex-shrink: 0;
}
#chatSend:hover { background: #FF9700; transform: scale(1.05); }

.chat-footer {
  text-align: center; padding: 6px;
  font-size: .62rem; color: #8A9E70;
  background: #fff; border-top: 1px solid rgba(61,99,24,.06);
}

@media(max-width:480px){
  #arise-chat-box{width:calc(100vw - 32px);right:16px;}
}
</style>

<!-- Chat Button -->
<button id="arise-chat-btn" onclick="toggleChat()" aria-label="Open ARISE chat">
  💬
  <div class="chat-badge" id="chatBadge">1</div>
</button>

<!-- Chat Box -->
<div id="arise-chat-box">
  <div class="chat-header">
    <div class="chat-avatar">🌱</div>
    <div class="chat-title">
      <h4>ARISE Assistant</h4>
      <span>Health Education AI</span>
    </div>
    <div class="chat-status"></div>
    <button class="chat-close" onclick="toggleChat()">✕</button>
  </div>

  <div class="chat-messages" id="chatMessages">
    <div class="chat-msg bot">
      <div class="msg-avatar">🌱</div>
      <div class="msg-bubble">Habari! Mimi ni ARISE msaidizi wako wa afya. 👋 Ninaweza kukusaidia na maswali yoyote kuhusu afya ya ujana, afya ya akili, au ujuzi wa maisha. Una swali gani?</div>
    </div>
  </div>

  <div class="chat-suggestions" id="chatSuggs">
    <span class="chat-sugg" onclick="sendSugg(this)">Hedhi ni nini?</span>
    <span class="chat-sugg" onclick="sendSugg(this)">Jinsi ya kudhibiti stress</span>
    <span class="chat-sugg" onclick="sendSugg(this)">HIV inaenezwa vipi?</span>
    <span class="chat-sugg" onclick="sendSugg(this)">Modules zipo</span>
  </div>

  <div class="chat-input-row">
    <input type="text" id="chatInput" placeholder="Andika swali lako..." maxlength="300"
      onkeypress="if(event.key==='Enter')sendChat()">
    <button id="chatSend" onclick="sendChat()">➤</button>
  </div>
  <div class="chat-footer">Powered by ARISE AI · ariseci.org</div>
</div>

<script>
(function(){
  var history = [];
  var open = false;

  window.toggleChat = function() {
    open = !open;
    document.getElementById('arise-chat-box').classList.toggle('open', open);
    document.getElementById('chatBadge').style.display = 'none';
    if (open) document.getElementById('chatInput').focus();
  };

  window.sendSugg = function(el) {
    document.getElementById('chatInput').value = el.textContent;
    document.getElementById('chatSuggs').style.display = 'none';
    sendChat();
  };

  window.sendChat = function() {
    var input = document.getElementById('chatInput');
    var msg = input.value.trim();
    if (!msg) return;
    input.value = '';

    appendMsg('user', msg);
    history.push({role:'user', content:msg});
    showTyping();

    fetch('/arise/?p=chatbot&action=chat', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({message:msg, history:history})
    })
    .then(function(r){return r.json();})
    .then(function(d){
      removeTyping();
      var reply = d.reply || 'Samahani, jaribu tena!';
      appendMsg('bot', reply);
      history.push({role:'assistant', content:reply});
      if (history.length > 20) history = history.slice(-20);
    })
    .catch(function(){
      removeTyping();
      appendMsg('bot', 'Samahani, kuna tatizo. Jaribu tena! 😊');
    });
  };

  function appendMsg(role, text) {
    var el = document.getElementById('chatMessages');
    var div = document.createElement('div');
    div.className = 'chat-msg ' + role;
    var av = role === 'bot' ? '🌱' : '👤';
    // Parse **bold** markdown
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    div.innerHTML = '<div class="msg-avatar">'+av+'</div><div class="msg-bubble">'+text+'</div>';
    el.appendChild(div);
    el.scrollTop = el.scrollHeight;
  }

  function showTyping() {
    var el = document.getElementById('chatMessages');
    var div = document.createElement('div');
    div.className = 'chat-msg bot'; div.id = 'typingIndicator';
    div.innerHTML = '<div class="msg-avatar">🌱</div><div class="msg-bubble typing-dots"><span></span><span></span><span></span></div>';
    el.appendChild(div);
    el.scrollTop = el.scrollHeight;
  }

  function removeTyping() {
    var el = document.getElementById('typingIndicator');
    if (el) el.remove();
  }
})();
</script>
