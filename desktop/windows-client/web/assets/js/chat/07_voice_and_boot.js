async function loadDiscordStatus() {
    const fd = new FormData(); fd.append('action', 'discord_status');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    const linked   = document.getElementById('discordLinked');
    const unlinked = document.getElementById('discordUnlinked');
    if (!linked || !unlinked) return;
    if (data.linked) {
        document.getElementById('discordTagDisplay').textContent = data.discord_tag;
        linked.style.display   = 'flex';
        unlinked.style.display = 'none';
    } else {
        linked.style.display   = 'none';
        unlinked.style.display = 'block';
    }
}

async function redeemDiscordSync() {
    const token = document.getElementById('discordSyncToken')?.value.trim().toUpperCase();
    const msg   = document.getElementById('discordSyncMsg');
    if (!token) { msg.style.color = 'var(--error)'; msg.textContent = 'Enter your sync code'; return; }

    msg.style.color   = 'var(--text-muted)';
    msg.textContent   = 'Linking...';

    const fd = new FormData(); fd.append('action', 'discord_sync'); fd.append('token', token);
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();

    if (data.success) {
        msg.style.color = 'var(--success)';
        msg.textContent = `✓ Linked to ${data.discord_tag}`;
        setTimeout(loadDiscordStatus, 1000);
    } else {
        msg.style.color = 'var(--error)';
        msg.textContent = data.error || 'Failed — try again';
    }
}

async function discordUnlink() {
    if (!confirm('Unlink your Discord account?')) return;
    const fd = new FormData(); fd.append('action', 'discord_unlink');
    await fetch('/api/auth.php', { method: 'POST', body: fd });
    loadDiscordStatus();
}

function setProfileStatus(id, message, color = 'var(--text-muted)') {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.color = color;
    el.textContent = message;
}

async function loadDataDeletionStatus() {
    const requestBtn = document.getElementById('requestDeletionBtn');
    const deleteBtn = document.getElementById('deleteSavedChatBtn');
    if (requestBtn) requestBtn.disabled = false;
    if (deleteBtn) deleteBtn.disabled = false;
    setProfileStatus('deleteSavedChatMsg', 'Delete saved chat history from this account immediately.');
    setProfileStatus('requestDeletionMsg', 'No full deletion request is currently pending.');

    try {
        const fd = new FormData();
        fd.append('action', 'get_data_deletion_status');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to load deletion status');
        }

        const request = data.request || null;
        if (request && request.status === 'pending') {
            if (requestBtn) requestBtn.disabled = true;
            const createdAt = request.created_at ? new Date(String(request.created_at).replace(' ', 'T')) : null;
            const when = createdAt && !Number.isNaN(createdAt.getTime())
                ? createdAt.toLocaleString()
                : 'recently';
            setProfileStatus('requestDeletionMsg', 'A full deletion request is already pending from ' + when + '.', '#f59e0b');
            return;
        }

        if (request && request.status && request.status !== 'pending') {
            setProfileStatus('requestDeletionMsg', 'Latest deletion request status: ' + request.status + '.', 'var(--text-muted)');
        }
    } catch (e) {
        setProfileStatus('requestDeletionMsg', 'Could not load deletion request status.', 'var(--error)');
    }
}

async function deleteSavedChatData() {
    const step = await openAuthPrompt({
        title: 'Delete Saved Chats',
        subtitle: 'Type DELETE to permanently remove synced chat history tied to this account.',
        placeholder: 'DELETE',
        maxLength: 12,
        type: 'text',
        submitText: 'Delete Chats',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'DELETE') {
        showToast('Type DELETE to confirm', 'error');
        return;
    }

    const deleteBtn = document.getElementById('deleteSavedChatBtn');
    if (deleteBtn) deleteBtn.disabled = true;
    setProfileStatus('deleteSavedChatMsg', 'Deleting saved chat history...', 'var(--text-muted)');

    try {
        const fd = new FormData();
        fd.append('action', 'delete_saved_chat_data');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to delete saved chat history');
        }

        convCache = [];
        msgCache = {};
        setActiveConvState(null);
        persistBackupFromCache();
        await newConversation();
        renderChat();

        const summary = 'Deleted ' + (data.deleted_conversations || 0) + ' conversations and ' + (data.deleted_logs || 0) + ' stored chat logs.';
        setProfileStatus('deleteSavedChatMsg', summary, 'var(--success)');
        showToast('Saved chat history deleted', 'success');
    } catch (e) {
        setProfileStatus('deleteSavedChatMsg', e.message || 'Failed to delete saved chat history.', 'var(--error)');
        showToast(e.message || 'Failed to delete saved chat history', 'error');
    } finally {
        if (deleteBtn) deleteBtn.disabled = false;
    }
}

async function requestDataDeletion() {
    const step = await openAuthPrompt({
        title: 'Request Full Data Deletion',
        subtitle: 'Type REQUEST to submit a manual deletion request for your full account data.',
        placeholder: 'REQUEST',
        maxLength: 12,
        type: 'text',
        submitText: 'Submit Request',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'REQUEST') {
        showToast('Type REQUEST to confirm', 'error');
        return;
    }

    const requestBtn = document.getElementById('requestDeletionBtn');
    if (requestBtn) requestBtn.disabled = true;
    setProfileStatus('requestDeletionMsg', 'Submitting deletion request...', 'var(--text-muted)');

    try {
        const fd = new FormData();
        fd.append('action', 'request_data_deletion');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data?.success) {
            throw new Error(data?.error || 'Failed to submit deletion request');
        }

        if (data.already_requested) {
            setProfileStatus('requestDeletionMsg', 'A full deletion request is already pending.', '#f59e0b');
            showToast('Deletion request already pending', 'error');
            return;
        }

        setProfileStatus('requestDeletionMsg', 'Full data deletion requested. Support will process it manually.', 'var(--success)');
        showToast('Deletion request submitted', 'success');
    } catch (e) {
        if (requestBtn) requestBtn.disabled = false;
        setProfileStatus('requestDeletionMsg', e.message || 'Failed to submit deletion request.', 'var(--error)');
        showToast(e.message || 'Failed to submit deletion request', 'error');
        return;
    }
}

