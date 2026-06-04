/**
 * webrtc_client.js
 * ================
 * Surpriseville.co.in — WebRTC Client Library
 *
 * Handles peer-to-peer audio/video calling between customers and vendors
 * via a PHP signaling back-end (webrtc_signal.php).
 *
 * Signaling flow:
 *   Caller  → initiateCall()  → sends SDP offer to server
 *   Callee  ← polling         ← detects incoming call
 *   Callee  → handleIncomingCall() → sends SDP answer to server
 *   Both    ← polling         ← exchange ICE candidates
 *   Either  → endCall() / declineCall()
 *
 * Author: Surpriseville Engineering
 * Phase:  4 — WebRTC Client
 */

'use strict';

/* ─────────────────────────────────────────────────────────────────────────────
 * ICE / STUN Server configuration
 * Google's public STUN servers — no auth required, suitable for production.
 * For TURN (NAT traversal behind symmetric NATs) add TURN credentials here.
 * ───────────────────────────────────────────────────────────────────────────*/
const SV_ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
    { urls: 'stun:stun2.l.google.com:19302' }
];

/* ─────────────────────────────────────────────────────────────────────────────
 * CORS-safe fetch helper
 * All requests must include credentials (session cookies) so the PHP back-end
 * can identify caller/callee from $_SESSION.
 * ───────────────────────────────────────────────────────────────────────────*/
/**
 * POST to a URL with FormData body, always including credentials (cookies).
 * @param {string}   url  - Target endpoint
 * @param {FormData} body - POST body
 * @returns {Promise<Object>} Parsed JSON response
 */
function svFetch(url, body) {
    return fetch(url, {
        method:      'POST',
        body:        body,
        credentials: 'include'          // send session cookies cross-origin
    }).then(function (r) {
        if (!r.ok) {
            throw new Error('HTTP ' + r.status + ' from ' + url);
        }
        return r.json();
    });
}

/* ─────────────────────────────────────────────────────────────────────────────
 * WebRTCClient
 * ───────────────────────────────────────────────────────────────────────────*/
/**
 * @class WebRTCClient
 *
 * Manages the full lifecycle of one WebRTC call session.
 * One instance should be created per page load (or per call if you prefer).
 *
 * @param {Object} config
 * @param {string}   config.signalApiUrl    - Full URL to webrtc_signal.php
 * @param {number}   config.orderId         - The order this call belongs to
 * @param {string}   config.myType          - 'user' | 'vendor'
 * @param {number}   config.myId            - My user_id or vendor_id
 * @param {string}   config.displayName     - My display name (used in UI)
 * @param {Function} config.onRemoteStream  - (stream: MediaStream) => void
 * @param {Function} config.onCallConnected - () => void
 * @param {Function} config.onCallEnded     - (durationSeconds: number) => void
 * @param {Function} config.onCallDeclined  - () => void
 * @param {Function} config.onIncomingCall  - (callData: Object) => void
 * @param {Function} config.onCallMissed    - () => void
 */
class WebRTCClient {

    constructor(config) {
        /* ── Validate required config ─────────────────────────────────────── */
        if (!config.signalApiUrl) throw new Error('WebRTCClient: signalApiUrl is required');
        if (!config.orderId)      throw new Error('WebRTCClient: orderId is required');
        if (!config.myType)       throw new Error('WebRTCClient: myType is required');
        if (!config.myId)         throw new Error('WebRTCClient: myId is required');

        this.config = Object.assign({
            onRemoteStream:  function () {},
            onCallConnected: function () {},
            onCallEnded:     function () {},
            onCallDeclined:  function () {},
            onIncomingCall:  function () {},
            onCallMissed:    function () {}
        }, config);

        /* ── Instance state ───────────────────────────────────────────────── */
        /** @type {RTCPeerConnection|null} */
        this.peerConnection   = null;

        /** @type {MediaStream|null} */
        this.localStream      = null;

        /** @type {number|null} The DB id of the current call_sessions row */
        this.callSessionId    = null;

        /** @type {'audio'|'video'} */
        this.callType         = 'audio';

        /** @type {number|null} setInterval handle for signal polling */
        this.pollingInterval  = null;

        /** @type {Array} ICE candidates buffered before remote SDP is set */
        this.iceQueue         = [];

        /** @type {boolean} */
        this.isPolling        = false;

        /** @type {boolean} true if we placed the call, false if we received it */
        this.isCaller         = false;

        /** @type {boolean} prevents firing onCallConnected more than once */
        this._connectedFired  = false;

        /** @type {number|null} setTimeout handle for ringing timeout */
        this._ringTimeout     = null;

        /** @type {boolean} prevents duplicate cleanup / end handling */
        this._ended           = false;
    }

