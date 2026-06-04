            </main>
            </div>
            </div>

            <script>
                // Common Theme Toggle Script
                const themeIcon = document.getElementById('themeIcon');

                function updateThemeIcon() {
                    if (document.documentElement.getAttribute('data-theme') === 'dark') {
                        if (themeIcon) themeIcon.textContent = '☀️';
                    } else {
                        if (themeIcon) themeIcon.textContent = '🌙';
                    }
                }
                updateThemeIcon();

                function toggleTheme() {
                    const currentTheme = document.documentElement.getAttribute('data-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', newTheme);
                    localStorage.setItem('vendorTheme', newTheme);
                    updateThemeIcon();
                }

                // Common Sidebar Toggle Script
                function toggleSidebar() {
                    const sb = document.getElementById('navLinks');
                    const ov = document.getElementById('overlay');
                    if (sb) sb.classList.toggle('active');
                    if (ov) ov.classList.toggle('active');
                }

                if (window.innerWidth <= 900) {
                    const closeBtns = document.querySelectorAll('.mobile-only-close');
                    closeBtns.forEach(btn => btn.style.display = 'block');
                }

                // Global Android App Integration
                if (typeof AndroidInterface !== 'undefined') {
                    var vendorId = "<?= $_SESSION['vendor_id'] ?? '' ?>";
                    if (vendorId) {
                        AndroidInterface.saveVendorId(vendorId);
                    }
                }
            </script>

            <?php if (basename($_SERVER['PHP_SELF']) !== 'order-chat.php' && basename($_SERVER['PHP_SELF']) !== 'call.php'): ?>
            <!-- Floating Chat Widget Container -->
            <div class="floating-chat-container" id="floatingChatContainer" style="position: fixed; bottom: 25px; right: 25px; z-index: 10000; font-family: 'Inter', sans-serif;">
                <!-- Flyout Panel -->
                <div class="floating-chat-card" id="floatingChatCard" style="display: none; width: 320px; max-height: 400px; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; margin-bottom: 15px; flex-direction: column; overflow: hidden; animation: slideUp 0.3s ease;">
                    <div style="background: #4361ee; color: #fff; padding: 15px 20px; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
                        <span>💬 Active Conversations</span>
                        <span onclick="toggleChatCard()" style="cursor: pointer; opacity: 0.8; font-size: 18px;">&times;</span>
                    </div>
                    <div id="floatingChatList" style="overflow-y: auto; max-height: 330px; display: flex; flex-direction: column; background: #f8fafc;">
                        <div style="padding: 20px; text-align: center; color: #64748b; font-size: 13px;">No new messages.</div>
                    </div>
                </div>
                <!-- Floating Trigger Button -->
                <button class="floating-chat-trigger" onclick="toggleChatCard()" style="width: 60px; height: 60px; border-radius: 50%; background: #4361ee; border: none; color: #fff; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 5px 20px rgba(67, 97, 238, 0.4); position: relative; transition: all 0.3s ease; outline: none;">
                    <span style="font-size:24px; display:inline-block; transform:translateY(1px);">💬</span>
                    <span id="floatingChatBadge" style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 800; min-width: 20px; height: 20px; border-radius: 50%; display: none; align-items: center; justify-content: center; border: 2px solid #fff; animation: pulse 2s infinite;">0</span>
                </button>
            </div>

            <style>
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            @keyframes pulse {
                0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
                70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
                100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
            }
            .floating-chat-item {
                padding: 12px 20px;
                border-bottom: 1px solid #e2e8f0;
                background: #fff;
                cursor: pointer;
                transition: background 0.2s;
                display: flex;
                flex-direction: column;
                gap: 4px;
                text-decoration: none;
                color: inherit;
            }
            .floating-chat-item:hover {
                background: #eff6ff;
            }
            .floating-chat-item-title {
                font-weight: 700;
                font-size: 13px;
                color: #1e293b;
            }
            .floating-chat-item-msg {
                font-size: 12px;
                color: #64748b;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .floating-chat-item-time {
                font-size: 10px;
                color: #94a3b8;
                align-self: flex-end;
            }
            </style>

            <script>
            function toggleChatCard() {
                const card = document.getElementById('floatingChatCard');
                if (!card) return;
                if (card.style.display === 'none' || card.style.display === '') {
                    card.style.display = 'flex';
                    fetchFloatingChats();
                } else {
                    card.style.display = 'none';
                }
            }

            async function fetchFloatingChats() {
                try {
                    const rootPath = window.location.pathname.includes('/vendor/') || window.location.pathname.includes('/admin/') ? '../' : '';
                    const res = await fetch(rootPath + 'ajax_unread_chats.php');
                    const data = await res.json();
                    
                    if (data && data.success) {
                        // Update badge
                        const badge = document.getElementById('floatingChatBadge');
                        if (badge) {
                            badge.innerText = data.count;
                            badge.style.display = data.count > 0 ? 'flex' : 'none';
                        }
                        
                        // Update trigger style if unread count > 0
                        const trigger = document.querySelector('.floating-chat-trigger');
                        if (trigger) {
                            if (data.count > 0) {
                                trigger.style.boxShadow = '0 5px 25px rgba(239, 68, 68, 0.5)';
                                trigger.style.background = '#ef4444';
                            } else {
                                trigger.style.boxShadow = '0 5px 20px rgba(67, 97, 238, 0.4)';
                                trigger.style.background = '#4361ee';
                            }
                        }
                        
                        // Populate list
                        const list = document.getElementById('floatingChatList');
                        if (list) {
                            if (!data.chats || data.chats.length === 0) {
                                list.innerHTML = '<div style="padding: 20px; text-align: center; color: #64748b; font-size: 13px;">No active conversations.</div>';
                            } else {
                                let html = '';
                                data.chats.forEach(chat => {
                                    const isUnread = chat.unread_count > 0;
                                    const highlightStyle = isUnread ? 'background: #fff5f5; border-left: 4px solid #ef4444;' : '';
                                    const badgeHtml = isUnread 
                                        ? `<span style="background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 10px; margin-left: 8px;">${chat.unread_count}</span>` 
                                        : '';
                                    
                                    html += `
                                        <a href="${chat.link}" class="floating-chat-item" style="${highlightStyle}">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <div class="floating-chat-item-title" style="${isUnread ? 'color: #b91c1c; font-weight: 800;' : ''}">${chat.title}</div>
                                                ${badgeHtml}
                                            </div>
                                            <div class="floating-chat-item-msg" style="${isUnread ? 'font-weight: 600; color: #1e293b;' : ''}">${chat.message}</div>
                                            <div class="floating-chat-item-time">${chat.time}</div>
                                        </a>
                                    `;
                                });
                                list.innerHTML = html;
                            }
                        }
                    }
                } catch (e) {
                    console.error('Error fetching unread chats:', e);
                }
            }

            // Poll count every 20 seconds
            setInterval(fetchFloatingChats, 20000);
            // Run on load
            document.addEventListener('DOMContentLoaded', fetchFloatingChats);
            </script>
            <?php endif; ?>

