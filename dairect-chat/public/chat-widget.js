// dAIrect Chat – frontend (session-safe + clear errors)
document.addEventListener('DOMContentLoaded', function () {
  const input   = document.getElementById('chat-input');
  const sendBtn = document.getElementById('chat-send');
  const clearBtn= document.getElementById('chat-clear');
  const micBtn  = document.getElementById('chat-mic');
  const chatBox = document.getElementById('chat-messages');

  // Seed from PHP if provided; harmless if null/empty
  let dairectSessionId = (window.DairectChatAjax && DairectChatAjax.session_id) || null;
  let busy = false;

  function appendMessage(text, className) {
    const msg = document.createElement('div');
    msg.className = className;
    msg.textContent = text;
    chatBox.appendChild(msg);
    chatBox.scrollTop = chatBox.scrollHeight;
  }

  function errText(data, fallback) {
    if (!data) return fallback;
    if (typeof data === 'string') return data;
    if (data.error && data.error.message) return data.error.message;
    if (typeof data.data === 'string') return data.data;
    try { return JSON.stringify(data); } catch { return fallback; }
  }

  async function sendMessage() {
    // ★ Explicitly define the message variable (fixes shorthand issue)
    const message = (input.value || '').trim();
    if (!message || busy) return;

    busy = true;
    sendBtn.disabled = true;
    appendMessage(message, 'chat-user');
    input.value = '';

    try {
      const params = new URLSearchParams();
      params.set('action', 'dairect_chat');
      params.set('message', message);
      params.set('nonce', DairectChatAjax.nonce);
      params.set('session_id', dairectSessionId || '');

      const res = await fetch(DairectChatAjax.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      });

      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch { data = { success:false, error:{message:'Non-JSON'}, raw }; }

      if (data && data.session_id) dairectSessionId = data.session_id;

      if (res.ok && data && data.success) {
        appendMessage((data.data || data.text || '').toString() || 'OK', 'chat-ai');
      } else {
        const msg = errText(data, res.status === 403 ? 'Forbidden (nonce/capability)' : 'No response from Gemini');
        appendMessage('Error: ' + msg, 'chat-ai');
      }
    } catch {
      appendMessage('Error: Network failure', 'chat-ai');
    } finally {
      busy = false;
      sendBtn.disabled = false;
    }
  }

  sendBtn.onclick = sendMessage;

  clearBtn.onclick = async () => {
    chatBox.innerHTML = '';
    try {
      const params = new URLSearchParams();
      params.set('action', 'dairect_chat_clear');
      params.set('nonce', DairectChatAjax.nonce);
      params.set('session_id', dairectSessionId || '');

      await fetch(DairectChatAjax.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      });
    } catch {}
  };

  input.addEventListener('keypress', e => { if (e.key === 'Enter') sendBtn.click(); });

  if (micBtn && ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
    const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    const recog = new Rec();
    recog.onresult = e => { input.value = e.results[0][0].transcript; };
    micBtn.onclick = () => recog.start();
  }
});
