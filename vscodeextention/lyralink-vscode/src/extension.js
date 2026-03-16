const vscode = require('vscode');
const { execSync, spawn } = require('child_process');
const fs   = require('fs');
const path = require('path');
const os   = require('os');
const http  = require('https');

// ── OUTPUT CHANNEL ──
let outputChannel;
let statusBar;
let diagnosticCollection;

// ── LANGUAGE RUNNERS ──
const RUNNERS = {
    python:     { cmd: 'python3', args: ['{file}'],          ext: '.py'  },
    javascript: { cmd: 'node',    args: ['{file}'],          ext: '.js'  },
    typescript: { cmd: 'ts-node', args: ['{file}'],          ext: '.ts'  },
    ruby:       { cmd: 'ruby',    args: ['{file}'],          ext: '.rb'  },
    php:        { cmd: 'php',     args: ['{file}'],          ext: '.php' },
    go:         { cmd: 'go',      args: ['run', '{file}'],   ext: '.go'  },
    rust:       { cmd: 'cargo',   args: ['script', '{file}'],ext: '.rs'  },
    bash:       { cmd: 'bash',    args: ['{file}'],          ext: '.sh'  },
    c:          { cmd: 'gcc',     args: ['{file}', '-o', '{file}.out', '&&', '{file}.out'], ext: '.c', compile: true },
    cpp:        { cmd: 'g++',     args: ['{file}', '-o', '{file}.out', '&&', '{file}.out'], ext: '.cpp', compile: true },
    java:       { cmd: 'java',    args: ['{file}'],          ext: '.java'},
};

// ── ACTIVATE ──
function activate(context) {
    try {
        outputChannel       = vscode.window.createOutputChannel('Lyralink AI');
        diagnosticCollection = vscode.languages.createDiagnosticCollection('lyralink');

        // Show output panel immediately so we can see logs
        outputChannel.show(true);
        outputChannel.appendLine('[Lyralink] Activating extension...');

        // Status bar item
        statusBar = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
        statusBar.command = 'lyralink.showOutput';
        setStatus('idle');
        statusBar.show();

        // Register commands
        context.subscriptions.push(
            vscode.commands.registerCommand('lyralink.runAndFix',   runAndFix),
            vscode.commands.registerCommand('lyralink.fixSelection', fixSelection),
            vscode.commands.registerCommand('lyralink.explainError', explainError),
            vscode.commands.registerCommand('lyralink.setApiKey',   setApiKey),
            vscode.commands.registerCommand('lyralink.showOutput',  () => outputChannel.show()),
            statusBar,
            diagnosticCollection
        );

        outputChannel.appendLine('[Lyralink] Commands registered.');

        // Check API key on startup
        const config = vscode.workspace.getConfiguration('lyralink');
        const apiKey = config.get('apiKey');
        outputChannel.appendLine('[Lyralink] API key set: ' + (apiKey ? 'YES (' + apiKey.slice(0,6) + '...)' : 'NO'));

        if (!apiKey) {
            vscode.window.showInformationMessage(
                'Lyralink AI: Set your API key to get started.',
                'Set API Key'
            ).then(sel => { if (sel) setApiKey(); });
        }

        outputChannel.appendLine('[Lyralink] Activated successfully ⚡');

    } catch (err) {
        // If outputChannel failed to create, use showErrorMessage as fallback
        const msg = '[Lyralink] Activation error: ' + (err && err.message ? err.message : String(err));
        if (outputChannel) {
            outputChannel.appendLine(msg);
            outputChannel.show(true);
        }
        vscode.window.showErrorMessage(msg);
    }
}

// ── DEACTIVATE ──
function deactivate() {}

// ── SET API KEY ──
async function setApiKey() {
    const key = await vscode.window.showInputBox({
        prompt:      'Enter your Lyralink API key',
        placeHolder: 'lyr_...',
        password:    true,
        ignoreFocusOut: true,
    });
    if (!key) return;
    await vscode.workspace.getConfiguration('lyralink').update('apiKey', key, true);
    vscode.window.showInformationMessage('✓ Lyralink API key saved!');
    log('API key updated.');
}

