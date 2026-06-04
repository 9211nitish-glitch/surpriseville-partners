# 🏗️ Custom Chat & WebRTC Calling System — Full Implementation Plan

> **Goal**: Replace Jitsi Meet (external 3rd-party) with our own WebRTC-based peer-to-peer calling + an enhanced chat system built entirely on our own PHP backend. Zero external call dependencies. Full control over UX, signaling, permissions, and reliability.

---

## 📌 Why Replace Jitsi?

| Problem with Jitsi | Our Custom Solution |
|---|---|
| Loads external JS from `meet.jit.si` — network dependent | Self-hosted signaling, no external JS for calls |
| CORS preflight issues on `__CALL_INVITE__` hack | Dedicated clean WebRTC signaling API |
| Call state hacked via chat messages (`__CALL_INVITE__:roomName`) | Dedicated `call_sessions` table — clean separation |
| No call history or logs | Full call log with duration, type, status |
| No way to decline / miss / timeout calls properly | Proper `RINGING → ACCEPTED / DECLINED / MISSED / ENDED` lifecycle |
| Jitsi adds watermark, non-branded UI | 100% branded, full-screen call UI |
| Audio-only not reliably enforced | Native WebRTC `addTrack` / `getAudioTracks` only |

---

## 🗺️ System Architecture

```
┌──────────────────────────────────────────────────────────┐
│                SURPRISEVILLE.CO.IN                        │
│                                                          │
│  /ajax/chat_api.php  ←──── REST + Long-Poll API           │
│  /ajax/webrtc_signal.php ←── WebRTC Signaling (ICE/SDP)  │
│                                                          │
│  DB: surpriseville_emp                                   │
│  ├── chat_messages (existing, MODIFIED)                  │
│  ├── call_sessions (NEW)                                 │
│  └── webrtc_signals (NEW)                                │
└──────────────────────────────────────────────────────────┘
           ↑↑                          ↑↑
    HTTP Polling (3s)           HTTP Polling (1s, signaling only)
           ↑↑                          ↑↑
┌─────────────────────┐    ┌──────────────────────────┐
│  VENDOR PORTAL       │    │   CUSTOMER PORTAL         │
│  order-chat.php      │    │   user-dashboard.php      │
│  (partners.*)        │    │   (surpriseville.co.in)   │
│                      │    │                          │
│  chat_engine.js (NEW)│    │  chat_engine.js (NEW)    │
│  webrtc_client.js(NEW│    │  webrtc_client.js (NEW)  │
└─────────────────────┘    └──────────────────────────┘
```

---

## 🔄 Complete Flow Diagrams

### 1️⃣ Chat Message Flow (Current + Enhanced)

```
Vendor types message
        │
        ▼
sendMessage() in chat_engine.js
        │
        ▼
POST /ajax/chat_api.php  action=send_message
        │
        ▼
INSERT into chat_messages (order_id, sender_type, sender_id, message, msg_type)
        │
        ▼
Returns {success: true, message_id: X}
        │
Customer's browser polls every 3s  ─────────────────────────►
        │                                         GET /ajax/chat_api.php action=get_messages
        │                                                  │
        ▼                                                  ▼
UI renders message bubble                    New message appended to DOM
        │
        ▼
mark_read() called if message is received
        │
        ▼
POST /ajax/chat_api.php  action=mark_read
        │
        ▼
UPDATE chat_messages SET is_read=1
        │
        ▼
Vendor sees ✓✓ (double tick = read)
```

### 2️⃣ WebRTC Call Flow (NEW — Custom)