async function load2FAStatus() {
    const el = document.getElementById('twoFAStatusMsg');
    if (!el) return;
    try {
        const fd = new FormData();
        fd.append('action', 'get_2fa_status');
        const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
        if (!data.success) {
            el.textContent = 'Unable to load 2FA status';
            return;
        }
        if (!data.enabled) {
            el.textContent = '2FA is currently disabled';
            return;
        }
        const method = data.method === 'yubikey' ? 'YubiKey' : 'Authenticator App';
        const remaining = Number(data.recovery_codes_remaining || 0);
        el.textContent = '2FA enabled via ' + method + ' · Backup codes left: ' + remaining;
    } catch (e) {
        el.textContent = 'Unable to load 2FA status';
    }
}

async function setupTotp2FA() {
    const setupFd = new FormData();
    setupFd.append('action', 'setup_2fa_totp');
    const setup = await (await fetch('/api/auth.php', { method: 'POST', body: setupFd })).json();
    if (!setup.success) {
        showToast(setup.error || 'Failed to start TOTP setup', 'error');
        return;
    }
    const step = await openAuthPrompt({
        title: 'Authenticator Setup',
        subtitle: 'Add this key to your authenticator app:\n' + setup.secret + '\nThen enter the 6-digit code.',
        placeholder: '123456',
        maxLength: 6,
        type: 'text',
        submitText: 'Enable 2FA',
        requiredError: 'Authenticator code is required'
    });
    if (step.cancelled) return;
    const enableFd = new FormData();
    enableFd.append('action', 'enable_2fa_totp');
    enableFd.append('code', step.value.trim());
    const enabled = await (await fetch('/api/auth.php', { method: 'POST', body: enableFd })).json();
    if (enabled.success) {
        if (Array.isArray(enabled.recovery_codes) && enabled.recovery_codes.length) {
            showRecoveryCodesModal(enabled.recovery_codes);
        }
        showToast('Authenticator app 2FA enabled', 'success');
        load2FAStatus();
    } else {
        showToast(enabled.error || 'Failed to enable authenticator app 2FA', 'error');
    }
}

async function registerYubiKey2FA() {
    const step = await openAuthPrompt({
        title: 'Enable YubiKey',
        subtitle: 'Touch your YubiKey and paste the generated OTP.',
        placeholder: 'YubiKey OTP',
        maxLength: 64,
        type: 'text',
        submitText: 'Enable 2FA',
        requiredError: 'YubiKey OTP is required'
    });
    if (step.cancelled) return;
    const fd = new FormData();
    fd.append('action', 'register_2fa_yubikey');
    fd.append('yubikey_otp', step.value.trim());
    fd.append('label', 'Primary YubiKey');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success) {
        if (Array.isArray(data.recovery_codes) && data.recovery_codes.length) {
            showRecoveryCodesModal(data.recovery_codes);
        }
        showToast('YubiKey 2FA enabled', 'success');
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to enable YubiKey 2FA', 'error');
    }
}

async function regenerateRecoveryCodes() {
    const step = await openAuthPrompt({
        title: 'Generate New Backup Codes',
        subtitle: 'Type NEW to replace all existing unused recovery codes.',
        placeholder: 'NEW',
        maxLength: 8,
        type: 'text',
        submitText: 'Generate',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled) return;
    if (step.value.trim().toUpperCase() !== 'NEW') {
        showToast('Type NEW to confirm', 'error');
        return;
    }

    const fd = new FormData();
    fd.append('action', 'regenerate_recovery_codes');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success && Array.isArray(data.recovery_codes)) {
        showRecoveryCodesModal(data.recovery_codes);
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to generate recovery codes', 'error');
    }
}

async function disable2FA() {
    const step = await openAuthPrompt({
        title: 'Disable Two-Factor Authentication',
        subtitle: 'Type DISABLE to confirm turning off 2FA.',
        placeholder: 'DISABLE',
        maxLength: 10,
        type: 'text',
        submitText: 'Disable',
        requiredError: 'Confirmation is required'
    });
    if (step.cancelled || step.value.trim().toUpperCase() !== 'DISABLE') {
        if (!step.cancelled) showToast('Type DISABLE to confirm', 'error');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'disable_2fa');
    const data = await (await fetch('/api/auth.php', { method: 'POST', body: fd })).json();
    if (data.success) {
        showToast('2FA disabled', 'success');
        load2FAStatus();
    } else {
        showToast(data.error || 'Failed to disable 2FA', 'error');
    }
}

// ── VOICE MODE ──
let voiceRecognizer = null;
let voiceState = 'idle'; // idle | listening | processing | speaking
let serverTtsAvailable = null; // null = unknown, true/false after first probe
let voiceAudio = null;
let voiceRequestController = null;
let voiceAutoRestartBlockUntil = 0;
let voiceSessionActive = false;
let voicePlaybackToken = 0;
let voiceStreamRunId = 0;
let voiceSpeechQueue = Promise.resolve();
let voiceSpeechRunId = 0;
let voiceSpeechBuffer = '';
let serverTtsRetryAt = 0;
let serverTtsFailureCount = 0;
let voiceHumanOnlyMode = true;
const voiceLabEnabled = !!window.LYRALINK_DEV_USER;
const voiceLabSettings = {
    liveLoop: false,
    deepThinking: true,
    showThinking: true,
};

function voiceCanTryServerTts() {
    if (serverTtsAvailable !== false) {
        return true;
    }
    return Date.now() >= serverTtsRetryAt;
}

function voiceMarkServerTtsSuccess() {
    serverTtsAvailable = true;
    serverTtsFailureCount = 0;
    serverTtsRetryAt = 0;
}