<!-- Global Incoming Call Alert Modal for Vendor -->
<div id="incomingCallModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); z-index: 99999; align-items: center; justify-content: center; font-family: 'Inter', sans-serif;">
    <div style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 24px; padding: 40px; text-align: center; color: white; width: 380px; max-width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
        <div style="margin-bottom: 20px; position: relative;">
            <div style="width: 80px; height: 80px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; animation: pulseCall 1.5s infinite;">
                <span style="font-size:32px; display:inline-block; transform:translateY(1px);">📞</span>
            </div>
        </div>
        <h3 id="incomingCallTitle" style="font-size: 20px; font-weight: 800; margin-bottom: 10px;">Incoming Call</h3>
        <p id="incomingCallBusiness" style="font-size: 16px; font-weight: 600; color: #a1a1aa; margin-bottom: 5px;">Surpriseville Office</p>
        <p id="incomingCallVendor" style="font-size: 13px; font-weight: 500; color: #71717a; margin-bottom: 30px;">Partner: Admin (Support)</p>
        
        <div style="display: flex; gap: 15px; justify-content: center;">
            <button id="btnDeclineIncoming" style="flex: 1; padding: 14px; border-radius: 14px; background: #ef4444; border: none; color: white; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; transition: background 0.2s;">
                Decline
            </button>
            <button id="btnAcceptIncoming" style="flex: 1; padding: 14px; border-radius: 14px; background: #10b981; border: none; color: white; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; transition: background 0.2s;">
                Accept
            </button>
        </div>
    </div>
</div>