// ── RUN AND FIX ──
async function runAndFix() {
    try {
        outputChannel.show(true);
        log('\n[Lyralink] runAndFix triggered');

        const editor = vscode.window.activeTextEditor;
        if (!editor) { vscode.window.showWarningMessage('No active file.'); return; }

        const doc      = editor.document;
        const langId   = doc.languageId;
        const runner   = RUNNERS[langId];

        if (!runner) {
            vscode.window.showWarningMessage(`Lyralink: Language "${langId}" not supported for running. Try Fix Selection instead.`);
            return;
        }

        // Save first
        await doc.save();

        const filePath   = doc.fileName;
        const config     = vscode.workspace.getConfiguration('lyralink');
        const maxRetries = config.get('maxRetries') || 3;

        log('\n' + '─'.repeat(60));
        log(`▶ Running: ${path.basename(filePath)}`);
        log('─'.repeat(60));
        setStatus('running');

        let attempt     = 0;
        let currentCode = doc.getText();

        while (attempt < maxRetries) {
            attempt++;
            if (attempt > 1) log(`\n↻ Retry attempt ${attempt}/${maxRetries}...`);

            const result = await runFile(filePath, runner, langId);

            if (result.success) {
                log('\n✓ Ran successfully!\n');
                log('Output:\n' + result.stdout);
                setStatus('success');
                diagnosticCollection.clear();
                vscode.window.showInformationMessage('✓ Code ran successfully!');
                return;
            }

            // Error — log it
            log('\n✗ Error detected:\n');
            log(result.stderr || result.stdout || 'Unknown error');

            if (!config.get('autoFix')) {
                const choice = await vscode.window.showErrorMessage(
                    'Code failed. Want Lyralink AI to fix it?',
                    'Fix It', 'Explain', 'Cancel'
                );
                if (choice === 'Cancel') { setStatus('error'); return; }
                if (choice === 'Explain') { await explainError(result.stderr); setStatus('idle'); return; }
            }

            // Send to AI
            setStatus('thinking');
            log('\n⚡ Sending to Lyralink AI...');

            const fixedCode = await askLyralinkToFix(currentCode, result.stderr || result.stdout, langId);

            if (!fixedCode) {
                log('✗ AI did not return a fix.');
                setStatus('error');
                vscode.window.showErrorMessage('Lyralink AI could not generate a fix.');
                return;
            }

            log('\n✓ AI generated a fix. Applying...');

            // Show diff if enabled
            if (config.get('showDiff') && attempt === 1) {
                const apply = await showDiffAndConfirm(editor, currentCode, fixedCode);
                if (!apply) { setStatus('idle'); return; }
            }

            // Apply fix
            await applyFix(editor, fixedCode);
            await doc.save();
            currentCode = fixedCode;

            log('Fix applied. Re-running...\n');
        }

        setStatus('error');
        log(`\n✗ Could not fix after ${maxRetries} attempts.`);
        vscode.window.showErrorMessage(`Lyralink: Could not fix after ${maxRetries} attempts. Check the output panel.`);

    } catch (err) {
        const msg = '[Lyralink] Unexpected error in runAndFix: ' + (err && err.message ? err.message : String(err));
        log(msg);
        log(err && err.stack ? err.stack : '');
        setStatus('error');
        vscode.window.showErrorMessage(msg);
    }
}

// ── FIX SELECTION ──
async function fixSelection() {
    const editor = vscode.window.activeTextEditor;
    if (!editor || editor.selection.isEmpty) {
        vscode.window.showWarningMessage('Select some code first.');
        return;
    }

    const selection  = editor.selection;
    const langId     = editor.document.languageId;
    const selectedCode = editor.document.getText(selection);

    outputChannel.show(true);
    log('\n' + '─'.repeat(60));
    log('⚡ Sending selection to Lyralink AI...');
    log('─'.repeat(60));
    setStatus('thinking');

    const prompt = `Review and fix this ${langId} code. Return ONLY the corrected code with no explanation, no markdown fences:\n\n${selectedCode}`;
    const fixedCode = await askLyralink(prompt);

    if (!fixedCode) {
        setStatus('error');
        vscode.window.showErrorMessage('Lyralink AI did not return a fix.');
        return;
    }

    const cleaned = stripFences(fixedCode);
    log('\n✓ Fix received. Applying to selection...');

    const apply = await vscode.window.showInformationMessage(
        'Lyralink AI has a fix ready. Apply it?',
        'Apply', 'Preview', 'Cancel'
    );

    if (apply === 'Cancel') { setStatus('idle'); return; }

    if (apply === 'Preview') {
        const tmpDoc = await vscode.workspace.openTextDocument({ content: cleaned, language: langId });
        await vscode.commands.executeCommand('vscode.diff',
            vscode.Uri.parse('untitled:Original'),
            tmpDoc.uri,
            'Lyralink Fix Preview'
        );
        const confirm = await vscode.window.showInformationMessage('Apply this fix?', 'Apply', 'Cancel');
        if (confirm !== 'Apply') { setStatus('idle'); return; }
    }

    await editor.edit(editBuilder => {
        editBuilder.replace(selection, cleaned);
    });

    setStatus('success');
    log('Fix applied to selection.');
    vscode.window.showInformationMessage('✓ Fix applied!');
}

