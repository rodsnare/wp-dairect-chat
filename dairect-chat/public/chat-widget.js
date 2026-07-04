// dAIrect Chat – frontend widget (embedded shortcode + floating drawer)
(function () {
    'use strict';

    var STORAGE_KEY_SESSION       = 'dairect_chat_session_id';
    var STORAGE_KEY_FLOAT_SESSION = 'dairect_float_session_id';

    function generateSessionId() {
        return 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    function getOrCreateSession(key) {
        var id = localStorage.getItem(key);
        if (!id) {
            id = generateSessionId();
            localStorage.setItem(key, id);
        }
        return id;
    }

    function clearSession(key) {
        localStorage.removeItem(key);
    }

    /**
     * Wire up a chat widget instance.
     * ids: { messages, input, send, clear, mic }
     * sessionKey: localStorage key for this instance's session.
     */
    function initWidget(ids, sessionKey) {
        var chatBox  = document.getElementById(ids.messages);
        var input    = document.getElementById(ids.input);
        var sendBtn  = document.getElementById(ids.send);
        var clearBtn = document.getElementById(ids.clear);
        var micBtn   = document.getElementById(ids.mic);

        if (!chatBox || !input || !sendBtn) return;

        var busy      = false;
        var sessionId = getOrCreateSession(sessionKey);

        function appendMessage(text, cls) {
            var msg = document.createElement('div');
            msg.className = cls;
            msg.textContent = text;
            chatBox.appendChild(msg);
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        function errText(data, fallback) {
            if (!data) return fallback;
            if (typeof data === 'string') return data;
            if (data.error && data.error.message) return data.error.message;
            if (typeof data.data === 'string') return data.data;
            try { return JSON.stringify(data); } catch (e) { return fallback; }
        }

        async function sendMessage() {
            var message = (input.value || '').trim();
            if (!message || busy) return;

            busy = true;
            sendBtn.disabled = true;
            appendMessage(message, 'chat-user');
            input.value = '';

            sessionId = getOrCreateSession(sessionKey);

            var params = new URLSearchParams();
            params.set('action', 'dairect_chat');
            params.set('message', message);
            params.set('nonce', DairectChatAjax.nonce);
            params.set('session_id', sessionId);

            try {
                var res  = await fetch(DairectChatAjax.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                });
                var raw  = await res.text();
                var data;
                try { data = JSON.parse(raw); } catch (e) { data = { success: false }; }

                if (data && data.session_id) {
                    sessionId = data.session_id;
                    localStorage.setItem(sessionKey, sessionId);
                }

                if (res.ok && data && data.success) {
                    var reply = data.message || (data.data && data.data.reply) || '';
                    appendMessage(reply || 'No response received', 'chat-ai');
                } else {
                    appendMessage('Error: ' + errText(data, 'No response from server'), 'chat-ai');
                }
            } catch (e) {
                appendMessage('Error: Network failure', 'chat-ai');
            } finally {
                busy = false;
                sendBtn.disabled = false;
            }
        }

        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') sendMessage();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', async function () {
                chatBox.innerHTML = '';
                clearSession(sessionKey);
                sessionId = getOrCreateSession(sessionKey);
                var params = new URLSearchParams();
                params.set('action', 'dairect_chat_clear');
                params.set('nonce', DairectChatAjax.nonce);
                params.set('session_id', sessionId);
                try {
                    await fetch(DairectChatAjax.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: params.toString()
                    });
                } catch (e) {}
            });
        }

        if (micBtn) {
            var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRec) {
                micBtn.style.display = 'none';
            } else {
                var recog     = new SpeechRec();
                var listening = false;
                recog.continuous     = false;
                recog.interimResults = true;
                recog.lang           = navigator.language || 'en-US';

                recog.onstart = function () {
                    listening = true;
                    micBtn.classList.add('is-listening');
                    micBtn.title = 'Listening… click to stop';
                };

                recog.onresult = function (e) {
                    var transcript = '';
                    for (var i = 0; i < e.results.length; i++) {
                        transcript += e.results[i][0].transcript;
                    }
                    input.value = transcript;
                };

                recog.onend = function () {
                    listening = false;
                    micBtn.classList.remove('is-listening');
                    micBtn.title = 'Click to speak';
                };

                recog.onerror = function (e) {
                    listening = false;
                    micBtn.classList.remove('is-listening');
                    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                        micBtn.classList.add('is-error');
                        micBtn.title = 'Microphone access denied';
                    } else if (e.error === 'no-speech') {
                        micBtn.title = 'No speech detected — try again';
                    } else {
                        micBtn.classList.add('is-error');
                        micBtn.title = 'Error: ' + e.error;
                    }
                    setTimeout(function () {
                        micBtn.classList.remove('is-error');
                        if (micBtn.title !== 'Microphone access denied') {
                            micBtn.title = 'Click to speak';
                        }
                    }, 2500);
                };

                micBtn.addEventListener('click', function () {
                    if (listening) {
                        recog.stop();
                    } else {
                        micBtn.classList.remove('is-error');
                        try { recog.start(); } catch (err) { /* already running */ }
                    }
                });
            }
        }
    }

    function initFloatingDrawer() {
        var floatBtn = document.getElementById('dairect-float-btn');
        var drawer   = document.getElementById('dairect-float-drawer');
        var closeBtn = document.getElementById('dairect-float-close');

        if (!floatBtn || !drawer) return;

        // Trigger pop-in once; remove the class after so hover reflows can't restart it
        floatBtn.classList.add('dairect-pop-in');

        function openDrawer() {
            drawer.classList.add('is-open');
            floatBtn.classList.remove('is-returning');
            void floatBtn.offsetWidth;
            floatBtn.classList.add('is-dropping');
            floatBtn.classList.add('is-behind');
            floatBtn.setAttribute('aria-expanded', 'true');
            var inp = document.getElementById('dairect-float-input');
            if (inp) { setTimeout(function () { inp.focus(); }, 460); }
        }

        function closeDrawer() {
            drawer.classList.remove('is-open');
            floatBtn.classList.remove('is-behind');
            floatBtn.classList.remove('is-dropping');
            void floatBtn.offsetWidth;
            floatBtn.classList.add('is-returning');
            floatBtn.setAttribute('aria-expanded', 'false');
        }

        floatBtn.addEventListener('click', function () {
            if (drawer.classList.contains('is-open')) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        // Jump on hover — suppress while drawer is open or button is animating
        floatBtn.addEventListener('mouseenter', function () {
            if (drawer.classList.contains('is-open')) return;
            if (floatBtn.classList.contains('is-returning')) return;
            floatBtn.classList.remove('dairect-jump');
            void floatBtn.offsetWidth;
            floatBtn.classList.add('dairect-jump');
        });
        floatBtn.addEventListener('animationend', function (e) {
            if (e.animationName === 'dairect-pop-in') {
                floatBtn.classList.remove('dairect-pop-in');
            }
            if (e.animationName === 'dairect-jump') {
                floatBtn.classList.remove('dairect-jump');
            }
            if (e.animationName === 'dairect-btn-return') {
                floatBtn.classList.remove('is-returning');
            }
        });

        initWidget({
            messages: 'dairect-float-messages',
            input:    'dairect-float-input',
            send:     'dairect-float-send',
            clear:    'dairect-float-clear',
            mic:      'dairect-float-mic',
        }, STORAGE_KEY_FLOAT_SESSION);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Embedded shortcode widget (no-ops if shortcode not on this page)
        initWidget({
            messages: 'chat-messages',
            input:    'chat-input',
            send:     'chat-send',
            clear:    'chat-clear',
            mic:      'chat-mic',
        }, STORAGE_KEY_SESSION);

        // Floating drawer (no-ops if floating mode disabled)
        initFloatingDrawer();
    });
})();