```
[VENDOR] Clicks "Audio Call" or "Video Call"
        │
        ▼
webrtc_client.js: createPeerConnection()
        │ Gets local media (camera/mic)
        ▼
createOffer() → SDP Offer generated
        │
        ▼
POST /ajax/webrtc_signal.php
  action=initiate_call
  order_id, call_type (audio/video)
  sdp_offer
        │
        ▼
DB: INSERT call_sessions
  (order_id, caller_type=vendor, caller_id, call_type, status=ringing, sdp_offer)
  Returns call_session_id
        │
        ▼
DB: INSERT webrtc_signals
  (call_session_id, type=offer, from_type=vendor, payload=sdpOffer)
        │
─────────────────────────────────────────────────────────────────────
[CUSTOMER SIDE] Polling every 1s for incoming call signals
        │
        ▼
GET /ajax/webrtc_signal.php action=poll_signal
        │
        ▼
DB: SELECT from call_sessions WHERE
  (order's user_id = me) AND status='ringing'
  AND created_at > NOW() - 60s
        │
        ▼
Found! → showIncomingCallUI(call_session_id, caller_name, call_type)
        │
        ├── DECLINE → POST action=decline_call
        │           UPDATE call_sessions SET status=declined
        │           Vendor sees "Call Declined"
        │
        └── ACCEPT → getLocalMedia() → createPeerConnection()
                    createAnswer(remote_sdp=sdp_offer)
                    POST /ajax/webrtc_signal.php
                      action=answer_call
                      call_session_id
                      sdp_answer
                    UPDATE call_sessions SET status=active, sdp_answer
                    INSERT webrtc_signals (type=answer, payload=sdpAnswer)
─────────────────────────────────────────────────────────────────────
[VENDOR SIDE] Polling every 1s for answer
        │
        ▼
GET /ajax/webrtc_signal.php action=poll_signal
        │
        ▼
Found answer → setRemoteDescription(sdp_answer)
        │
        ▼
ICE Candidate Exchange Loop:
  Both sides continuously:
    onicecandidate → POST action=send_ice
                     INSERT webrtc_signals (type=ice_candidate)
    Poll → action=get_ice → SELECT new ICE candidates → addIceCandidate()
─────────────────────────────────────────────────────────────────────
[CONNECTED] P2P WebRTC Stream Active
  Both sides show call UI with live video/audio tracks
        │
        ▼
Either side clicks "End Call"
        │
        ▼
POST /ajax/webrtc_signal.php action=end_call
  UPDATE call_sessions SET status=ended, ended_at=NOW()
  duration = TIMESTAMPDIFF(SECOND, answered_at, ended_at)
        │
        ▼
Other side polls → sees status=ended → closes PeerConnection → hides UI
```

### 3️⃣ Notification/Polling Flow

```
Every page (header.php loaded for user/vendor)
        │
        ▼
setInterval(pollNotifications, 5000)
        │
        ▼
POST /ajax/chat_api.php  action=check_notifications
        │
        ├── Returns: unread_chat_count, latest_chat, incoming_call
        │
        ├── If incoming_call found:
        │     showCallNotificationBanner()
        │     Start ringing tone
        │     [Accept] → redirect to order-chat.php?order_id=X&call_id=Y
        │     [Decline] → POST action=decline_call
        │
        └── If new message found:
              showMessageNotificationBanner()
              Play notification sound
              [Click] → redirect to order-chat.php?order_id=X
```

---

## 🗃️ Database Changes

### ✅ Tables to KEEP (No Changes)
- `chat_messages` — keep all existing columns, add 2 new columns

### 🔧 Columns to ADD to `chat_messages`

```sql
ALTER TABLE chat_messages
  ADD COLUMN msg_type ENUM('text','call_log') NOT NULL DEFAULT 'text' AFTER message,
  ADD COLUMN call_session_id INT(11) DEFAULT NULL AFTER msg_type;
```

> **Why**: Replace the old `__CALL_INVITE__:roomName` hack in chat messages with a clean `msg_type='call_log'` + `call_session_id` reference. Old messages still work because `msg_type` defaults to 'text'.

### 🆕 New Table: `call_sessions`

```sql
CREATE TABLE `call_sessions` (
  `id`               INT(11) NOT NULL AUTO_INCREMENT,
  `order_id`         INT(11) NOT NULL,
  `caller_type`      ENUM('user','vendor') NOT NULL,
  `caller_id`        INT(11) NOT NULL,
  `call_type`        ENUM('audio','video') NOT NULL DEFAULT 'audio',
  `status`           ENUM('ringing','active','ended','declined','missed','failed') NOT NULL DEFAULT 'ringing',
  `sdp_offer`        MEDIUMTEXT DEFAULT NULL   COMMENT 'Caller SDP offer (base64)',
  `sdp_answer`       MEDIUMTEXT DEFAULT NULL   COMMENT 'Callee SDP answer (base64)',
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `answered_at`      TIMESTAMP NULL DEFAULT NULL,
  `ended_at`         TIMESTAMP NULL DEFAULT NULL,
  `duration_seconds` INT(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='WebRTC call lifecycle tracking';
```

### 🆕 New Table: `webrtc_signals`