// ── EXPLAIN ERROR ──
async function explainError(errorText) {
    const editor = vscode.window.activeTextEditor;
    const code   = editor ? editor.document.getText() : '';
    const langId = editor ? editor.document.languageId : 'code';

    if (!errorText) {
        errorText = await vscode.window.showInputBox({
            prompt: 'Paste the error message to explain',
            placeHolder: 'TypeError: ...',
            ignoreFocusOut: true,
        });
        if (!errorText) return;
    }

    outputChannel.show(true);
    log('\n⚡ Asking Lyralink to explain the error...');
    setStatus('thinking');

    const prompt = `Explain this ${langId} error in plain English and suggest how to fix it:\n\nError:\n${errorText}\n\n${code ? 'Code:\n' + code.slice(0, 2000) : ''}`;
    const explanation = await askLyralink(prompt);

    if (explanation) {
        log('\n📖 Explanation:\n');
        log(explanation);
        setStatus('idle');
        vscode.window.showInformationMessage('Explanation ready — check the Lyralink output panel.');
    } else {
        setStatus('error');
    }
}

// ── RUN FILE ──
function runFile(filePath, runner, langId) {
    return new Promise((resolve) => {
        const args    = runner.args.map(a => a.replace('{file}', filePath));
        const command = runner.cmd;
        let stdout = '', stderr = '';

        let child;
        try {
            if (runner.compile) {
                // For compiled languages, run as shell command
                const shellCmd = `${command} ${args.join(' ')}`;
                child = spawn('sh', ['-c', shellCmd], { cwd: path.dirname(filePath) });
            } else {
                child = spawn(command, args, { cwd: path.dirname(filePath) });
            }
        } catch (e) {
            resolve({ success: false, stdout: '', stderr: `Failed to start runner: ${e.message}\nIs ${command} installed?` });
            return;
        }

        const timeout = setTimeout(() => {
            child.kill();
            resolve({ success: false, stdout, stderr: stderr + '\nTimeout: execution exceeded 30 seconds.' });
        }, 30000);

        child.stdout.on('data', d => { stdout += d.toString(); });
        child.stderr.on('data', d => { stderr += d.toString(); });

        child.on('close', code => {
            clearTimeout(timeout);
            resolve({ success: code === 0, stdout, stderr });
        });

        child.on('error', err => {
            clearTimeout(timeout);
            resolve({ success: false, stdout, stderr: `Could not run ${command}: ${err.message}\nMake sure ${command} is installed and in your PATH.` });
        });
    });
}

// ── ASK LYRALINK TO FIX ──
async function askLyralinkToFix(code, errorOutput, langId) {
    const prompt = `You are fixing ${langId} code that produced an error. Return ONLY the complete corrected code file with NO explanation, NO markdown code fences, NO commentary. Just the raw fixed code.

Error output:
${errorOutput.slice(0, 1500)}

Current code:
${code.slice(0, 4000)}`;

    const raw = await askLyralink(prompt);
    if (!raw) return null;
    return stripFences(raw);
}

