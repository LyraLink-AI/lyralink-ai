// ── SEND MESSAGE ──
async function sendMessage() {
    const typedMessage = getInputText();
    const attachment = pendingChatAttachment;
    if (!typedMessage && !attachment) return;

    const message = typedMessage || 'Please analyze this attachment.';
    const attachmentLine = attachment ? `
<div style="margin-top:8px;font-size:11px;color:#a78bfa">📎 ${escapeHtml(attachment.name)}${attachment.size ? ` (${escapeHtml(formatAttachmentSize(attachment.size))})` : ''}</div>` : '';
    const displayMessage = attachment ? `${message}

[Attached: ${attachment.name}]` : message;

    if (!activeConvId) await newConversation();

    const chatbox    = document.getElementById('chatbox');
    const emptyState = document.getElementById('emptyState');
    if (emptyState) emptyState.remove();

    chatbox.innerHTML += `<div class="msg user"><div class="avatar">👤</div><div class="bubble">${escapeHtml(message)}${attachmentLine}</div></div>`;
    const thinkingId = 'thinking_' + Date.now();
    chatbox.innerHTML += `<div class="msg ai" id="${thinkingId}"><div class="msg-role-label">Lyralink</div><div class="bubble"><div class="thinking-box"><div class="thinking-box-header"><span class="thinking-box-label">Thinking</span><span class="thinking-dots"><span></span><span></span><span></span></span></div><div class="thinking-box-body">Checking the request, mapping the best path, and preparing the answer.</div></div></div></div>`;
    let thinkingEl = document.getElementById(thinkingId);

    clearInput();
    clearPendingAttachment();
    if (window.matchMedia('(max-width: 767px)').matches) {
        setAiToolsOpen(false);
    }
    setInputDisabled(true);
    if (aiAssistOptions.taskMode) {
        setAgentStatus('planning', attachment ? 'Reviewing your request and attachment.' : 'Reviewing your request and mapping the next move.', 'Generating the next step now.');
    }
    if (aiAssistOptions.liveTrace) renderClientTrace('Preparing request');
    chatbox.scrollTop = chatbox.scrollHeight;
    await saveMessage('user', displayMessage);

    const messages = [
        ...getActiveMessages().slice(0, -1),
        { role: 'user', content: displayMessage }
    ];

    const storedModelPrefs = getStoredModelPrefs();
    const providerToSend = selectedLlmProvider || storedModelPrefs.provider || undefined;
    const modelToSend = selectedLlmModel || storedModelPrefs.model || undefined;
    const executionPayload = buildExecutionControlPayload();
    const isImageAttachment = !!(attachment?.file && String(attachment.file.type || '').toLowerCase().startsWith('image/'));

    try {
        if (aiAssistOptions.liveTrace) renderClientTrace('Generating and validating response');
        const requestTimeoutMs = isImageAttachment
            ? 240000
            : ((aiAssistOptions.taskMode || aiAssistOptions.codeTest) ? 180000 : 90000);
        let response;
        if (attachment?.file) {
            const fd = new FormData();
            fd.append('messages', JSON.stringify(messages));
            fd.append('user_id', currentUser?.username || '');
            fd.append('username', currentUser?.username || '');
            fd.append('user_plan', currentUser?.plan || 'free');
            if (providerToSend) fd.append('provider', providerToSend);
            if (modelToSend) fd.append('model', modelToSend);
            fd.append('dev_mode', isDevUser() && devModeEnabled ? '1' : '0');
            fd.append('task_mode', aiAssistOptions.taskMode ? '1' : '0');
            fd.append('task_focus', aiAssistOptions.taskFocus || 'general');
            fd.append('persistent_goals', JSON.stringify(getPersistentGoalContext()));
            fd.append('run_code_tests', aiAssistOptions.codeTest ? '1' : '0');
            fd.append('live_trace', aiAssistOptions.liveTrace ? '1' : '0');
            fd.append('project_id', executionPayload.project_id || 'default');
            fd.append('work_mode', executionPayload.work_mode ? '1' : '0');
            fd.append('long_running_agent', executionPayload.long_running_agent ? '1' : '0');
            fd.append('approval_required', executionPayload.approval_required ? '1' : '0');
            fd.append('approval_granted', executionPayload.approval_granted ? '1' : '0');
            fd.append('agent_budget_tokens', String(executionPayload.agent_budget_tokens || 0));
            fd.append('agent_budget_seconds', String(executionPayload.agent_budget_seconds || 180));
            fd.append('agent_permissions', JSON.stringify(executionPayload.agent_permissions || []));
            fd.append('task_state', JSON.stringify(executionPayload.task_state || {}));
            fd.append('client_channel', executionPayload.client_channel || 'web');
            fd.append('webhook_events', JSON.stringify(executionPayload.webhook_events || []));
            if (executionPayload.agent_checkpoint_note) fd.append('agent_checkpoint_note', executionPayload.agent_checkpoint_note);
            if (executionPayload.rollback_to_checkpoint) fd.append('rollback_to_checkpoint', executionPayload.rollback_to_checkpoint);
            fd.append('stream', '0');
            fd.append('attachment', attachment.file, attachment.name || 'attachment');
            response = await fetchWithTimeout('/api/chat.php', { method: 'POST', body: fd }, requestTimeoutMs);
        } else {
            const edgeCacheKey = (!currentUser?.username)
                ? buildEdgeChatCacheKey(messages, {
                    taskMode: aiAssistOptions.taskMode,
                    taskFocus: aiAssistOptions.taskFocus || 'general',
                    provider: providerToSend,
                    model: modelToSend
                })
                : '';
            const headers = { 'Content-Type': 'application/json' };
            if (edgeCacheKey) {
                headers['X-Chat-Cache-Key'] = edgeCacheKey;
            }
            headers['Accept'] = 'text/event-stream, application/json';
            response = await fetchWithTimeout('/api/chat.php', {
                method: 'POST',
                headers,
                body: JSON.stringify({
                    messages,
                    cache_key: edgeCacheKey || undefined,
                    user_id:    currentUser?.username || null,
                    username:   currentUser?.username || null,
                    user_plan:  currentUser?.plan     || 'free',
                    provider:   providerToSend,
                    model:      modelToSend,
                    dev_mode:   isDevUser() ? devModeEnabled : false,
                    task_mode: aiAssistOptions.taskMode,
                    task_focus: aiAssistOptions.taskFocus || 'general',
                    persistent_goals: getPersistentGoalContext(),
                    run_code_tests: aiAssistOptions.codeTest,
                    live_trace: aiAssistOptions.liveTrace,
                    stream: true,
                    project_id: executionPayload.project_id || 'default',
                    work_mode: executionPayload.work_mode,
                    long_running_agent: executionPayload.long_running_agent,
                    approval_required: executionPayload.approval_required,
                    approval_granted: executionPayload.approval_granted,
                    agent_budget_tokens: executionPayload.agent_budget_tokens,
                    agent_budget_seconds: executionPayload.agent_budget_seconds,
                    agent_permissions: executionPayload.agent_permissions,
                    task_state: executionPayload.task_state,
                    client_channel: executionPayload.client_channel || 'web',
                    webhook_events: executionPayload.webhook_events,
                    agent_checkpoint_note: executionPayload.agent_checkpoint_note || undefined,
                    rollback_to_checkpoint: executionPayload.rollback_to_checkpoint || undefined
                })
            }, requestTimeoutMs);
        }
        if (!response.ok) {
            let payload = {};
            try { payload = await response.json(); } catch (_) {}
            const httpCode = Number(payload?.http_code ?? payload?.status ?? response.status ?? 0);
            const detail = payload?.error_detail || payload?.message || payload?.error || `HTTP ${response.status || 'unknown'} response received.`;
            const devMessage = isDevUser()
                ? `Request failed (${httpCode || response.status || 'unknown'}): ${detail}`
                : 'The request could not be completed right now. Please try again.';
            document.getElementById(thinkingId)?.remove();
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(devMessage)}</div></div>`;
            setAgentStatus('waiting', devMessage, isDevUser() ? 'Review the developer error details in the request response.' : 'Retry or switch to a faster mode.');
            if (aiAssistOptions.liveTrace) renderClientTrace(`HTTP error ${httpCode || response.status || 'unknown'}: ${detail}`);
            return;
        }

        let data;
        let madeBy = 'Lyralink';
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        if (contentType.includes('text/event-stream') && response.body) {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let liveReply = '';
            let finalPayload = null;

            const updateLiveBubble = () => {
                const root = document.getElementById(thinkingId);
                const bubble = root?.querySelector('.bubble');
                if (!bubble) return;
                bubble.innerHTML = buildStreamingAssistantBubbleHtml(liveReply, '', '', aiAssistOptions.taskMode, '', '', '', madeBy);
                chatbox.scrollTop = chatbox.scrollHeight;
            };

            const handleEventBlock = (block) => {
                const lines = String(block || '').split(/\r?\n/);
                let eventName = 'message';
                const payloadLines = [];
                for (const line of lines) {
                    if (line.startsWith('event:')) {
                        eventName = line.slice(6).trim() || 'message';
                    } else if (line.startsWith('data:')) {
                        payloadLines.push(line.slice(5).trimStart());
                    }
                }
                const rawPayload = payloadLines.join('\n').trim();
                if (!rawPayload) return;
                let parsedPayload = null;
                try {
                    parsedPayload = JSON.parse(rawPayload);
                } catch (_) {
                    parsedPayload = null;
                }
                        if (eventName === 'status' && thinkingEl && parsedPayload?.message) {
                    const body = thinkingEl.querySelector('.thinking-box-body');
                    if (body) body.textContent = parsedPayload.message;
                    return;
                }
                if (eventName === 'delta' && parsedPayload?.delta) {
                    const rawDelta = String(parsedPayload.delta || '');
                    if (rawDelta === '') {
                        return;
                    }
                    // Preserve exact model spacing; client-side re-chunking can
                    // merge words when deltas already arrive tokenized.
                    liveReply += rawDelta;
                    updateLiveBubble();
                    return;
                }
                if (eventName === 'final' && parsedPayload && typeof parsedPayload === 'object') {
                    finalPayload = parsedPayload;
                    madeBy = parsedPayload.made_by || madeBy;
                }
            };

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let separatorIndex;
                while ((separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, separatorIndex);
                    buffer = buffer.slice(separatorIndex + 2);
                    handleEventBlock(block);
                }
            }

            buffer += decoder.decode();
            if (buffer.trim() !== '') {
                handleEventBlock(buffer);
            }
            data = finalPayload || { reply: liveReply, made_by: madeBy };
            thinkingEl?.remove();
            thinkingEl = null;
        } else {
            data = await response.json();
            thinkingEl?.remove();
            thinkingEl = null;
        }

        if (data.error === 'limit_reached') {
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble" style="border-left-color:rgba(255,107,53,0.6)">
                <strong style="color:#ff6b35">Monthly limit reached</strong><br><br>
                ${escapeHtml(data.message)}<br><br>
                <a href="/pages/pricing" style="display:inline-block;margin-top:4px;padding:8px 16px;background:#7c3aed;color:white;border-radius:8px;text-decoration:none;font-size:13px;">⚡ Upgrade Plan</a>
                &nbsp;
                <a href="/pages/pricing#credits" style="display:inline-block;margin-top:4px;padding:8px 16px;background:none;border:1px solid #ff6b35;color:#ff6b35;border-radius:8px;text-decoration:none;font-size:13px;">Buy Credits</a>
            </div></div>`;
            setAgentStatus('waiting', 'Usage limit reached for this month.', 'Upgrade or add credits to continue.');
            setInputDisabled(false);
            document.getElementById('userInput').focus();
            return;
        }

        if (data.error === 'attachment_error') {
            chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(data.message || 'That attachment could not be processed.')}</div></div>`;
            setAgentStatus('waiting', data.message || 'That attachment could not be processed.', 'Try another file or resend it.');
            setInputDisabled(false);
            document.getElementById('userInput').focus();
            return;
        }

        // Honest failure surface. Never present a silent void: state what
        // happened, whether anything ran, and what to do next.
        const lyraFailureText = (() => {
            const code = String((data && data.error) || '').trim();
            const known = {
                approval_required: 'This action needs your approval before I can continue. Approve it and resend, or tell me to proceed.',
                timeout: 'The model did not finish in time. Nothing was executed or changed. Try a shorter request, or ask me to split it into steps.',
                rate_limited: 'Too many requests right now. Nothing was executed or changed. Wait a moment and resend.',
                usage_limit: 'The usage limit for this period was reached. Nothing was executed or changed.',
                invalid_benchmark_payload: 'The request could not be parsed. Nothing was changed. Please resend.',
            };
            if (known[code]) {
                return known[code];
            }
            if (code) {
                return 'The request did not complete (' + code + '). Nothing was executed or changed. Resend to try again.';
            }
            return 'The request returned no output, so nothing was executed or changed. Resend to try again.';
        })();
        const reply = (typeof data.reply === 'string' && data.reply.trim() !== '')
            ? data.reply
            : lyraFailureText;
        const aiThinking = data.thinking || '';
        const codeTestCard = renderCodeTestCard(data.code_test);
        const generatedImageUrl = data.generated_image_url || '';
        const generatedImageDownloadUrl = data.generated_image_download_url || '';
        madeBy = data.made_by || madeBy;
        if (thinkingEl) {
            await streamAssistantMessage(thinkingId, reply, codeTestCard, '', aiAssistOptions.taskMode, aiThinking, generatedImageUrl, data.image_prompt || '', generatedImageDownloadUrl, madeBy);
            thinkingEl.removeAttribute('id');
        } else {
            chatbox.innerHTML += buildAssistantMessageHtml(reply, codeTestCard, '', aiAssistOptions.taskMode, aiThinking, generatedImageUrl, data.image_prompt || '', generatedImageDownloadUrl, madeBy);
        }

        applyAgentPayload(data.agent || {}, reply);
        applyExecutionPayload(data);

        if (data.error === 'approval_required') {
            setAgentStatus('waiting', 'Approval is required before the mission can continue.', 'Click "Approve next run" and resend your request.');
        }

        if (aiAssistOptions.liveTrace) {
            const items = formatServerTrace(data.trace);
            renderTracePanel(items, 'Live Trace');
        }

        await saveMessage('assistant', reply, aiThinking);
        const conv = convCache.find(c => c.conv_id === activeConvId);
        document.getElementById('chatTitle').textContent = conv?.title || 'Chat';
        document.getElementById('chatTitle').className   = 'chat-title has-msgs';
        if (data.debug) renderDebug(data.debug);

    } catch (e) {
        document.getElementById(thinkingId)?.remove();
        const errorName = e?.name || 'Error';
        const errorMsg = e?.message || 'The request was interrupted before a response was received.';
        const publicText = isImageAttachment
            ? 'Image analysis took too long to finish. Please retry with a clearer or smaller image, or try again in a moment.'
            : 'The request timed out before the model finished. Please retry or switch to a faster mode.';
        const devText = isDevUser()
            ? `Request failed (${errorName}): ${errorMsg}`
            : publicText;
        chatbox.innerHTML += `<div class="msg ai"><div class="msg-role-label">Lyralink</div><div class="bubble">${escapeHtml(devText)}</div></div>`;
        setAgentStatus('waiting', devText, isDevUser() ? 'Inspect the network failure and retry with a model route that matches the request.' : 'Retry or switch to a faster mode.');
        if (aiAssistOptions.liveTrace) renderClientTrace(isDevUser() ? `${errorName}: ${errorMsg}` : 'Request failed');
    }

    chatbox.scrollTop = chatbox.scrollHeight;
    setInputDisabled(false);
    document.getElementById('userInput').focus();
}