```sql
CREATE TABLE `webrtc_signals` (
  `id`              BIGINT(20) NOT NULL AUTO_INCREMENT,
  `call_session_id` INT(11) NOT NULL,
  `from_type`       ENUM('user','vendor') NOT NULL,
  `signal_type`     ENUM('offer','answer','ice_candidate','end','decline') NOT NULL,
  `payload`         MEDIUMTEXT NOT NULL COMMENT 'JSON: SDP or ICE candidate',
  `is_delivered`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `call_session_id` (`call_session_id`),
  KEY `from_type_signal` (`call_session_id`,`from_type`,`signal_type`),
  KEY `undelivered` (`call_session_id`,`is_delivered`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='WebRTC SDP and ICE exchange signals';
```

> **Auto-cleanup**: Signals older than 5 minutes are irrelevant. Add a MySQL event or cron:
> `DELETE FROM webrtc_signals WHERE created_at < NOW() - INTERVAL 5 MINUTE;`

---

## 🗑️ What To DELETE (Old Jitsi / Hack References)

### In `partners.surpriseville.co.in/vendor/order-chat.php`
- **DELETE** variable: `let jitsiApi = null;`
- **DELETE** functions: `joinCall()`, `startCall()` (entire old Jitsi versions)
- **DELETE** the Jitsi `endCall()` function
- **DELETE** the `jitsiContainer` div: `<div id="jitsiContainer" ...>`
- **DELETE** auto-join via URL param: `urlParams.has('join_call')` block
- **DELETE** the Jitsi `__CALL_INVITE__` rendering inside `loadMessages()` (the `startsWith('__CALL_INVITE__')` branch)
- **KEEP** the call overlay HTML (`callOverlay` div) — repurpose for WebRTC stream

### In `Surpriseville.co.in/includes/header.php` (Customer)
- **DELETE** the accept call handler redirect: `window.location.href = '...join_call=' + ...`
- **KEEP** the `showCallPopup()` function structure — repurpose to open WebRTC call inline

### In `Surpriseville.co.in/ajax/chat_api.php`
- **DELETE** the `__CALL_INVITE__` pattern in `check_calls` SQL queries
- **REPLACE** with `call_sessions` table queries

### In `partners.surpriseville.co.in/vendor/header.php`
- **KEEP** all notification polling
- **REPLACE** the call accept redirect with WebRTC inline handler

---

## 📁 New Files To Create

### 1. `/Surpriseville.co.in/ajax/webrtc_signal.php` 🆕
**Purpose**: Central WebRTC signaling server. Handles all call state + ICE/SDP exchange via simple HTTP polling.

**Actions**:
- `initiate_call` — caller creates call session + stores SDP offer
- `poll_signal` — callee/caller polls for new signals (answer, ICE, end)
- `answer_call` — callee stores SDP answer, updates call status to `active`
- `send_ice` — either side stores ICE candidates
- `get_ice` — either side fetches undelivered ICE candidates from other party
- `end_call` — either side ends call, sets duration
- `decline_call` — callee declines, sets status `declined`
- `get_call_history` — fetch call log for an order

### 2. `/Surpriseville.co.in/assets/js/webrtc_client.js` 🆕
**Purpose**: Complete WebRTC client library used by both vendor and customer chat pages.

**Key Classes/Functions**:
```javascript
class WebRTCClient {
  constructor(config)        // Pass signalApiUrl, orderId, sessionId, myType
  async initiateCall(type)   // 'audio' | 'video' — caller side
  async handleIncomingCall(callData) // callee side
  async answerCall()
  declineCall()
  endCall()
  onRemoteStream(callback)   // called when remote media arrives
  onCallEnded(callback)      // called when remote hangs up
  onCallDeclined(callback)
  startSignalPolling()       // polls /ajax/webrtc_signal.php every 1s
  stopSignalPolling()
  addIceCandidate(candidate)
  sendIceCandidate(candidate)
}
```

**ICE Servers (STUN — free, no server needed)**:
```javascript
const ICE_SERVERS = [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun1.l.google.com:19302' },
  { urls: 'stun:stun2.l.google.com:19302' }
];
```
> **Note**: For LAN/intranet calls or if STUN fails (e.g., corporate NAT), add a free TURN relay. Initially STUN-only is fine for mobile ↔ mobile on 4G.