// ── ASK LYRALINK (API call) ──
function askLyralink(prompt) {
    return new Promise((resolve) => {
        const config  = vscode.workspace.getConfiguration('lyralink');
        const apiKey  = config.get('apiKey');
        const apiUrl  = config.get('apiUrl') || 'https://ai.cloudhavenx.com/api/public_api.php';

        if (!apiKey) {
            vscode.window.showErrorMessage('Lyralink: No API key set. Run "Lyralink: Set API Key".', 'Set Key')
                .then(s => { if (s) setApiKey(); });
            resolve(null);
            return;
        }

        const urlObj  = new URL(apiUrl);
        const body    = JSON.stringify({ prompt, stream: false });

        const options = {
            hostname: urlObj.hostname,
            port:     urlObj.port || 443,
            path:     urlObj.pathname + urlObj.search,
            method:   'POST',
            headers:  {
                'Content-Type':  'application/json',
                'Content-Length': Buffer.byteLength(body),
                'X-API-Key':     apiKey,
            },
        };

        const req = http.request(options, res => {
            let data = '';
            res.on('data', chunk => { data += chunk; });
            res.on('end', () => {
                log('[API] Status: ' + res.statusCode);
                log('[API] Raw response: ' + data.slice(0, 500));
                try {
                    const json = JSON.parse(data);
                    if (json.success && json.response) {
                        resolve(json.response);
                    } else if (json.error) {
                        // error can be a string or { code, message } object
                        const errMsg = typeof json.error === 'object'
                            ? (json.error.message || JSON.stringify(json.error))
                            : json.error;
                        log('API error: ' + errMsg);
                        vscode.window.showErrorMessage('Lyralink API: ' + errMsg);
                        resolve(null);
                    } else {
                        log('Unexpected API response: ' + data.slice(0, 300));
                        resolve(null);
                    }
                } catch (e) {
                    log('Failed to parse API response: ' + data.slice(0, 300));
                    resolve(null);
                }
            });
        });

        req.on('error', err => {
            log('Network error: ' + err.message);
            vscode.window.showErrorMessage('Lyralink: Network error — ' + err.message);
            resolve(null);
        });

        req.write(body);
        req.end();
    });
}

// ── APPLY FIX TO EDITOR ──
async function applyFix(editor, newCode) {
    const doc      = editor.document;
    const fullRange = new vscode.Range(
        doc.positionAt(0),
        doc.positionAt(doc.getText().length)
    );
    await editor.edit(editBuilder => {
        editBuilder.replace(fullRange, newCode);
    });
}

// ── SHOW DIFF AND CONFIRM ──
async function showDiffAndConfirm(editor, originalCode, fixedCode) {
    const tmpDir  = os.tmpdir();
    const ext     = path.extname(editor.document.fileName) || '.txt';
    const origTmp = path.join(tmpDir, `lyralink_orig${ext}`);
    const fixTmp  = path.join(tmpDir, `lyralink_fix${ext}`);

    fs.writeFileSync(origTmp, originalCode);
    fs.writeFileSync(fixTmp,  fixedCode);

    await vscode.commands.executeCommand('vscode.diff',
        vscode.Uri.file(origTmp),
        vscode.Uri.file(fixTmp),
        '⚡ Lyralink Fix — Original ↔ Fixed'
    );

    const choice = await vscode.window.showInformationMessage(
        'Apply this AI-generated fix?',
        { modal: false },
        'Apply Fix', 'Skip'
    );

    // Close the diff tab
    await vscode.commands.executeCommand('workbench.action.closeActiveEditor');

    return choice === 'Apply Fix';
}

// ── STRIP MARKDOWN FENCES ──
function stripFences(text) {
    // Remove ```lang ... ``` wrappers if AI accidentally includes them
    return text
        .replace(/^```[\w]*\n?/m, '')
        .replace(/\n?```\s*$/m, '')
        .trim();
}

// ── STATUS BAR ──
function setStatus(state) {
    const states = {
        idle:     { text: '$(sparkle) Lyralink',       tooltip: 'Lyralink AI — Ready (Ctrl+Shift+R to run)' },
        running:  { text: '$(sync~spin) Running…',     tooltip: 'Lyralink AI — Running code' },
        thinking: { text: '$(loading~spin) AI Fixing…',tooltip: 'Lyralink AI — Generating fix' },
        success:  { text: '$(check) Lyralink',         tooltip: 'Lyralink AI — Ran successfully' },
        error:    { text: '$(error) Lyralink',         tooltip: 'Lyralink AI — Error detected' },
    };
    const s = states[state] || states.idle;
    statusBar.text    = s.text;
    statusBar.tooltip = s.tooltip;
    statusBar.backgroundColor = state === 'error'
        ? new vscode.ThemeColor('statusBarItem.errorBackground')
        : undefined;
}

// ── LOG ──
function log(msg) {
    outputChannel.appendLine(msg);
}

module.exports = { activate, deactivate };