function voiceMarkServerTtsFailure() {
    serverTtsAvailable = false;
    serverTtsFailureCount += 1;
    const exp = Math.min(5, Math.max(0, serverTtsFailureCount - 1));
    const backoffMs = Math.min(30000, 1500 * Math.pow(2, exp));
    serverTtsRetryAt = Date.now() + backoffMs;
}

function voiceCanAutoRestart() {
    return Date.now() > voiceAutoRestartBlockUntil;
}

function voiceBlockAutoRestart(ms = 1400) {
    voiceAutoRestartBlockUntil = Date.now() + Math.max(250, ms);
}

function voiceShouldAutoListen() {
    const backdrop = document.getElementById('voicePanelBackdrop');
    return (voiceSessionActive || (voiceLabEnabled && voiceLabSettings.liveLoop))
        && backdrop
        && backdrop.style.display !== 'none'
        && voiceState === 'idle'
        && voiceCanAutoRestart();
}

function voiceInitLabSettings() {
    if (!voiceLabEnabled) return;
    const readBool = (key, fallback) => {
        const raw = localStorage.getItem(key);
        if (raw === null) return fallback;
        return raw === '1';
    };

    voiceLabSettings.liveLoop = readBool('lyralink_voice_live_loop', false);
    voiceLabSettings.deepThinking = readBool('lyralink_voice_deep_thinking', true);
    voiceLabSettings.showThinking = readBool('lyralink_voice_show_thinking', true);

    const liveToggle = document.getElementById('voiceLiveLoopToggle');
    const deepToggle = document.getElementById('voiceDeepThinkingToggle');
    const showToggle = document.getElementById('voiceShowThinkingToggle');
    if (liveToggle) liveToggle.checked = voiceLabSettings.liveLoop;
    if (deepToggle) deepToggle.checked = voiceLabSettings.deepThinking;
    if (showToggle) showToggle.checked = voiceLabSettings.showThinking;
}

function voiceApplyLabSettings() {
    if (!voiceLabEnabled) return;
    const liveToggle = document.getElementById('voiceLiveLoopToggle');
    const deepToggle = document.getElementById('voiceDeepThinkingToggle');
    const showToggle = document.getElementById('voiceShowThinkingToggle');

    voiceLabSettings.liveLoop = !!(liveToggle && liveToggle.checked);
    voiceLabSettings.deepThinking = !!(deepToggle && deepToggle.checked);
    voiceLabSettings.showThinking = !!(showToggle && showToggle.checked);

    localStorage.setItem('lyralink_voice_live_loop', voiceLabSettings.liveLoop ? '1' : '0');
    localStorage.setItem('lyralink_voice_deep_thinking', voiceLabSettings.deepThinking ? '1' : '0');
    localStorage.setItem('lyralink_voice_show_thinking', voiceLabSettings.showThinking ? '1' : '0');

    const thinkingWrap = document.getElementById('voiceThinkingWrap');
    if (thinkingWrap && !voiceLabSettings.showThinking) {
        thinkingWrap.style.display = 'none';
        thinkingWrap.textContent = '';
    }
}

function voiceCancelInFlight() {
    voiceStreamRunId += 1;
    if (voiceRequestController) {
        try { voiceRequestController.abort(); } catch(_) {}
        voiceRequestController = null;
    }
}

function voiceStopPlayback() {
    voicePlaybackToken += 1;
    voiceSpeechRunId += 1;
    voiceSpeechQueue = Promise.resolve();
    voiceSpeechBuffer = '';
    if (voiceAudio) {
        try { voiceAudio.pause(); } catch(_) {}
        voiceAudio = null;
    }
    if (window.speechSynthesis) {
        try { window.speechSynthesis.cancel(); } catch(_) {}
    }
}

async function voiceFetchWithTimeout(url, options = {}, timeoutMs = 90000) {
    const controller = new AbortController();
    const externalSignal = options?.signal || null;
    const onExternalAbort = () => controller.abort();

    if (externalSignal && typeof externalSignal.addEventListener === 'function') {
        if (externalSignal.aborted) {
            controller.abort();
        } else {
            externalSignal.addEventListener('abort', onExternalAbort, { once: true });
        }
    }

    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const { signal: _dropSignal, ...rest } = options || {};
        return await fetch(url, { ...rest, signal: controller.signal });
    } finally {
        clearTimeout(timer);
        if (externalSignal && typeof externalSignal.removeEventListener === 'function') {
            externalSignal.removeEventListener('abort', onExternalAbort);
        }
    }
}

