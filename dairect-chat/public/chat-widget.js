document.addEventListener('DOMContentLoaded', function () {
  const input = document.getElementById('chat-input');
  const sendBtn = document.getElementById('chat-send');
  const clearBtn = document.getElementById('chat-clear');
  const micBtn = document.getElementById('chat-mic');
  const chatBox = document.getElementById('chat-messages');

  function appendMessage(text, className) {
    const msg = document.createElement('div');
    msg.className = className;
    msg.textContent = text;
    chatBox.appendChild(msg);
    chatBox.scrollTop = chatBox.scrollHeight;
  }

  sendBtn.onclick = () => {
    const message = input.value.trim();
    if (!message) return;
    appendMessage(message, 'chat-user');
    input.value = '';
    fetch(DairectChatAjax.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'dairect_chat',
        message: message,
        nonce: DairectChatAjax.nonce
      })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) appendMessage(data.data, 'chat-ai');
      else appendMessage('Error: ' + (data.data || 'Unknown'), 'chat-ai');
    });
  };

  clearBtn.onclick = () => {
    chatBox.innerHTML = '';
    fetch(DairectChatAjax.ajax_url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'dairect_chat_clear', nonce: DairectChatAjax.nonce })
    });
  };

  input.addEventListener('keypress', e => {
    if (e.key === 'Enter') sendBtn.click();
  });

  if (micBtn && ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
    const Rec = window.SpeechRecognition || window.webkitSpeechRecognition;
    const recog = new Rec();
    recog.onresult = e => { input.value = e.results[0][0].transcript; };
    micBtn.onclick = () => recog.start();
  }
});