### 3. `/Surpriseville.co.in/assets/js/chat_engine.js` 🆕
**Purpose**: Standalone chat polling engine used on both portals.

**Replaces**: The inline `loadMessages()`, `sendMessage()`, `markAsRead()`, `checkTargetStatus()` scattered inside `order-chat.php`.

**Key Functions**:
```javascript
class ChatEngine {
  constructor(config)           // orderId, apiUrl, myType, myId, targetId
  start()                       // starts polling
  stop()
  sendMessage(text)
  markRead()
  onNewMessages(callback)       // called with array of new messages
  onStatusChange(callback)      // online/offline status
  renderMessage(msg)            // returns DOM element for a message
}
```

### 4. `/Surpriseville.co.in/ajax/chat_api.php` — MODIFIED (existing)
- Remove `__CALL_INVITE__` pattern from SQL
- Add `check_active_call` action (replaces `check_calls`)
- Use `call_sessions` table for call checking

### 5. `/partners.surpriseville.co.in/vendor/order-chat.php` — MAJOR REWRITE
- Import `chat_engine.js` and `webrtc_client.js`
- Clean HTML call UI (local + remote video elements)
- No Jitsi iframe dependency

### 6. `/Surpriseville.co.in/user/order-chat.php` 🆕 (or modify user-dashboard.php)
- Customer-side chat + call UI using same engines
- **Currently missing** — customers see calls only via popup; need a proper chat page

### 7. `/Surpriseville.co.in/database/migrate_webrtc.sql` 🆕
- Migration SQL with `call_sessions`, `webrtc_signals`, and `ALTER TABLE chat_messages`

---

## 🖥️ UI Changes

### Vendor Portal (`order-chat.php`) — Call UI

```
┌─────────────────────────────────────────────────────┐
│  ← Back  Chat with: Rahul Sharma  ● Online          │
│                    [📞 Audio] [📹 Video]             │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌──── Message Bubbles Area ────┐                   │
│  │  Customer: Hi, are you here? │                   │
│  │               Me: Yes! → ✓✓  │                   │
│  │  [📞 Call Log: Audio 2m 14s] │                   │
│  └──────────────────────────────┘                   │
│                                                     │
├─────────────────────────────────────────────────────┤
│  [ Type message...            ] [Send →]            │
└─────────────────────────────────────────────────────┘

On Active Video Call:
┌─────────────────────────────────────────────────────┐
│  ┌──────────────── Remote Video ──────────────────┐ │
│  │                                                │ │
│  │          Customer's face here                  │ │
│  │                              ┌──────────┐     │ │
│  │                              │ My Video │     │ │
│  │                              │ (small)  │     │ │
│  │                              └──────────┘     │ │
│  │  [🎤 Mute]  [📷 Cam Off]   [📵 End Call]     │ │
│  └────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

### Call Overlay HTML (in order-chat.php)

```html
<div id="callOverlay" style="display:none; position:fixed; inset:0; background:#000; z-index:20000;">
  <video id="remoteVideo" autoplay playsinline style="width:100%;height:100%;object-fit:cover;"></video>
  <video id="localVideo" autoplay muted playsinline style="position:absolute;bottom:100px;right:20px;width:120px;height:160px;border-radius:12px;object-fit:cover;border:2px solid #fff;"></video>
  <div id="callControls" style="position:absolute;bottom:30px;left:50%;transform:translateX(-50%);display:flex;gap:20px;">
    <button id="btnMute">🎤</button>
    <button id="btnCamOff">📷</button>
    <button id="btnEndCall" onclick="webrtcClient.endCall()">📵 End</button>
  </div>
  <div id="callStatus" style="position:absolute;top:20px;left:50%;transform:translateX(-50%);color:#fff;">Calling...</div>
</div>
```

### Incoming Call Banner (Customer — header.php)

```
┌─────────────────────────────────────────────────────┐
│  📞 Incoming Call from Vendor Name                  │
│     Order #1234 • Video Call                        │
│  [✗ Decline]              [✔ Accept]               │
│  ♪ (ringing tone playing)                           │
└─────────────────────────────────────────────────────┘
```

---

## 📋 Detailed File-by-File Change Plan

### Phase 1: Database Migration

#### [NEW] `Surpriseville.co.in/database/migrate_webrtc.sql`
```sql
-- Run on surpriseville_emp database