<style>
@keyframes pulseCall {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 20px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<script>
let currentIncomingCallId = null;
let currentIncomingOrderId = null;
let currentIncomingCallType = null;
let globalRingtoneInterval = null;
let globalAudioCtx = null;

function playSyntheticRing() {
    try {
        if (!globalAudioCtx) {
            globalAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (globalAudioCtx.state === 'suspended') {
            globalAudioCtx.resume();
        }
        
        const osc1 = globalAudioCtx.createOscillator();
        const osc2 = globalAudioCtx.createOscillator();
        const gainNode = globalAudioCtx.createGain();
        
        osc1.type = 'sine';
        osc1.frequency.value = 440;
        
        osc2.type = 'sine';
        osc2.frequency.value = 480;
        
        gainNode.gain.setValueAtTime(0, globalAudioCtx.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.15, globalAudioCtx.currentTime + 0.1);
        gainNode.gain.setValueAtTime(0.15, globalAudioCtx.currentTime + 1.8);
        gainNode.gain.linearRampToValueAtTime(0, globalAudioCtx.currentTime + 2.0);
        
        osc1.connect(gainNode);
        osc2.connect(gainNode);
        gainNode.connect(globalAudioCtx.destination);
        
        osc1.start();
        osc2.start();
        
        osc1.stop(globalAudioCtx.currentTime + 2.0);
        osc2.stop(globalAudioCtx.currentTime + 2.0);
    } catch (e) {
        console.warn('Audio Context ring error:', e);
    }
}

async function checkIncomingCallsGlobal() {
    if (document.getElementById('incomingCallModal').style.display === 'flex') return;

    try {
        const res = await fetch('/vendor/ajax/check_incoming_calls.php');
        const data = await res.json();
        if (data && data.success && data.incoming) {
            currentIncomingCallId = data.call_session_id;
            currentIncomingOrderId = data.order_id;
            currentIncomingCallType = data.call_type;
            
            document.getElementById('incomingCallTitle').innerText = (data.call_type === 'video' ? '📹 Video Call' : '📞 Voice Call');
            document.getElementById('incomingCallModal').style.display = 'flex';
            
            playSyntheticRing();
            globalRingtoneInterval = setInterval(playSyntheticRing, 3000);
        }
    } catch(e) {
        // Silent
    }
}

document.getElementById('btnDeclineIncoming').onclick = async function() {
    if (!currentIncomingCallId) return;
    const sid = currentIncomingCallId;
    currentIncomingCallId = null;
    
    clearInterval(globalRingtoneInterval);
    document.getElementById('incomingCallModal').style.display = 'none';
    
    try {
        const fd = new FormData();
        fd.append('action', 'decline_call');
        fd.append('call_session_id', sid);
        fd.append('vendor_id', "<?= $_SESSION['vendor_id'] ?? 0 ?>");
        await fetch('/webrtc_signal_proxy.php', { method: 'POST', body: fd, credentials: 'include' });
        
        // Log "Call declined" in chat history
        const logFd = new FormData();
        logFd.append('message', '📞 Call declined');
        logFd.append('order_id', currentIncomingOrderId);
        logFd.append('is_offline', '0');
        await fetch('/vendor/ajax/save_vendor_message.php', { method: 'POST', body: logFd });
    } catch (e) {
        console.warn('Decline signal failed:', e);
    }
};

document.getElementById('btnAcceptIncoming').onclick = function() {
    if (!currentIncomingCallId) return;
    
    clearInterval(globalRingtoneInterval);
    document.getElementById('incomingCallModal').style.display = 'none';
    
    const callUrl = `/vendor/call.php?order_id=${currentIncomingOrderId}&call_id=${currentIncomingCallId}&action=answer&call_type=${currentIncomingCallType}`;
        
    window.open(callUrl, 'webrtc_call_window', 'width=1000,height=750,toolbar=no,menubar=no,location=no,status=no');
};

setInterval(checkIncomingCallsGlobal, 4000);
document.addEventListener('DOMContentLoaded', checkIncomingCallsGlobal);
</script>

<!-- Global Loading Overlay -->
<div id="globalLoadingOverlay" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.75); backdrop-filter:blur(6px); z-index:999999; align-items:center; justify-content:center; flex-direction:column; font-family:'Inter', sans-serif;">
    <div style="width: 54px; height: 54px; border: 5px solid rgba(255,255,255,0.15); border-top: 5px solid #135bec; border-radius: 50%; animation: globalSpin 1s linear infinite; margin-bottom: 20px;"></div>
    <div style="color:white; font-size:16px; font-weight:700; letter-spacing:-0.3px;">Processing...</div>
    <div style="color:#94a3b8; font-size:13px; margin-top:6px;">Please wait while we update your status.</div>
</div>

<style>
@keyframes globalSpin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
window.showLoading = function() {
    const overlay = document.getElementById('globalLoadingOverlay');
    if (overlay) {
        overlay.style.display = 'flex';
    }
};

window.hideLoading = function() {
    const overlay = document.getElementById('globalLoadingOverlay');
    if (overlay) {
        overlay.style.display = 'none';
    }
};
</script>
</body>

</html>