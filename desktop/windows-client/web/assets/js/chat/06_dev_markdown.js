function isDevUser() { return currentUser?.username === DEV_USERNAME; }

function showDevFab() {
    const fab = document.getElementById('devFab');
    if (isDevUser()) fab.classList.add('visible');
    else fab.classList.remove('visible');
}

function toggleDevPanel() {
    const panel = document.getElementById('devPanel');
    const fab   = document.getElementById('devFab');
    panel.classList.toggle('open');
    fab.classList.toggle('panel-open');
}

function toggleDevMode() {
    devModeEnabled = !devModeEnabled;
    document.getElementById('devModeBtn').textContent = devModeEnabled ? 'ON' : 'OFF';
    document.getElementById('devModeBtn').style.color = devModeEnabled ? '#22c55e' : '#ef4444';
    document.getElementById('devModeBtn').style.borderColor = devModeEnabled ? '#22c55e' : '#ef4444';
}

function renderDebug(debug) {
    if (!debug || !isDevUser()) return;

    const usageCount = Number.isFinite(Number(debug.token_count)) ? Number(debug.token_count) : Number(debug.msg_count || 0);
    const usageLimit = Number.isFinite(Number(debug.token_limit)) ? Number(debug.token_limit) : Number(debug.msg_limit || 0);
    const pct     = usageLimit > 0 ? Math.min(100, Math.round((usageCount / usageLimit) * 100)) : 0;
    const scoreColor = debug.post_score >= 4 ? 'good' : debug.post_score >= 2 ? 'warn' : 'bad';
    const msColor    = debug.groq_ms < 1000 ? 'good' : debug.groq_ms < 3000 ? 'warn' : 'bad';
    const cooldownMin = Math.ceil(debug.cooldown_left / 60);
    const actualProvider = debug.provider || 'unknown';
    const actualModel = debug.model || 'unknown';
    const requestedProvider = debug.requested_provider || actualProvider;
    const requestedModel = debug.requested_model || actualModel;
    const requestedHttpCode = debug.requested_http_code || null;
    const providerChanged = requestedProvider !== actualProvider || requestedModel !== actualModel;
    const promptTokens = Number.isFinite(Number(debug.prompt_tokens)) ? Number(debug.prompt_tokens) : null;
    const completionTokens = Number.isFinite(Number(debug.completion_tokens)) ? Number(debug.completion_tokens) : null;
    const totalTokens = Number.isFinite(Number(debug.total_tokens)) ? Number(debug.total_tokens) : null;
    const confidenceLabel = debug?.confidence?.label || 'unknown';
    const confidenceScore = Number(debug?.confidence?.score || 0);
    const verifyPassed = !!debug?.verification?.passed;
    const hallucinationRisk = debug?.hallucination?.risk || 'low';
    const requestClass = String(debug?.request_class || 'GENERAL_INFORMATION');
    const riskLevel = String(debug?.risk_level || 'low');
    const responseMode = String(debug?.response_mode || 'general_information');
    const activeValidators = Array.isArray(debug?.active_validators) ? debug.active_validators : [];
    const evidenceRequired = !!debug?.evidence_required;
    const toolRequired = !!debug?.tool_required;
    const validatorCount = Number.isFinite(Number(debug?.validator_count)) ? Number(debug.validator_count) : activeValidators.length;
    const regenerationCount = Number.isFinite(Number(debug?.regeneration_count)) ? Number(debug.regeneration_count) : 0;
    const classificationMs = Number.isFinite(Number(debug?.classification_ms)) ? Number(debug.classification_ms) : null;
    const generationMs = Number.isFinite(Number(debug?.generation_ms)) ? Number(debug.generation_ms) : null;
    const validationMs = Number.isFinite(Number(debug?.validation_ms)) ? Number(debug.validation_ms) : null;
    const totalLatencyMs = Number.isFinite(Number(debug?.total_latency_ms)) ? Number(debug.total_latency_ms) : null;

    document.getElementById('devBody').innerHTML = `
        <div class="dev-section">
            <div class="dev-section-title">Model</div>
            <div class="dev-row">
                <span class="dev-label">Actual model</span>
                <span class="dev-value accent">${escapeHtml(actualModel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Actual provider</span>
                <span class="dev-value accent">${escapeHtml(actualProvider)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Requested route</span>
                <span class="dev-value ${providerChanged ? 'warn' : ''}">${escapeHtml(requestedProvider)} / ${escapeHtml(requestedModel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Requested status</span>
                <span class="dev-value ${(requestedHttpCode && requestedHttpCode >= 400) ? 'bad' : ''}">${requestedHttpCode || 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Response time</span>
                <span class="dev-value ${msColor}">${debug.groq_ms}ms</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Classify latency</span>
                <span class="dev-value">${classificationMs !== null ? `${classificationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Generation latency</span>
                <span class="dev-value">${generationMs !== null ? `${generationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Validation latency</span>
                <span class="dev-value">${validationMs !== null ? `${validationMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Total latency</span>
                <span class="dev-value">${totalLatencyMs !== null ? `${totalLatencyMs}ms` : 'n/a'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Trace ID</span>
                <span class="dev-value accent">${escapeHtml(String(debug.trace_id || '--'))}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Confidence</span>
                <span class="dev-value ${confidenceLabel === 'high' ? 'good' : (confidenceLabel === 'medium' ? 'warn' : 'bad')}">${escapeHtml(confidenceLabel)} ${confidenceScore ? `(${confidenceScore})` : ''}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Request class</span>
                <span class="dev-value accent">${escapeHtml(requestClass)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Risk level</span>
                <span class="dev-value ${riskLevel === 'very_high' || riskLevel === 'high' ? 'bad' : (riskLevel === 'medium' ? 'warn' : 'good')}">${escapeHtml(riskLevel)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Response mode</span>
                <span class="dev-value">${escapeHtml(responseMode)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Active validators</span>
                <span class="dev-value">${escapeHtml(activeValidators.length ? activeValidators.join(', ') : 'lightweight')}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Evidence required</span>
                <span class="dev-value ${evidenceRequired ? 'warn' : 'good'}">${evidenceRequired ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Tool required</span>
                <span class="dev-value ${toolRequired ? 'warn' : 'good'}">${toolRequired ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Validator count</span>
                <span class="dev-value">${validatorCount}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Regeneration count</span>
                <span class="dev-value">${regenerationCount}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Self-verify</span>
                <span class="dev-value ${verifyPassed ? 'good' : 'warn'}">${verifyPassed ? 'pass' : 'check needed'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Hallucination risk</span>
                <span class="dev-value ${hallucinationRisk === 'low' ? 'good' : (hallucinationRisk === 'medium' ? 'warn' : 'bad')}">${escapeHtml(hallucinationRisk)}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Checkpoint</span>
                <span class="dev-value">saved: ${escapeHtml(String(debug?.checkpoint?.saved || 'n/a'))} • applied: ${escapeHtml(String(debug?.checkpoint?.applied || 'n/a'))}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Provider fallback</span>
                <span class="dev-value ${debug.fallback_used ? 'warn' : 'good'}">${debug.fallback_used ? 'Yes' : 'No'}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">HTTP status</span>
                <span class="dev-value ${debug.http_code && debug.http_code >= 400 ? 'bad' : ''}">${debug.http_code || 'n/a'}</span>
            </div>
            ${debug.requested_error ? `
            <div class="dev-row">
                <span class="dev-label">Requested error</span>
                <span class="dev-value bad">${escapeHtml(debug.requested_error)}</span>
            </div>` : ''}
            <div class="dev-row">
                <span class="dev-label">Provider tokens</span>
                <span class="dev-value">${totalTokens !== null ? `${promptTokens ?? 0} in / ${completionTokens ?? 0} out / ${totalTokens} total` : 'Not returned by provider'}</span>
            </div>
            ${debug.llm_error ? `
            <div class="dev-row">
                <span class="dev-label">Provider error</span>
                <span class="dev-value bad">${escapeHtml(debug.llm_error)}</span>
            </div>` : ''}
            <div class="dev-row">
                <span class="dev-label">Messages sent</span>
                <span class="dev-value">${debug.messages_sent}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Messages trimmed</span>
                <span class="dev-value ${debug.messages_trimmed > 0 ? 'warn' : 'good'}">${debug.messages_trimmed}</span>
            </div>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">Plan & Usage</div>
            <div class="dev-row">
                <span class="dev-label">Plan</span>
                <span class="dev-value accent">${debug.plan}</span>
            </div>
            <div class="dev-row" style="gap:8px;align-items:center">
                <span class="dev-label" style="white-space:nowrap">${usageCount} / ${usageLimit >= 99999999 ? '∞' : usageLimit} tokens</span>
                <div class="dev-bar-wrap"><div class="dev-bar" style="width:${pct}%"></div></div>
                <span class="dev-value">${pct}%</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Credits remaining</span>
                <span class="dev-value ${debug.credits > 0 ? 'good' : 'bad'}">${debug.credits}</span>
            </div>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">Dataset RAG</div>
            <div class="dev-row">
                <span class="dev-label">Matches found</span>
                <span class="dev-value ${debug.dataset_matches > 0 ? 'good' : 'bad'}">${debug.dataset_matches}</span>
            </div>
            <div class="dev-row">
                <span class="dev-label">Search method</span>
                <span class="dev-value accent">${debug.dataset_method}</span>
            </div>
            ${(debug.dataset_snippets || []).map((s, i) => `
            <div style="background:#0a0a0f;border:1px solid #1e1e2e;border-radius:6px;padding:6px 8px;margin-top:4px;font-size:10px;">
                <span style="color:#64748b">#${s.id} [${s.method}] score:${s.score}</span><br>
                <span style="color:#a78bfa">${escapeHtml(s.q)}…</span>
            </div>`).join('')}
            <button onclick="window.open('/pages/dataset_manager','_blank')" style="margin-top:8px;width:100%;padding:6px;background:rgba(124,58,237,0.15);border:1px solid #7c3aed;color:#a78bfa;border-radius:6px;font-family:'DM Mono',monospace;font-size:10px;cursor:pointer;">⚙ Open Dataset Manager</button>
        </div>

        <div class="dev-section">
            <div class="dev-section-title">System Prompt</div>
            <div class="dev-prompt">${escapeHtml(debug.system_prompt)}</div>
        </div>
    `;

    // Auto-open panel when new debug data arrives on desktop only.
    const panel = document.getElementById('devPanel');
    const fab   = document.getElementById('devFab');
    if (window.matchMedia('(min-width: 768px)').matches && !panel.classList.contains('open')) {
        panel.classList.add('open');
        fab.classList.add('panel-open');
    }
}


function escapeHtml(text) {
    return String(text).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── MARKDOWN RENDERER ──
(function setupMarked() {
    // Configure marked to use highlight.js for syntax highlighting
    marked.setOptions({
        highlight: function(code, lang) {
            if (lang && hljs.getLanguage(lang)) {
                try { return hljs.highlight(code, { language: lang }).value; } catch(e) {}
            }
            return hljs.highlightAuto(code).value;
        },
        breaks: true,
        gfm: true,
    });

    // Custom renderer — wrap code blocks with header + copy button
    const renderer = new marked.Renderer();
    renderer.code = function(code, lang) {
        const language  = lang || 'plaintext';
        const langLabel = lang || 'code';
        const id        = 'cb-' + Math.random().toString(36).slice(2,8);

        let highlighted;
        if (lang && hljs.getLanguage(lang)) {
            try { highlighted = hljs.highlight(code, { language: lang }).value; }
            catch(e) { highlighted = hljs.highlightAuto(code).value; }
        } else {
            highlighted = hljs.highlightAuto(code).value;
        }

        return `<div class="code-block" id="${id}">
            <div class="code-block-header">
                <span class="code-lang">${escapeHtml(langLabel)}</span>
                <button class="code-copy-btn" onclick="copyCode('${id}',this)">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    Copy
                </button>
            </div>
            <pre><code class="hljs language-${escapeHtml(language)}">${highlighted}</code></pre>
        </div>`;
    };
    marked.use({ renderer });
})();

function renderMarkdown(text) {
    return marked.parse(text || '');
}

function renderAssistantActionRow(replyText, includeActions = true) {
    if (!includeActions) return '';
    const replyId = 'reply_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
    replyActionStore[replyId] = String(replyText || '');
    return `<div class="assistant-action-row">
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('tasks','${replyId}')">Save tasks</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('continue','${replyId}')">Next step</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('execute','${replyId}')">Take action</button>
        <button type="button" class="assistant-action-btn" onclick="runReplyAction('summary','${replyId}')">Summarize</button>
    </div>`;
}

function closeThoughtBubbles() {
    document.querySelectorAll('.thought-bubble.open').forEach(el => {
        el.classList.remove('open');
        el.setAttribute('aria-expanded', 'false');
    });
}

function toggleThoughtBubble(event, el) {
    if (!el) return;
    event.preventDefault();
    event.stopPropagation();
    const shouldOpen = !el.classList.contains('open');
    closeThoughtBubbles();
    if (shouldOpen) {
        el.classList.add('open');
        el.setAttribute('aria-expanded', 'true');
    }
}

function handleThoughtBubbleKey(event, el) {
    if (event.key === 'Enter' || event.key === ' ') {
        toggleThoughtBubble(event, el);
    } else if (event.key === 'Escape') {
        closeThoughtBubbles();
        el?.blur();
    }
}

document.addEventListener('click', event => {
    if (!event.target.closest('.thought-bubble')) closeThoughtBubbles();
});

function renderThoughtBubble(thinking) {
    const text = String(thinking || '').trim();
    if (!text) return '';
    const summary = text.length > 140 ? `${text.slice(0, 137).trim()}...` : text;
    return ` <span class="thought-bubble" tabindex="0" role="button" aria-expanded="false" aria-label="View Lyralink's reasoning for this reply" onclick="toggleThoughtBubble(event, this)" onkeydown="handleThoughtBubbleKey(event, this)"><span class="thought-bubble-icon">💭</span><span class="thought-bubble-content">${escapeHtml(text)}</span><span class="thought-bubble-label">Reasoning</span></span>`;
}

function renderGeneratedImageCard(imageUrl, prompt, downloadUrl = '', madeBy = 'Lyralink') {
    if (!imageUrl) return '';
    const safePrompt = escapeHtml(String(prompt || 'Generated image'));
    const safeMadeBy = escapeHtml(String(madeBy || 'Lyralink'));
    const previewUrl = escapeHtml(String(imageUrl));
    const downloadLink = downloadUrl ? escapeHtml(String(downloadUrl)) : previewUrl;
    return `<figure class="generated-image-card">
        <img class="generated-image-preview" src="${previewUrl}" alt="${safePrompt}" loading="lazy" referrerpolicy="no-referrer">
        <figcaption class="generated-image-caption">
            <div class="generated-image-title">${safePrompt}</div>
            <div class="generated-image-meta">Made by ${safeMadeBy}</div>
            <div class="generated-image-actions">
                <a class="generated-image-download" href="${downloadLink}" target="_blank" rel="noopener">Download image</a>
            </div>
        </figcaption>
    </figure>`;
}

function buildAssistantMessageHtml(reply, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, thinking = '', generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const imageCard = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || reply || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    return `<div class="msg ai"><div class="msg-role-label">Lyralink${renderThoughtBubble(thinking)}</div><div class="bubble">${imageCard}${renderMarkdown(reply || '')}${codeTestCard}${moltNotice}${renderAssistantActionRow(reply || '', includeActions)}</div></div>`;
}

function buildStreamingAssistantBubbleHtml(text, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const imageCard = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    const safeText = escapeHtml(String(text || ''));
    return `${imageCard}<div class="assistant-streaming-note">Generating response...</div><div class="assistant-streaming-text">${safeText}</div>${codeTestCard}${moltNotice}${renderAssistantActionRow('', includeActions)}`;
}

function sleep(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
}

function chunkTextForStreaming(text) {
    const source = String(text || '');
    if (!source) return [];
    const chunks = [];
    const parts = source.match(/\s+|[^\s]+/g) || [];
    let buffer = '';
    for (const part of parts) {
        buffer += part;
        if (buffer.length >= 18 || /[.!?]\s*$/.test(buffer) || /\n\s*$/.test(buffer)) {
            chunks.push(buffer);
            buffer = '';
        }
    }
    if (buffer) chunks.push(buffer);
    return chunks;
}

async function streamAssistantMessage(messageId, reply, codeTestCard = '', moltNotice = '', includeActions = aiAssistOptions.taskMode, thinking = '', generatedImageUrl = '', imagePrompt = '', generatedImageDownloadUrl = '', madeBy = 'Lyralink') {
    const root = document.getElementById(messageId);
    const bubble = root?.querySelector('.bubble');
    if (!root || !bubble) {
        const chatbox = document.getElementById('chatbox');
        if (chatbox) chatbox.innerHTML += buildAssistantMessageHtml(reply, codeTestCard, moltNotice, includeActions, thinking, generatedImageUrl, imagePrompt, generatedImageDownloadUrl, madeBy);
        return;
    }

    const roleLabel = root.querySelector('.msg-role-label');
    if (roleLabel) {
        const existingThought = roleLabel.querySelector('.thought-bubble');
        if (existingThought) existingThought.remove();
        const thoughtBubbleHtml = renderThoughtBubble(thinking);
        if (thoughtBubbleHtml) roleLabel.insertAdjacentHTML('beforeend', thoughtBubbleHtml);
    }

    const text = String(reply || '');
    const previousText = String(root.dataset.renderedReply || '');
    const currentMessageKey = String(messageId || '');
    const priorMessageKey = String(root.dataset.renderMessageId || '');

    if (priorMessageKey && priorMessageKey !== currentMessageKey) {
        return;
    }
    if (previousText && previousText.length > 0 && text.length < previousText.length) {
        return;
    }

    root.dataset.renderMessageId = currentMessageKey;
    root.dataset.renderedReply = text;

    bubble.innerHTML = buildStreamingAssistantBubbleHtml(text, codeTestCard, moltNotice, includeActions, generatedImageUrl, imagePrompt, generatedImageDownloadUrl, madeBy);
    const chatbox = document.getElementById('chatbox');
    if (chatbox) chatbox.scrollTop = chatbox.scrollHeight;

    const chunks = chunkTextForStreaming(text);
    if (chunks.length === 0) {
        bubble.innerHTML = `${generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : ''}${renderMarkdown(text)}${codeTestCard}${moltNotice}${renderAssistantActionRow(text, includeActions)}`;
        return;
    }

    const imageHtml = generatedImageUrl ? renderGeneratedImageCard(generatedImageUrl, imagePrompt || text || 'Generated image', generatedImageDownloadUrl || generatedImageUrl, madeBy) : '';
    const actionsHtml = renderAssistantActionRow(text, includeActions);
    let visibleText = '';
    for (let i = 0; i < chunks.length; i++) {
        visibleText += chunks[i];
        bubble.innerHTML = `${imageHtml}<div class="assistant-streaming-note">Generating response...</div><div class="assistant-streaming-text">${escapeHtml(visibleText)}</div>${codeTestCard}${moltNotice}${actionsHtml}`;
        if (i < chunks.length - 1) {
            await sleep(18 + Math.min(42, chunks[i].length * 2));
        }
    }

    bubble.innerHTML = `${imageHtml}${renderMarkdown(text)}${codeTestCard}${moltNotice}${actionsHtml}`;
}

function renderCodeTestCard(report) {
    if (!report || !report.enabled) return '';
    const rows = Array.isArray(report.results) ? report.results : [];
    const hasFailure = rows.some(r => ['failed', 'error'].includes(r.status));
    const statusClass = hasFailure ? 'fail' : 'pass';
    const reportId = 'vrep_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
    validationReportStore[reportId] = report;
    const lines = rows.map(r => {
        const runtime = r.runtime ? ` (${r.runtime})` : '';
        const title = `${r.lang || 'code'}: ${r.status || 'unknown'}${runtime}`;
        const note = r.note ? `<div class="code-test-line">${escapeHtml(r.note)}</div>` : '';
        return `<div class="code-test-line"><strong>${escapeHtml(title)}</strong>${note}<div>${escapeHtml(r.output || '')}</div></div>`;
    }).join('');
    const summary = escapeHtml(report.summary || 'Validation completed.');
    const action = hasFailure
        ? `<div class="code-test-actions"><button class="code-test-fix-btn" onclick="requestValidationFix('${reportId}')">Fix failing code</button></div>`
        : '';
    return `<div class="code-test-card ${statusClass}"><div class="code-test-title">Sandbox Validation</div><div class="code-test-summary">${summary}</div>${lines}${action}</div>`;
}

function buildValidationFixPrompt(report) {
    const rows = Array.isArray(report?.results) ? report.results : [];
    const failed = rows.filter(r => ['failed', 'error'].includes(r.status));
    const details = failed.map(r => {
        const runtime = r.runtime ? ` (${r.runtime})` : '';
        const output = String(r.output || '').slice(0, 1200);
        return `- ${r.lang || 'code'}: ${r.status || 'failed'}${runtime}\n${output}`;
    }).join('\n\n');

    return [
        'Fix the code from your previous answer so it passes validation.',
        'Return complete corrected code blocks only, with proper language fences.',
        'Do not include explanations before or after the code.',
        '',
        'Validation errors:',
        details || '- Validation failed, but no details were captured.',
    ].join('\n');
}

async function requestValidationFix(reportId) {
    const report = validationReportStore[reportId];
    if (!report) {
        showToast('Validation details are no longer available', 'error');
        return;
    }
    const prompt = buildValidationFixPrompt(report);
    const input = document.getElementById('userInput');
    if (!input) return;
    input.innerText = prompt;
    await sendMessage();
}

function copyCode(blockId, btn) {
    const block = document.getElementById(blockId);
    const code  = block ? block.querySelector('code') : null;
    if (!code) return;
    navigator.clipboard.writeText(code.innerText).then(() => {
        btn.classList.add('copied');
        btn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 18 4 13"/></svg> Copied!`;
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copy`;
        }, 2000);
    });
}

// ── DISCORD SYNC ──