    /* ══════════════════════════════════════════════════════════════════════
     * PUBLIC — CALLER SIDE
     * ════════════════════════════════════════════════════════════════════*/

    /**
     * Place an outgoing call.
     * Creates the local media stream, RTCPeerConnection, SDP offer, and
     * posts it to the server. Then starts signal polling.
     *
     * @param {'audio'|'video'} callType
     * @returns {Promise<void>}
     */
    async initiateCall(callType) {
        this.callType  = callType || 'audio';
        this.isCaller  = true;
        this._ended    = false;
        this.iceQueue  = [];

        /* 1. Acquire local media */
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: this.callType === 'video'
            });
        } catch (err) {
            console.error('[WebRTCClient] getUserMedia failed:', err);
            throw err;
        }

        /* 2. Show local preview if a <video id="localVideo"> exists */
        const localVideo = document.getElementById('localVideo');
        if (localVideo && this.callType === 'video') {
            localVideo.srcObject = this.localStream;
            localVideo.muted     = true; // avoid echo
            localVideo.play().catch(function () {});
        }

        /* 3. Create PeerConnection and wire up events */
        this._createPeerConnection();

        /* 4. Add local tracks to the connection */
        this.localStream.getTracks().forEach((track) => {
            this.peerConnection.addTrack(track, this.localStream);
        });

        /* 5. Create SDP offer and set as local description */
        let offer;
        try {
            offer = await this.peerConnection.createOffer();
            await this.peerConnection.setLocalDescription(offer);
        } catch (err) {
            console.error('[WebRTCClient] createOffer failed:', err);
            throw err;
        }

        /* 6. POST offer + call intent to server */
        const body = new FormData();
        body.append('action',      'initiate_call');
        body.append('order_id',    this.config.orderId);
        body.append('call_type',   this.callType);
        body.append('sdp_offer',   JSON.stringify(offer));

        /* Identify as vendor if applicable */
        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        let res;
        try {
            res = await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            console.error('[WebRTCClient] initiate_call POST failed:', err);
            throw err;
        }

        if (!res.success) {
            throw new Error('[WebRTCClient] initiate_call failed: ' + (res.message || 'unknown'));
        }

        this.callSessionId = res.call_session_id;
        console.log('[WebRTCClient] Call initiated, session id:', this.callSessionId);

        /* 7. Begin polling for the callee's answer / ICE candidates */
        this._startPolling();

        /* 8. 60-second ringing timeout → missed call */
        this._ringTimeout = setTimeout(() => {
            this._missedCall();
        }, 60000);
    }

    /* ══════════════════════════════════════════════════════════════════════
     * PUBLIC — CALLEE SIDE
     * ════════════════════════════════════════════════════════════════════*/

    /**
     * Accept an incoming call.
     * Called when the user clicks "Accept" on the incoming call notification.
     *
     * @param {Object} callData - The call_sessions row returned by the poll API
     * @returns {Promise<void>}
     */
    async handleIncomingCall(callData) {
        this.callSessionId  = callData.id;
        this.callType       = callData.call_type || 'audio';
        this.isCaller       = false;
        this._ended         = false;
        this.iceQueue       = [];
        this._connectedFired = false;

        /* 1. Acquire local media */
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: this.callType === 'video'
            });
        } catch (err) {
            console.error('[WebRTCClient] getUserMedia (callee) failed:', err);
            throw err;
        }

        /* 2. Show local preview */
        const localVideo = document.getElementById('localVideo');
        if (localVideo && this.callType === 'video') {
            localVideo.srcObject = this.localStream;
            localVideo.muted     = true;
            localVideo.play().catch(function () {});
        }

        /* 3. Create PeerConnection */
        this._createPeerConnection();

        /* 4. Add local tracks */
        this.localStream.getTracks().forEach((track) => {
            this.peerConnection.addTrack(track, this.localStream);
        });

        /* 5. Set remote description from caller's SDP offer */
        let offerSdp;
        try {
            offerSdp = JSON.parse(callData.sdp_offer);
        } catch (e) {
            console.error('[WebRTCClient] Failed to parse sdp_offer:', e);
            throw new Error('Invalid sdp_offer in callData');
        }

        try {
            await this.peerConnection.setRemoteDescription(
                new RTCSessionDescription(offerSdp)
            );
        } catch (err) {
            console.error('[WebRTCClient] setRemoteDescription (offer) failed:', err);
            throw err;
        }

        /* 6. Flush buffered ICE candidates that arrived before remote SDP */
        await this._flushIceQueue();

        /* 7. Create SDP answer */
        let answer;
        try {
            answer = await this.peerConnection.createAnswer();
            await this.peerConnection.setLocalDescription(answer);
        } catch (err) {
            console.error('[WebRTCClient] createAnswer failed:', err);
            throw err;
        }

        /* 8. POST answer to server */
        const body = new FormData();
        body.append('action',          'answer_call');
        body.append('call_session_id', this.callSessionId);
        body.append('sdp_answer',      JSON.stringify(answer));

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        let res;
        try {
            res = await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            console.error('[WebRTCClient] answer_call POST failed:', err);
            throw err;
        }

        if (!res.success) {
            throw new Error('[WebRTCClient] answer_call failed: ' + (res.message || 'unknown'));
        }

        console.log('[WebRTCClient] Call answered, session:', this.callSessionId);

        /* 9. Start ICE + status polling */
        this._startPolling();
    }

    /* ══════════════════════════════════════════════════════════════════════
     * PUBLIC — CALL CONTROL
     * ════════════════════════════════════════════════════════════════════*/

    /**
     * Decline an incoming call without answering.
     *
     * @param {number} callSessionId - The call session to decline
     * @returns {Promise<void>}
     */
    async declineCall(callSessionId) {
        const sid = callSessionId || this.callSessionId;
        if (!sid) return;

        const body = new FormData();
        body.append('action',          'decline_call');
        body.append('call_session_id', sid);

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        try {
            await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            console.warn('[WebRTCClient] decline_call POST failed:', err);
        }

        /* Notify local UI that call was declined */
        this.config.onCallDeclined();
        this._cleanup();
    }

    /**
     * End the active call (works for both caller and callee).
     * Sends the end signal, cleans up, and fires onCallEnded with duration.
     *
     * @returns {Promise<void>}
     */
    async endCall() {
        if (this._ended) return;
        this._ended = true;

        const sid = this.callSessionId;
        if (!sid) {
            this._cleanup();
            return;
        }

        const body = new FormData();
        body.append('action',          'end_call');
        body.append('call_session_id', sid);

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        let duration = 0;
        try {
            const res = await svFetch(this.config.signalApiUrl, body);
            if (res.success && res.duration_seconds !== undefined) {
                duration = parseInt(res.duration_seconds, 10) || 0;
            }
        } catch (err) {
            console.warn('[WebRTCClient] end_call POST failed:', err);
        }

        this._cleanup();
        this.config.onCallEnded(duration);
    }

    /**
     * Toggle the local microphone on/off.
     * @returns {boolean} new muted state (true = muted)
     */
    toggleMute() {
        if (!this.localStream) return false;
        const audioTrack = this.localStream.getAudioTracks()[0];
        if (!audioTrack) return false;
        audioTrack.enabled = !audioTrack.enabled;
        console.log('[WebRTCClient] Mic', audioTrack.enabled ? 'unmuted' : 'muted');
        return !audioTrack.enabled; // true = muted
    }

    /**
     * Toggle the local camera on/off (video calls only).
     * @returns {boolean} new state (true = camera off)
     */
    toggleCamera() {
        if (!this.localStream) return false;
        const videoTrack = this.localStream.getVideoTracks()[0];
        if (!videoTrack) return false;
        videoTrack.enabled = !videoTrack.enabled;
        console.log('[WebRTCClient] Camera', videoTrack.enabled ? 'on' : 'off');
        return !videoTrack.enabled; // true = camera off
    }

    /* ══════════════════════════════════════════════════════════════════════
     * PRIVATE HELPERS
     * ════════════════════════════════════════════════════════════════════*/

    /**
     * Create and configure a new RTCPeerConnection.
     * Sets up the standard event handlers for ICE and remote tracks.
     * @private
     */
    _createPeerConnection() {
        this.peerConnection = new RTCPeerConnection({
            iceServers: SV_ICE_SERVERS
        });

        /* ICE candidate gathered locally → relay to remote peer via server */
        this.peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                this._sendIce(event.candidate);
            }
        };

        /* ICE connection state change — useful for debugging */
        this.peerConnection.oniceconnectionstatechange = () => {
            const state = this.peerConnection ? this.peerConnection.iceConnectionState : 'closed';
            console.log('[WebRTCClient] ICE state:', state);

            if (state === 'failed') {
                console.warn('[WebRTCClient] ICE failed — media path could not be established');
            }
        };

        /* Remote track arrived — attach to <video id="remoteVideo"> */
        this.peerConnection.ontrack = (event) => {
            const stream = event.streams[0];
            if (!stream) return;

            const remoteVideo = document.getElementById('remoteVideo');
            if (remoteVideo) {
                remoteVideo.srcObject = stream;
                remoteVideo.play().catch(function () {});
            }

            /* Notify the application layer */
            this.config.onRemoteStream(stream);
        };
    }

    /**
     * Send a locally gathered ICE candidate to the signaling server.
     * The server stores it for the remote peer to consume via polling.
     *
     * @param {RTCIceCandidate} candidate
     * @private
     */
    async _sendIce(candidate) {
        /* Ignore empty/null candidates (end-of-candidates signal) */
        if (!candidate || !candidate.candidate) return;

        const body = new FormData();
        body.append('action',          'send_ice');
        body.append('call_session_id', this.callSessionId);
        body.append('ice_candidate',   JSON.stringify(candidate));

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        try {
            await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            /* Non-fatal — ICE re-gathering will handle transient failures */
            console.warn('[WebRTCClient] _sendIce failed:', err);
        }
    }

    /**
     * Begin polling the server for signals (SDP answer, ICE candidates,
     * end/decline events) and for incoming call detection on the callee side.
     *
     * Polling interval: 800 ms — low enough for responsive ICE exchange,
     * high enough to not overload the server.
     *
     * @private
     */
    _startPolling() {
        if (this.isPolling) return;
        this.isPolling = true;

        this.pollingInterval = setInterval(async () => {
            await this._doPoll();
        }, 800);
    }

    /**
     * Execute a single poll request.
     * @private
     */
    async _doPoll() {
        const body = new FormData();
        body.append('action',   'poll_signal');
        body.append('order_id', this.config.orderId);

        if (this.callSessionId) {
            body.append('call_session_id', this.callSessionId);
        }

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        let res;
        try {
            res = await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            /* Transient network error — silently retry next tick */
            console.warn('[WebRTCClient] poll_signal failed:', err);
            return;
        }

        if (!res.success) return;

        /* ── Process signals array ─────────────────────────────────────── */
        if (Array.isArray(res.signals)) {
            for (const signal of res.signals) {
                await this._handleSignal(signal);
            }
        }

        /* ── Handle call_status field ─────────────────────────────────── */
        if (res.call_status === 'active' && !this._connectedFired) {
            this._connectedFired = true;

            /* Cancel the ringing timeout — call was answered */
            if (this._ringTimeout) {
                clearTimeout(this._ringTimeout);
                this._ringTimeout = null;
            }

            this.config.onCallConnected();
        }

        /* ── Detect incoming call on callee side (when no session active) ── */
        if (
            !this.isCaller &&
            !this.callSessionId &&
            res.incoming_call &&
            typeof this.config.onIncomingCall === 'function'
        ) {
            this.config.onIncomingCall(res.incoming_call);
        }
    }

    /**
     * Process a single signal object from the polling response.
     *
     * @param {Object} signal - A webrtc_signals row from the server
     * @private
     */
    async _handleSignal(signal) {
        const type = signal.signal_type || signal.type;

        switch (type) {

            case 'answer': {
                /* Caller received callee's SDP answer */
                if (!this.peerConnection) break;
                let answerSdp;
                try {
                    answerSdp = JSON.parse(signal.payload);
                } catch (e) {
                    console.error('[WebRTCClient] Failed to parse answer payload:', e);
                    break;
                }
                try {
                    await this.peerConnection.setRemoteDescription(
                        new RTCSessionDescription(answerSdp)
                    );
                    console.log('[WebRTCClient] Remote description (answer) set');
                    /* Now we can apply buffered ICE candidates */
                    await this._flushIceQueue();
                } catch (err) {
                    console.error('[WebRTCClient] setRemoteDescription (answer) failed:', err);
                }
                break;
            }

            case 'ice_candidate': {
                /* Remote ICE candidate — add it or buffer it */
                let candidate;
                try {
                    candidate = JSON.parse(signal.payload);
                } catch (e) {
                    console.error('[WebRTCClient] Failed to parse ICE candidate payload:', e);
                    break;
                }

                const hasRemote =
                    this.peerConnection &&
                    this.peerConnection.remoteDescription &&
                    this.peerConnection.remoteDescription.type;

                if (hasRemote) {
                    try {
                        await this.peerConnection.addIceCandidate(
                            new RTCIceCandidate(candidate)
                        );
                    } catch (err) {
                        console.warn('[WebRTCClient] addIceCandidate failed:', err);
                    }
                } else {
                    /* Buffer until remote description is available */
                    this.iceQueue.push(candidate);
                }
                break;
            }

            case 'end': {
                /* Remote peer ended the call */
                if (this._ended) break;
                this._ended = true;

                let duration = 0;
                try {
                    const parsed = JSON.parse(signal.payload);
                    duration = parseInt(parsed.duration_seconds, 10) || 0;
                } catch (e) { /* payload may be empty */ }

                this._cleanup();
                this.config.onCallEnded(duration);
                break;
            }

            case 'decline': {
                /* Remote peer declined the call */
                if (this._ended) break;
                this._ended = true;
                this._cleanup();
                this.config.onCallDeclined();
                break;
            }

            default:
                /* Unknown signal type — ignore gracefully */
                break;
        }
    }

    /**
     * Apply all ICE candidates that were buffered while waiting for
     * the remote SDP description to be set.
     * @private
     */
    async _flushIceQueue() {
        if (!this.peerConnection) return;

        console.log('[WebRTCClient] Flushing ICE queue:', this.iceQueue.length, 'candidates');

        for (const candidate of this.iceQueue) {
            try {
                await this.peerConnection.addIceCandidate(
                    new RTCIceCandidate(candidate)
                );
            } catch (err) {
                console.warn('[WebRTCClient] Queued addIceCandidate failed:', err);
            }
        }

        this.iceQueue = [];
    }

    /**
     * Handle a ringing timeout (callee never answered within 60 s).
     * Only the caller side triggers this.
     * @private
     */
    async _missedCall() {
        /* Only act if we're still in a ringing state */
        if (this._ended || !this.callSessionId) return;
        this._ended = true;

        console.log('[WebRTCClient] Call missed (ringing timeout)');

        /* Tell the server to close the session */
        const body = new FormData();
        body.append('action',          'end_call');
        body.append('call_session_id', this.callSessionId);

        if (this.config.myType === 'vendor') {
            body.append('vendor_id', this.config.myId);
        }

        try {
            await svFetch(this.config.signalApiUrl, body);
        } catch (err) {
            console.warn('[WebRTCClient] _missedCall end_call POST failed:', err);
        }

        this.config.onCallMissed();
        this._cleanup();
    }

    /**
     * Clean up all resources: polling, PeerConnection, media tracks, DOM elements.
     * Safe to call multiple times.
     * @private
     */
    _cleanup() {
        /* Stop polling */
        if (this.pollingInterval !== null) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
        this.isPolling = false;

        /* Cancel ringing timeout if still pending */
        if (this._ringTimeout !== null) {
            clearTimeout(this._ringTimeout);
            this._ringTimeout = null;
        }

        /* Close peer connection */
        if (this.peerConnection) {
            try {
                this.peerConnection.close();
            } catch (e) { /* ignore */ }
            this.peerConnection = null;
        }

        /* Stop all local media tracks (release camera/microphone) */
        if (this.localStream) {
            this.localStream.getTracks().forEach(function (track) {
                track.stop();
            });
            this.localStream = null;
        }

        /* Clear DOM video elements */
        const localVideo  = document.getElementById('localVideo');
        const remoteVideo = document.getElementById('remoteVideo');
        if (localVideo)  { localVideo.srcObject  = null; }
        if (remoteVideo) { remoteVideo.srcObject = null; }

        /* Reset session state */
        this.callSessionId   = null;
        this.iceQueue        = [];
        this._connectedFired = false;

        console.log('[WebRTCClient] Cleanup complete');
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Global export — makes WebRTCClient available in plain <script> tags as well
 * as ES module imports.
 * ───────────────────────────────────────────────────────────────────────────*/
window.WebRTCClient = WebRTCClient;
window.svFetch      = svFetch; // exported for use in chat_engine.js if needed