ALTER TABLE chat_messages
  ADD COLUMN msg_type ENUM('text','call_log') NOT NULL DEFAULT 'text' AFTER message,
  ADD COLUMN call_session_id INT(11) DEFAULT NULL AFTER msg_type;

CREATE TABLE call_sessions ( ... as above ... );
CREATE TABLE webrtc_signals ( ... as above ... );
```

---

### Phase 2: Backend Signaling API

#### [NEW] `Surpriseville.co.in/ajax/webrtc_signal.php`

Key logic pseudocode:
```php
<?php
require_once '../includes/config.php';
// CORS (same pattern as chat_api.php)
// Auth check (session user OR vendor_id POST from trusted origin)

$action = $_POST['action'] ?? '';

switch ($action) {
  case 'initiate_call':
    // INSERT call_sessions (status=ringing, sdp_offer)
    // INSERT webrtc_signals (type=offer, payload=sdp_offer)
    // INSERT chat_messages (msg_type=call_log, call_session_id, message='__CALL_STARTED__')
    // Return call_session_id
    break;

  case 'poll_signal':
    // For CALLEE: check call_sessions for incoming ringing call (60s window)
    // For CALLER: check call_sessions WHERE id=call_session_id for status change
    // Return { call: callData, signals: [newSignals] }
    break;

  case 'answer_call':
    // UPDATE call_sessions SET status=active, sdp_answer, answered_at=NOW()
    // INSERT webrtc_signals (type=answer, payload=sdp_answer)
    break;

  case 'send_ice':
    // INSERT webrtc_signals (type=ice_candidate, payload=iceJSON)
    break;

  case 'get_ice':
    // SELECT signals WHERE call_session_id=X AND from_type != myType AND is_delivered=0
    // UPDATE is_delivered=1
    // Return signals
    break;

  case 'end_call':
    // UPDATE call_sessions SET status=ended, ended_at=NOW(), duration_seconds=DIFF
    // INSERT webrtc_signals (type=end)
    break;

  case 'decline_call':
    // UPDATE call_sessions SET status=declined
    // INSERT webrtc_signals (type=decline)
    break;

  case 'get_call_history':
    // SELECT from call_sessions WHERE order_id=X ORDER BY created_at DESC
    break;
}
```

#### [MODIFY] `Surpriseville.co.in/ajax/chat_api.php`
- Remove `__CALL_INVITE__` pattern from ALL SQL WHERE clauses in `check_calls`/`check_notifications`
- Add `check_active_call` action using `call_sessions` table
- `check_notifications` now polls `call_sessions` for `status='ringing'` instead of message hack

---

### Phase 3: JavaScript Engines

#### [NEW] `Surpriseville.co.in/assets/js/webrtc_client.js`
Full class — see architecture above. Key points:
- Uses `RTCPeerConnection` with Google STUN servers
- Polls `/ajax/webrtc_signal.php` every 800ms for ICE + answer signals
- Fires callbacks: `onRemoteStream`, `onCallEnded`, `onCallDeclined`, `onCallConnected`
- Handles audio-only by passing `{ video: false, audio: true }` constraints
- Auto-stops polling when call ends

#### [NEW] `Surpriseville.co.in/assets/js/chat_engine.js`
Extracted from order-chat.php inline JS. Key points:
- Polls `chat_api.php` every 3s for new messages
- Handles call_log message rendering (shows call history bubble with duration)
- Heartbeat every 20s
- Status check every 10s

---

### Phase 4: UI Pages

#### [MAJOR REWRITE] `partners.surpriseville.co.in/vendor/order-chat.php`
```
What changes:
- REMOVE: All Jitsi code (joinCall, jitsiApi, jitsiContainer div)
- REMOVE: __CALL_INVITE__ message rendering
- REMOVE: Auto-join from URL param (?join_call=...)
- ADD: <script src="https://surpriseville.co.in/assets/js/webrtc_client.js">
- ADD: <script src="https://surpriseville.co.in/assets/js/chat_engine.js">
- ADD: WebRTC call overlay HTML (remoteVideo, localVideo, controls)
- ADD: startCall(type) → calls webrtcClient.initiateCall(type)
- ADD: Call status indicators (Ringing, Connected, Ended)
- ADD: Mute / Camera off buttons
- ADD: Call log bubble rendering using call_sessions data
```

#### [NEW] `Surpriseville.co.in/user/order-chat.php` OR modify `user-dashboard.php`
Customer-facing chat page identical in structure to vendor's — but uses `user` session vars.

#### [MODIFY] `Surpriseville.co.in/includes/header.php` (Customer)
```
What changes:
- REMOVE: window.location.href = '...join_call=' + call.message.replace(...)
- ADD: When accept pressed → open call inline using webrtcClient.handleIncomingCall(callData)
- ADD: <script src="/assets/js/webrtc_client.js"> in head (conditional on logged-in)
- MODIFY: pollForNotifications() → check call_sessions via check_active_call action instead of __CALL_INVITE__ pattern
```

#### [MODIFY] `partners.surpriseville.co.in/vendor/header.php`
```
What changes:
- REMOVE: join_call URL param redirect
- ADD: Inline call accept using webrtcClient
- ADD: <script src="https://surpriseville.co.in/assets/js/webrtc_client.js">
```

---

## 🔐 Security Considerations

| Risk | Mitigation |
|---|---|
| Anyone can POST fake signals | All webrtc_signal.php calls validate session OR trusted-origin vendor_id. Call session ownership checked on every action |
| SDP/ICE payload injection | Payloads stored as-is, never executed server-side. Only forwarded to verified other party |
| ICE candidate flooding | `send_ice` rate-limited to 50 calls per session (counter in call_sessions) |
| Old ringing calls stuck | Auto-expire: calls with `status=ringing` older than 60s → treated as `missed` |
| Phone number leakage in chat | Existing filter_content() regex stays in place |

---

## 🔁 Phased Execution Plan

| Phase | Task | Files | Priority |
|---|---|---|---|
| **1** | Run DB migration SQL | `migrate_webrtc.sql` | 🔴 Critical |
| **2** | Build `webrtc_signal.php` API | New file | 🔴 Critical |
| **3** | Modify `chat_api.php` | Remove CALL_INVITE hack | 🟡 High |
| **4** | Build `webrtc_client.js` | New file | 🔴 Critical |
| **5** | Build `chat_engine.js` | New file | 🟡 High |
| **6** | Rewrite vendor `order-chat.php` | Remove Jitsi | 🔴 Critical |
| **7** | Build customer `order-chat.php` | New file | 🟡 High |
| **8** | Patch vendor `header.php` | Inline accept call | 🟡 High |
| **9** | Patch customer `header.php` | Inline accept call | 🟡 High |
| **10** | Test full flow end-to-end | All | 🟢 Final |

---

## ✅ Verification Plan

### Automated
- `php -l` on all PHP files after changes

### Manual Testing Checklist
- [ ] Vendor sends text message → Customer receives within 3s
- [ ] Customer sends text message → Vendor receives within 3s
- [ ] Double-tick (read receipt) updates when message is read
- [ ] Vendor initiates Audio Call → Customer sees incoming call banner
- [ ] Customer accepts → WebRTC connects (P2P audio stream active)
- [ ] Vendor initiates Video Call → both see video streams
- [ ] Either side ends call → Both UIs return to chat
- [ ] Call log bubble appears in chat with duration
- [ ] Customer declines → Vendor sees "Call Declined" status
- [ ] Call times out (60s no answer) → Vendor sees "No Answer"
- [ ] Mute/Unmute button works
- [ ] Camera on/off button works
- [ ] Works in mobile browser (Chrome Android / Safari iOS)

---

## ⚠️ Open Questions for Review

> [!IMPORTANT]
> **TURN Server**: If STUN-only fails for some users (due to strict NAT/firewalls at hotels, offices), calls will not connect. STUN works for ~85% of connections. A TURN relay (like Cloudflare TURN or Metered.ca free tier) provides 100% connectivity. Do you want to add TURN server credentials?

> [!IMPORTANT]
> **Customer Chat Page**: Currently customers have no standalone chat page — they join calls from the header notification popup which redirects to `user-dashboard?action=view_order`. Should we create a dedicated `order-chat.php` on the customer portal too, or embed chat inline in the user dashboard?

> [!NOTE]
> **Old Call Messages**: Existing `__CALL_INVITE__:roomName` rows in `chat_messages` will become orphaned once the new system launches. They will render as plain text. We can add a one-time cleanup query or leave them as-is since they are historical.

> [!NOTE]
> **Progressive Rollout**: We can deploy chat improvements first (Phase 1-5) and then WebRTC calling (Phase 6-10) in a second pass, so chat is never broken during the transition.