function voiceNormalizeSpeechText(text) {
    return String(text || '')
        .replace(/```[\s\S]*?```/g, ' code block ')
        .replace(/`([^`]+)`/g, '$1')
        .replace(/\*\*([^*]+)\*\*/g, '$1')
        .replace(/\*([^*]+)\*/g, '$1')
        .replace(/#+\s/g, '')
        .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
        .replace(/\n{2,}/g, '. ')
        .replace(/\n/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function voiceChunkSpeechText(text, maxLen = 240) {
    const normalized = voiceNormalizeSpeechText(text);
    if (!normalized) return [];

    const chunks = [];
    const sentenceParts = normalized.split(/(?<=[.!?])\s+/);
    let buf = '';

    for (const partRaw of sentenceParts) {
        const part = String(partRaw || '').trim();
        if (!part) continue;
        if (!buf) {
            buf = part;
            continue;
        }
        if ((buf.length + 1 + part.length) <= maxLen) {
            buf += ' ' + part;
        } else {
            chunks.push(buf);
            buf = part;
        }
    }
    if (buf) chunks.push(buf);

    if (chunks.length === 0) {
        return [normalized.slice(0, maxLen)];
    }
    return chunks;
}

function voicePlayAudioBlob(blob, token) {
    return new Promise((resolve) => {
        if (!blob || token !== voicePlaybackToken) {
            resolve(false);
            return;
        }
        const url = URL.createObjectURL(blob);
        const audio = new Audio(url);
        voiceAudio = audio;
        audio.onended = () => {
            URL.revokeObjectURL(url);
            if (token === voicePlaybackToken) {
                voiceAudio = null;
            }
            resolve(true);
        };
        audio.onerror = () => {
            URL.revokeObjectURL(url);
            if (token === voicePlaybackToken) {
                voiceAudio = null;
            }
            resolve(false);
        };
        audio.play().catch(() => {
            URL.revokeObjectURL(url);
            if (token === voicePlaybackToken) {
                voiceAudio = null;
            }
            resolve(false);
        });
    });
}

function voiceRenderThinking(thinkingText) {
    const thinkingWrap = document.getElementById('voiceThinkingWrap');
    if (!thinkingWrap) return;
    if (!voiceLabEnabled || !voiceLabSettings.showThinking || !thinkingText) {
        thinkingWrap.style.display = 'none';
        thinkingWrap.textContent = '';
        return;
    }
    thinkingWrap.textContent = thinkingText;
    thinkingWrap.style.display = 'block';
}

function voiceTryAutoListen() {
    if (!voiceShouldAutoListen()) return;
    setTimeout(() => {
        if (voiceShouldAutoListen()) {
            voiceStartListening();
        }
    }, 220);
}

async function openVoicePanel() {
    const backdrop  = document.getElementById('voicePanelBackdrop');
    backdrop.style.display = 'flex';
    const SR        = window.SpeechRecognition || window.webkitSpeechRecognition;
    const noSupport = document.getElementById('voiceNoSupport');
    const orbWrap   = document.getElementById('voiceOrbWrap');
    const hint      = document.getElementById('voiceHint');

    if (!SR) {
        noSupport.style.display = 'block';
        orbWrap.style.pointerEvents = 'none';
        orbWrap.style.opacity = '0.4';
        hint.style.display = 'none';
        return;
    }

    noSupport.style.display = 'none';
    orbWrap.style.pointerEvents = '';
    orbWrap.style.opacity = '';
    voiceInitLabSettings();

    // Probe server TTS availability once
    if (serverTtsAvailable === null) {
        hint.textContent = 'Checking AI voice...';
        try {
            const probe = await fetch('/api/tts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ probe: true })
            });
            const probeData = await probe.json().catch(() => ({}));
            serverTtsAvailable = !!probeData.configured;
            voiceHumanOnlyMode = !!probeData.human_voice;
        } catch(_) {
            voiceMarkServerTtsFailure();
            voiceHumanOnlyMode = false;
        }
    }

    if (serverTtsAvailable) {
        hint.textContent = 'Tap once to enter voice mode \u00b7 LyraLink keeps listening between turns';
    } else if (window.speechSynthesis) {
        hint.textContent = 'Tap once to enter voice mode \u00b7 using browser voice when AI TTS is unavailable';
    } else {
        hint.textContent = 'Tap once to enter voice mode';
    }
}

function closeVoicePanel() {
    voiceSessionActive = false;
    voiceBlockAutoRestart();
    voiceCancelInFlight();
    voiceStop();
    voiceStopPlayback();
    voiceRenderThinking('');
    document.getElementById('voicePanelBackdrop').style.display = 'none';
}

function voiceSetState(state) {
    voiceState = state;
    const orb     = document.getElementById('voiceOrb');
    const ring    = document.getElementById('voiceOrbRing');
    const ring2   = document.getElementById('voiceOrbRing2');
    const status  = document.getElementById('voiceStatus');
    const stopBtn = document.getElementById('voiceStopBtn');
    if (!orb) return;

    orb.className    = 'voice-orb' + (state !== 'idle' ? ' ' + state : '');
    ring.className   = 'voice-orb-ring'  + (state === 'listening' ? ' pulse' : '');
    ring2.className  = 'voice-orb-ring2' + (state === 'listening' ? ' pulse' : '');
    status.className = 'voice-status'    + (state !== 'idle' ? ' ' + state : '');
    stopBtn.style.display = (state !== 'idle' || voiceSessionActive) ? 'inline-flex' : 'none';

    const labels = {
        idle:       voiceSessionActive ? 'Voice mode active — waiting for you' : 'Tap once to enter voice mode',
        listening:  'Listening...',
        processing: 'Processing your speech...',
        speaking:   'Speaking... tap the orb to interrupt',
    };
    status.textContent = labels[state] || 'Tap once to enter voice mode';
    orb.textContent = state === 'speaking' ? '🔊' : state === 'processing' ? '⏳' : '🎤';
}

function voiceOrbClick() {
    if (voiceState === 'idle')       {
        voiceSessionActive = true;
        voiceStartListening();
        return;
    }
    if (voiceState === 'listening')  {
        voiceSessionActive = false;
        voiceBlockAutoRestart();
        voiceSetState('idle');
        if (voiceRecognizer) {
            try { voiceRecognizer.abort(); } catch(_) {}
            voiceRecognizer = null;
        }
        const transcriptEl = document.getElementById('voiceTranscript');
        if (transcriptEl) {
            transcriptEl.textContent = 'Voice mode ended.';
            transcriptEl.classList.remove('active');
            transcriptEl.classList.add('empty');
        }
        return;
    }
    if (voiceState === 'processing') {
        voiceCancelInFlight();
        voiceStopPlayback();
        voiceSetState('idle');
        voiceTryAutoListen();
        return;
    }
    if (voiceState === 'speaking')   {
        voiceStopPlayback();
        voiceSetState('idle');
        voiceTryAutoListen();
        return;
    }
}

function voiceFallbackToTypedInput() {
    const transcriptEl = document.getElementById('voiceTranscript');
    const hint = document.getElementById('voiceHint');
    const promptText = 'Microphone access is blocked in this browser on Linux. Type your message instead.';

    if (hint) {
        hint.textContent = 'Speech recognition is blocked in this browser; typed text fallback is active.';
    }

    const typed = window.prompt(promptText, '');
    if (typed === null) {
        if (transcriptEl) {
            transcriptEl.textContent = 'Mic error: not-allowed';
            transcriptEl.classList.remove('active');
            transcriptEl.classList.add('empty');
        }
        voiceSetState('idle');
        return;
    }

    const cleaned = typed.trim();
    if (!cleaned) {
        if (transcriptEl) {
            transcriptEl.textContent = 'Your words will appear here...';
            transcriptEl.classList.add('empty');
        }
        voiceSetState('idle');
        return;
    }

    if (transcriptEl) {
        transcriptEl.textContent = cleaned;
        transcriptEl.classList.remove('empty');
        transcriptEl.classList.remove('active');
    }
    voiceSendMessage(cleaned);
}

function voiceHandleMicPermissionError(errorText) {
    const transcriptEl = document.getElementById('voiceTranscript');
    const hint = document.getElementById('voiceHint');
    const finalText = errorText || 'Microphone access was blocked. Please allow mic permission in your browser and try again.';
    voiceSessionActive = false;

    if (transcriptEl) {
        transcriptEl.textContent = finalText;
        transcriptEl.classList.remove('active');
        transcriptEl.classList.add('empty');
    }
    if (hint) {
        hint.textContent = 'Microphone permission blocked — type your message instead, or re-enable access in browser settings.';
    }
    voiceFallbackToTypedInput();
    voiceSetState('idle');
}

async function voiceEnsureMicrophonePermission() {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        return true;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        stream.getTracks().forEach((track) => track.stop());
        return true;
    } catch (error) {
        console.warn('Microphone permission check failed:', error);
        throw error;
    }
}

async function voiceStartListening() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return;
    if (voiceState === 'listening') return;

    const transcriptEl = document.getElementById('voiceTranscript');
    const hint = document.getElementById('voiceHint');
    if (transcriptEl) {
        transcriptEl.textContent = '';
        transcriptEl.classList.remove('empty');
        transcriptEl.classList.add('active');
    }
    if (hint) {
        hint.textContent = 'Microphone access requested — please allow it when your browser asks.';
    }

    try {
        await voiceEnsureMicrophonePermission();
    } catch (error) {
        voiceHandleMicPermissionError('Mic error: not-allowed');
        return;
    }

    voiceStopPlayback();

    voiceRecognizer = new SR();
    voiceRecognizer.continuous     = false;
    voiceRecognizer.interimResults = true;
    voiceRecognizer.lang           = 'en-US';

    voiceSetState('listening');

    voiceRecognizer.onresult = (event) => {
        let interim = '', final = '';
        for (const res of event.results) {
            if (res.isFinal) final   += res[0].transcript;
            else             interim += res[0].transcript;
        }
        if (transcriptEl) {
            transcriptEl.textContent = final || interim || '';
        }
    };

    voiceRecognizer.onerror = (event) => {
        if (event.error === 'no-speech' || event.error === 'aborted') {
            voiceRecognizer = null;
            voiceSetState('idle');
            if (transcriptEl) {
                transcriptEl.classList.remove('active');
            }
            if (event.error === 'no-speech') {
                voiceTryAutoListen();
            }
            return;
        }
        if (event.error === 'not-allowed' || event.error === 'permission-denied') {
            voiceHandleMicPermissionError('Mic error: not-allowed');
            voiceRecognizer = null;
            return;
        }
        if (transcriptEl) {
            transcriptEl.textContent = 'Mic error: ' + event.error;
            transcriptEl.classList.remove('active');
        }
        voiceRecognizer = null;
        voiceSetState('idle');
        voiceTryAutoListen();
    };

    voiceRecognizer.onend = () => {
        voiceRecognizer = null;
        transcriptEl.classList.remove('active');
        const text = transcriptEl.textContent.trim();
        if (voiceState === 'listening') {
            if (text) {
                voiceSendMessage(text);
            } else {
                voiceSetState('idle');
                transcriptEl.textContent = 'Your words will appear here...';
                transcriptEl.classList.add('empty');
                voiceTryAutoListen();
            }
        }
    };

    try { voiceRecognizer.start(); } catch(e) { voiceSetState('idle'); }
}

function voiceStop() {
    voiceSessionActive = false;
    voiceBlockAutoRestart();
    voiceCancelInFlight();
    voiceStopPlayback();
    if (voiceRecognizer) {
        try { voiceRecognizer.abort(); } catch(_) {}
        voiceRecognizer = null;
    }
    voiceSetState('idle');
    const tr = document.getElementById('voiceTranscript');
    if (tr) tr.classList.remove('active');
}

function voiceClear() {
    voiceStop();
    const tr = document.getElementById('voiceTranscript');
    if (tr) { tr.textContent = 'Your words will appear here...'; tr.classList.add('empty'); }
    const resp = document.getElementById('voiceResponse');
    if (resp) { resp.className = 'voice-response-wrap'; resp.textContent = ''; }
    voiceRenderThinking('');
}

function voiceSplitSpeechBuffer(buffer, final = false) {
    const segments = [];
    let remainder = buffer;

    while (remainder.length > 0) {
        let boundary = -1;
        const sentenceMatch = remainder.slice(0, 260).match(/^(.+?[.!?…]+(?:["')\]]+)?)(\s+|$)/s);
        if (sentenceMatch) {
            boundary = sentenceMatch[1].length;
        } else if (!final && remainder.length > 220) {
            const spaceBoundary = remainder.lastIndexOf(' ', 180);
            if (spaceBoundary > 80) {
                boundary = spaceBoundary;
            }
        } else if (final) {
            boundary = remainder.length;
        }

        if (boundary <= 0) {
            break;
        }

        const segment = remainder.slice(0, boundary).trim();
        if (segment) {
            segments.push(segment);
        }
        remainder = remainder.slice(boundary).trimStart();
    }

    if (final && remainder.trim()) {
        segments.push(remainder.trim());
        remainder = '';
    }

    return { segments, remainder };
}

function voiceQueueSpeechSegment(segment) {
    const runId = voiceSpeechRunId;
    const cleanSegment = voiceNormalizeSpeechText(segment);
    if (!cleanSegment) {
        return;
    }

    voiceSpeechQueue = voiceSpeechQueue.then(async () => {
        if (runId !== voiceSpeechRunId) {
            return;
        }

        if (voiceCanTryServerTts()) {
            try {
                const res = await fetch('/api/tts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: cleanSegment })
                });

                if (runId !== voiceSpeechRunId) {
                    return;
                }

                if (res.ok && res.headers.get('Content-Type')?.includes('audio')) {
                    voiceMarkServerTtsSuccess();
                    const blob = await res.blob();
                    if (runId !== voiceSpeechRunId) {
                        return;
                    }
                    await new Promise((resolve) => {
                        const url = URL.createObjectURL(blob);
                        voiceAudio = new Audio(url);
                        voiceAudio.onended = () => { URL.revokeObjectURL(url); voiceAudio = null; resolve(); };
                        voiceAudio.onerror = () => { URL.revokeObjectURL(url); voiceAudio = null; resolve(); };
                        voiceAudio.play().catch(() => { URL.revokeObjectURL(url); voiceAudio = null; resolve(); });
                    });
                    return;
                }

                voiceMarkServerTtsFailure();
            } catch(_) {
                voiceMarkServerTtsFailure();
            }
        }

        if (voiceHumanOnlyMode) {
            return;
        }

        const synth = window.speechSynthesis;
        if (!synth) {
            return;
        }

        await new Promise((resolve) => {
            const utter = new SpeechSynthesisUtterance(cleanSegment);
            utter.rate = 1.05;
            utter.pitch = 1.0;
            utter.volume = 1.0;
            utter.onend = resolve;
            utter.onerror = resolve;
            if (runId !== voiceSpeechRunId) {
                resolve();
                return;
            }
            synth.speak(utter);
        });
    }).catch(() => {});
}

function voiceFlushSpeechBuffer(final = false) {
    const split = voiceSplitSpeechBuffer(voiceSpeechBuffer, final);
    voiceSpeechBuffer = split.remainder;
    split.segments.forEach(voiceQueueSpeechSegment);
}

function voiceHandleStreamDelta(delta, final = false) {
    voiceSpeechBuffer += String(delta || '');
    voiceFlushSpeechBuffer(final);
}

async function voiceSendMessage(text) {
    voiceSetState('processing');
    voiceStopPlayback();
    const currentRunId = ++voiceStreamRunId;

    if (!activeConvId) await newConversation();

    const messages = [
        ...getActiveMessages(),
        { role: 'user', content: text }
    ];

    const applyVoiceReply = async (reply, aiThinking, finalPayload = null) => {
        voiceHandleStreamDelta('', true);
        await voiceSpeechQueue.catch(() => {});

        if (finalPayload?.agent && Object.keys(finalPayload.agent).length > 0) {
            applyAgentPayload(finalPayload.agent, reply);
        }

        await saveMessage('user', text);
        await saveMessage('assistant', reply, aiThinking || '');
        renderChat();

        const respEl = document.getElementById('voiceResponse');
        if (respEl) {
            respEl.textContent = reply;
            respEl.className = 'voice-response-wrap show';
        }

        voiceRenderThinking(aiThinking || '');
        voiceSetState('idle');
        voiceTryAutoListen();
    };

    const voiceSendMessageFallback = async () => {
        const edgeCacheKey = (!currentUser?.username)
            ? buildEdgeChatCacheKey(messages, {
                taskMode: aiAssistOptions.taskMode,
                taskFocus: aiAssistOptions.taskFocus || 'general',
                provider: selectedLlmProvider || undefined,
                model: selectedLlmModel || undefined
            })
            : '';

        const headers = { 'Content-Type': 'application/json' };
        if (edgeCacheKey) {
            headers['X-Chat-Cache-Key'] = edgeCacheKey;
        }

        const fallbackRes = await voiceFetchWithTimeout('/api/chat.php', {
            method: 'POST',
            headers,
            body: JSON.stringify({
                messages,
                cache_key: edgeCacheKey || undefined,
                user_id: currentUser?.username || null,
                username: currentUser?.username || null,
                user_plan: currentUser?.plan || 'free',
                provider: selectedLlmProvider || undefined,
                model: selectedLlmModel || undefined,
                task_mode: aiAssistOptions.taskMode,
                task_focus: aiAssistOptions.taskFocus || 'general',
                persistent_goals: getPersistentGoalContext(),
                dev_mode: voiceLabEnabled,
                reasoning_requested: true,
                live_trace: true,
            })
        }, voiceLabSettings.deepThinking ? 180000 : 120000);

        if (!fallbackRes.ok) {
            throw new Error('fallback_failed');
        }

        const data = await fallbackRes.json().catch(() => ({}));
        const reply = String(data.reply || 'Sorry, something went wrong.');
        const reasoning = data.reasoning && typeof data.reasoning === 'object'
            ? (String(data.reasoning.summary || data.reasoning.decision_path?.join(' ') || '') || String(data.thinking || ''))
            : String(data.thinking || '');

        if (voiceState !== 'speaking') {
            voiceSetState('speaking');
        }
        voiceHandleStreamDelta(reply, true);
        await applyVoiceReply(reply, reasoning, data);
    };

    try {
        voiceRequestController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const edgeCacheKey = (!currentUser?.username)
            ? buildEdgeChatCacheKey(messages, {
                taskMode: aiAssistOptions.taskMode,
                taskFocus: aiAssistOptions.taskFocus || 'general',
                provider: selectedLlmProvider || undefined,
                model: selectedLlmModel || undefined
            })
            : '';
        const headers = { 'Content-Type': 'application/json' };
        if (edgeCacheKey) {
            headers['X-Chat-Cache-Key'] = edgeCacheKey;
        }
        const timeoutMs = voiceLabSettings.deepThinking ? 180000 : 120000;
        const response = await voiceFetchWithTimeout('/api/chat_stream.php', {
            method:  'POST',
            headers,
            signal: voiceRequestController ? voiceRequestController.signal : undefined,
            body:    JSON.stringify({
                messages,
                cache_key: edgeCacheKey || undefined,
                user_id:   currentUser?.username || null,
                username:  currentUser?.username || null,
                user_plan: currentUser?.plan     || 'free',
                provider:  selectedLlmProvider   || undefined,
                model:     selectedLlmModel      || undefined,
                task_mode: aiAssistOptions.taskMode,
                task_focus: aiAssistOptions.taskFocus || 'general',
                persistent_goals: getPersistentGoalContext(),
                dev_mode: voiceLabEnabled,
                reasoning_requested: true,
                live_trace: true,
            })
        }, timeoutMs);
        voiceRequestController = null;
        if (!response.ok || !response.body) {
            throw new Error('stream_failed');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let reply = '';
        let aiThinking = '';
        let finalPayload = null;
        const respEl = document.getElementById('voiceResponse');
        if (respEl) {
            respEl.textContent = '';
            respEl.className = 'voice-response-wrap show';
        }

        voiceRenderThinking('');

        while (true) {
            const { value, done } = await reader.read();
            if (currentRunId !== voiceStreamRunId) {
                throw new Error('aborted');
            }
            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });
            let newlineIndex;
            while ((newlineIndex = buffer.indexOf('\n')) !== -1) {
                const rawLine = buffer.slice(0, newlineIndex).trim();
                buffer = buffer.slice(newlineIndex + 1);
                if (!rawLine) {
                    continue;
                }

                let payload = null;
                try {
                    payload = JSON.parse(rawLine);
                } catch(_) {
                    continue;
                }

                if (payload.type === 'meta') {
                    continue;
                }
                if (payload.type === 'delta') {
                    const chunk = String(payload.text || '');
                    if (!chunk) {
                        continue;
                    }
                    reply += chunk;
                    if (respEl) {
                        respEl.textContent = reply;
                    }
                    if (voiceState !== 'speaking') {
                        voiceSetState('speaking');
                    }
                    voiceHandleStreamDelta(chunk, false);
                } else if (payload.type === 'done') {
                    finalPayload = payload;
                    reply = String(payload.reply || reply || 'Sorry, something went wrong.');
                    const reasoningText = payload.reasoning && typeof payload.reasoning === 'object'
                        ? (String(payload.reasoning.summary || payload.reasoning.decision_path?.join(' ') || '') || String(payload.thinking || ''))
                        : String(payload.thinking || '');
                    aiThinking = String(reasoningText || payload.thinking || '');
                } else if (payload.type === 'error') {
                    throw new Error(String(payload.message || 'stream_error'));
                }
            }
        }

        buffer += decoder.decode();
        const trailing = buffer.trim();
        if (trailing) {
            try {
                const payload = JSON.parse(trailing);
                if (payload.type === 'done') {
                    finalPayload = payload;
                    reply = String(payload.reply || reply || 'Sorry, something went wrong.');
                    const reasoningText = payload.reasoning && typeof payload.reasoning === 'object'
                        ? (String(payload.reasoning.summary || payload.reasoning.decision_path?.join(' ') || '') || String(payload.thinking || ''))
                        : String(payload.thinking || '');
                    aiThinking = String(reasoningText || payload.thinking || '');
                }
            } catch(_) {}
        }

        await applyVoiceReply(reply, aiThinking, finalPayload);
    } catch (err) {
        voiceRequestController = null;
        if (currentRunId !== voiceStreamRunId) {
            return;
        }
        try {
            await voiceSendMessageFallback();
            return;
        } catch (_) {}
        const respEl = document.getElementById('voiceResponse');
        if (respEl) {
            respEl.textContent = voiceState === 'idle'
                ? 'Voice request cancelled.'
                : 'Connection error. Please try again.';
            respEl.className   = 'voice-response-wrap show';
        }
        voiceSetState('idle');
        voiceTryAutoListen();
    }
}

async function voiceSpeak(text) {
    const token = ++voicePlaybackToken;
    const chunks = voiceChunkSpeechText(text, voiceLabSettings.deepThinking ? 220 : 260);

    if (!chunks.length) {
        voiceSetState('idle');
        voiceTryAutoListen();
        return;
    }

    // Try server-side AI TTS first
    if (voiceCanTryServerTts()) {
        voiceSetState('speaking'); // set state optimistically so UI updates immediately
        try {
            let playedAnyServerChunk = false;
            for (const chunk of chunks) {
                if (token !== voicePlaybackToken) return;
                const res = await fetch('/api/tts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: chunk })
                });
                if (!(res.ok && res.headers.get('Content-Type')?.includes('audio'))) {
                    playedAnyServerChunk = false;
                    voiceMarkServerTtsFailure();
                    break;
                }

                playedAnyServerChunk = true;
                voiceMarkServerTtsSuccess();
                const blob = await res.blob();
                const ok = await voicePlayAudioBlob(blob, token);
                if (!ok || token !== voicePlaybackToken) {
                    return;
                }
            }

            if (playedAnyServerChunk && token === voicePlaybackToken) {
                voiceSetState('idle');
                voiceTryAutoListen();
                return;
            }
        } catch(_) {
            voiceMarkServerTtsFailure();
        }
    }

    if (voiceHumanOnlyMode) {
        voiceSetState('idle');
        voiceTryAutoListen();
        return;
    }

    // ── Browser SpeechSynthesis fallback ─────────────────────────────────
    const synth = window.speechSynthesis;
    if (!synth) { voiceSetState('idle'); voiceTryAutoListen(); return; }

    for (const chunk of chunks) {
        if (token !== voicePlaybackToken) return;
        const plain = voiceNormalizeSpeechText(chunk).slice(0, 2200);
        if (!plain) continue;

        await new Promise((resolve) => {
            if (token !== voicePlaybackToken) {
                resolve();
                return;
            }
            const utter = new SpeechSynthesisUtterance(plain);
            utter.rate = 1.05;
            utter.pitch = 1.0;
            utter.volume = 1.0;
            utter.onstart = () => {
                if (token === voicePlaybackToken) {
                    voiceSetState('speaking');
                }
            };
            utter.onend = () => resolve();
            utter.onerror = () => resolve();
            synth.speak(utter);
        });
    }

    if (token === voicePlaybackToken) {
        voiceSetState('idle');
        voiceTryAutoListen();
    }
}

// ── API STATUS ──
async function checkApiStatus() {
    const dot  = document.getElementById('apiStatusDot');
    const text = document.getElementById('apiStatusText');
    if (!dot || !text) return;
    try {
        const start = Date.now();
        const fd = new FormData(); fd.append('action', 'check');
        const res = await fetch('/api/auth.php', { method: 'POST', body: fd });
        const ms  = Date.now() - start;
        if (res.ok) {
            dot.className  = 'status-dot online';
            text.textContent = ms < 500 ? 'API online' : `API online · ${ms}ms`;
        } else {
            throw new Error('non-ok');
        }
    } catch(e) {
        dot.className    = 'status-dot offline';
        text.textContent = 'API offline';
    }
}

// ── IDLE TIMEOUT ──
(function initIdleTimeout() {
    const IDLE_WARN_MS  = 8 * 60 * 1000;  // 8 min — show warning
    const IDLE_LIMIT_MS = 9 * 60 * 1000;  // 9 min — auto sign-out
    const PING_EVERY_MS = 2 * 60 * 1000;  // 2 min — heartbeat while active

    let lastActivity = Date.now();
    let warnShown = false;
    let warnEl = null;

    // Track any user interaction
    ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(evt =>
        document.addEventListener(evt, () => { lastActivity = Date.now(); hideWarn(); }, { passive: true })
    );

    function hideWarn() {
        if (warnShown && warnEl) {
            warnEl.remove(); warnEl = null; warnShown = false;
        }
    }

    function showWarn() {
        if (warnShown) return;
        warnShown = true;
        warnEl = document.createElement('div');
        warnEl.id = 'idleWarnBanner';
        Object.assign(warnEl.style, {
            position:'fixed', bottom:'24px', left:'50%', transform:'translateX(-50%)',
            background:'#1e1e2e', border:'1px solid #7c3aed', borderRadius:'12px',
            padding:'12px 20px', color:'#e2e8f0', fontSize:'13px', zIndex:'9999',
            boxShadow:'0 4px 24px rgba(0,0,0,0.5)', display:'flex', gap:'14px', alignItems:'center',
            fontFamily:'DM Mono,monospace'
        });
        warnEl.innerHTML = '<span>You\'ve been inactive. You\'ll be signed out in 1 minute.</span>'
            + '<button onclick="this.closest(\'#idleWarnBanner\').remove()" '
            + 'style="background:#7c3aed;border:none;color:white;padding:5px 14px;border-radius:8px;cursor:pointer;font-family:inherit;font-size:12px">Stay signed in</button>';
        warnEl.querySelector('button').addEventListener('click', () => {
            lastActivity = Date.now(); hideWarn();
        });
        document.body.appendChild(warnEl);
    }

    // Heartbeat: keep server session alive while user is active
    async function ping() {
        if (!currentUser) return;
        if (Date.now() - lastActivity < PING_EVERY_MS) {
            const fd = new FormData(); fd.append('action', 'ping');
            try {
                const d = await (await fetch('/api/auth.php', { method:'POST', body:fd })).json();
                if (!d.success && d.reason === 'idle_timeout') handleIdleLogout();
            } catch(e) {}
        }
    }

    async function handleIdleLogout() {
        if (!currentUser) return;
        await logout();
        // Show signed-out notice
        const notice = document.createElement('div');
        Object.assign(notice.style, {
            position:'fixed', top:'50%', left:'50%', transform:'translate(-50%,-50%)',
            background:'#111118', border:'1px solid #1e1e2e', borderRadius:'16px',
            padding:'32px 40px', color:'#e2e8f0', fontSize:'14px', zIndex:'10000',
            boxShadow:'0 8px 40px rgba(0,0,0,0.7)', textAlign:'center', maxWidth:'340px',
            fontFamily:'DM Mono,monospace'
        });
        notice.innerHTML = '<div style="font-size:28px;margin-bottom:12px">🔒</div>'
            + '<div style="font-family:Syne,sans-serif;font-size:16px;font-weight:700;margin-bottom:8px">Signed out</div>'
            + '<div style="color:#64748b;font-size:13px">You were signed out due to inactivity.</div>';
        document.body.appendChild(notice);
        setTimeout(() => notice.remove(), 5000);
    }

    // Tick every 30 seconds
    setInterval(async () => {
        const idle = Date.now() - lastActivity;
        if (idle >= IDLE_LIMIT_MS && currentUser) {
            await handleIdleLogout();
        } else if (idle >= IDLE_WARN_MS && currentUser) {
            showWarn();
        }
    }, 30_000);

    setInterval(ping, PING_EVERY_MS);
})();

// ── INIT ──
(async function init() {
    initAiAssistControls();
    // checkSession handles conv loading via loadConvList internally
    await checkSession();
    loadGoalBoard();
    document.getElementById('goalInput')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addGoalFromInput();
        }
    });
    checkApiStatus();
    setInterval(checkApiStatus, 60 * 1000);
    document.getElementById('userInput').focus();
})();
