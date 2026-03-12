<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lyralink — API Docs</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f; --surface: #111118; --border: #1e1e2e;
            --accent: #7c3aed; --accent-glow: rgba(124,58,237,0.3); --accent-light: #a78bfa;
            --text: #e2e8f0; --text-muted: #64748b; --success: #22c55e; --error: #ef4444; --warn: #f59e0b;
            --green: #22c55e; --blue: #38bdf8; --orange: #f59e0b; --pink: #f472b6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Mono', monospace; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
        body::before { content:''; position:fixed; top:-200px; left:30%; width:600px; height:400px; background:radial-gradient(ellipse,rgba(124,58,237,0.08) 0%,transparent 70%); pointer-events:none; z-index:0; }

        /* NAV */
        nav { padding:14px 24px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:rgba(10,10,15,0.9); backdrop-filter:blur(12px); z-index:10; }
        .nav-logo { height:28px; width:auto; mix-blend-mode:lighten; }
        .nav-links { display:flex; gap:8px; margin-left:auto; }
        .nav-link { color:var(--text-muted); text-decoration:none; font-size:12px; border:1px solid var(--border); padding:5px 12px; border-radius:20px; transition:all 0.2s; }
        .nav-link:hover, .nav-link.active { border-color:var(--accent); color:var(--accent-light); }

        /* LAYOUT */
        .layout { display:flex; flex:1; position:relative; z-index:1; }

        /* SIDEBAR TOC */
        .doc-sidebar { width:220px; flex-shrink:0; border-right:1px solid var(--border); padding:24px 0; position:sticky; top:53px; height:calc(100vh - 53px); overflow-y:auto; }
        .doc-sidebar-section { padding:0 16px; margin-bottom:20px; }
        .doc-sidebar-label { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px; }
        .doc-sidebar a { display:block; font-size:12px; color:var(--text-muted); text-decoration:none; padding:5px 8px; border-radius:6px; transition:all 0.15s; margin-bottom:2px; }
        .doc-sidebar a:hover, .doc-sidebar a.active { background:rgba(124,58,237,0.1); color:var(--accent-light); }

        /* CONTENT */
        .doc-content { flex:1; padding:40px 48px 80px; max-width:820px; }

        .doc-section { margin-bottom:52px; scroll-margin-top:70px; }
        .doc-section h2 { font-family:'Syne',sans-serif; font-size:22px; font-weight:800; margin-bottom:6px; }
        .doc-section .section-sub { font-size:12px; color:var(--text-muted); margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid var(--border); }
        .doc-section p { font-size:13px; color:var(--text-muted); line-height:1.8; margin-bottom:12px; }
        .doc-section p strong { color:var(--text); }
        .doc-section ul { list-style:none; display:flex; flex-direction:column; gap:6px; margin:12px 0; }
        .doc-section ul li { font-size:13px; color:var(--text-muted); padding-left:16px; position:relative; }
        .doc-section ul li::before { content:'—'; position:absolute; left:0; color:var(--accent); }

        /* ENDPOINT BLOCK */
        .endpoint { background:var(--surface); border:1px solid var(--border); border-radius:14px; overflow:hidden; margin-bottom:24px; }
        .endpoint-header { padding:14px 18px; display:flex; align-items:center; gap:12px; border-bottom:1px solid var(--border); }
        .method-badge { padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; }
        .method-get { background:rgba(34,197,94,0.15); color:var(--success); border:1px solid rgba(34,197,94,0.3); }
        .endpoint-path { font-size:13px; color:var(--text); }
        .endpoint-desc { font-size:12px; color:var(--text-muted); margin-left:auto; }
        .endpoint-body { padding:18px; }

        /* CODE BLOCK */
        .code-block { background:var(--bg); border:1px solid var(--border); border-radius:10px; overflow:hidden; margin:12px 0; }
        .code-block-header { padding:8px 14px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--border); }
        .code-lang { font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; }
        .copy-code-btn { background:none; border:1px solid var(--border); color:var(--text-muted); border-radius:6px; padding:3px 10px; font-size:10px; cursor:pointer; font-family:'DM Mono',monospace; transition:all 0.2s; }
        .copy-code-btn:hover { border-color:var(--accent); color:var(--accent-light); }
        .code-block pre { padding:16px; overflow-x:auto; font-size:12px; line-height:1.7; }
        .code-block code { font-family:'DM Mono',monospace; }

        /* SYNTAX HIGHLIGHTS */
        .kw  { color:#c084fc; }  /* keyword / method */
        .str { color:#86efac; }  /* string */
        .num { color:#fbbf24; }  /* number */
        .key { color:#38bdf8; }  /* json key */
        .cmt { color:#475569; font-style:italic; }  /* comment */
        .url { color:#fb923c; }  /* url */

        /* PARAMS TABLE */
        .params-table { width:100%; border-collapse:collapse; font-size:12px; margin:12px 0; }
        .params-table th { text-align:left; padding:8px 12px; border-bottom:1px solid var(--border); font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; }
        .params-table td { padding:10px 12px; border-bottom:1px solid rgba(30,30,46,0.5); vertical-align:top; }
        .params-table tr:last-child td { border-bottom:none; }
        .param-name { color:var(--accent-light); }
        .param-required { color:var(--error); font-size:10px; }
        .param-optional { color:var(--text-muted); font-size:10px; }
        .param-type { color:var(--warn); }
        .param-desc { color:var(--text-muted); }

        /* RATE LIMIT TABLE */
        .rate-table { width:100%; border-collapse:collapse; font-size:12px; margin:12px 0; }
        .rate-table th { text-align:left; padding:8px 12px; border-bottom:1px solid var(--border); font-size:10px; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; }
        .rate-table td { padding:10px 12px; border-bottom:1px solid rgba(30,30,46,0.5); }
        .rate-table tr:last-child td { border-bottom:none; }
        .plan-pill { padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; }
        .pill-free { background:rgba(100,116,139,0.2); color:var(--text-muted); }
        .pill-basic { background:rgba(34,197,94,0.15); color:var(--success); }
        .pill-pro { background:rgba(124,58,237,0.2); color:var(--accent-light); }
        .pill-enterprise { background:rgba(255,107,53,0.15); color:#ff6b35; }

        /* CALLOUT */
        .callout { background:rgba(124,58,237,0.08); border:1px solid rgba(124,58,237,0.25); border-radius:10px; padding:14px 16px; margin:14px 0; font-size:13px; color:var(--text-muted); line-height:1.6; }
        .callout.warn { background:rgba(245,158,11,0.07); border-color:rgba(245,158,11,0.25); }
        .callout.error { background:rgba(239,68,68,0.07); border-color:rgba(239,68,68,0.25); }
        .callout strong { color:var(--text); display:block; margin-bottom:4px; }

        /* CTA */
        .cta-bar { background:var(--surface); border:1px solid rgba(124,58,237,0.3); border-radius:14px; padding:24px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; margin-top:40px; }
        .cta-bar h3 { font-family:'Syne',sans-serif; font-size:16px; font-weight:700; margin-bottom:4px; }
        .cta-bar p { font-size:13px; color:var(--text-muted); }
        .btn { padding:9px 18px; border-radius:10px; font-family:'DM Mono',monospace; font-size:12px; cursor:pointer; border:none; text-decoration:none; display:inline-block; transition:all 0.2s; }
        .btn-primary { background:var(--accent); color:white; box-shadow:0 0 12px var(--accent-glow); }
        .btn-primary:hover { background:#6d28d9; }

        /* TOAST */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:10px 18px; font-size:12px; z-index:999; opacity:0; transition:opacity 0.3s; pointer-events:none; }
        .toast.show { opacity:1; }
        .toast.success { border-color:var(--success); color:var(--success); }

        @media (max-width: 768px) {
            nav { padding: 10px 14px; gap: 8px; }
            nav img { height: 24px; }
            .nav-link { font-size: 11px; padding: 4px 8px; }
            .doc-sidebar { display: none; }
            .doc-content { padding: 24px 16px 60px; max-width: 100%; }
            .doc-section h2 { font-size: 18px; }
            .doc-section h3 { font-size: 15px; }
            .endpoint-header { flex-wrap: wrap; gap: 8px; padding: 10px 14px; }
            .endpoint-body { padding: 14px; }
            .code-block pre { font-size: 11px; padding: 12px; }
            table { font-size: 11px; }
            table th, table td { padding: 8px 10px; }
        }
    </style>
</head>
<body>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <div class="nav-links">
        <a href="/pages/api_keys" class="nav-link active">🔑 API Keys</a>
        <a href="/chat" class="nav-link">← Chat</a>
    </div>
</nav>

<div class="layout">

    <!-- SIDEBAR -->
    <div class="doc-sidebar">
        <div class="doc-sidebar-section">
            <div class="doc-sidebar-label">Getting Started</div>
            <a href="#intro">Introduction</a>
            <a href="#auth">Authentication</a>
            <a href="#errors">Errors</a>
            <a href="#rate-limits">Rate Limits</a>
        </div>
        <div class="doc-sidebar-section">
            <div class="doc-sidebar-label">Endpoints</div>
            <a href="#search">Search Dataset</a>
        </div>
        <div class="doc-sidebar-section">
            <div class="doc-sidebar-label">Examples</div>
            <a href="#example-curl">cURL</a>
            <a href="#example-js">JavaScript</a>
            <a href="#example-python">Python</a>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="doc-content">

        <!-- INTRO -->
        <div class="doc-section" id="intro">
            <h2>Lyralink Dataset API</h2>
            <div class="section-sub">Base URL: <span style="color:var(--accent-light)">https://ai.cloudhavenx.com/api/public_api.php</span></div>
            <p>The Lyralink Dataset API lets you search the Lyralink Q&A dataset — a curated collection of real conversations — and retrieve semantically relevant results for any query.</p>
            <p>All requests require an API key. Keys are free to generate from your <a href="/pages/api_keys.php" style="color:var(--accent-light)">account dashboard</a>. Rate limits are determined by your Lyralink plan.</p>

            <div class="callout">
                <strong>Currently in beta</strong>
                The API is in active development. The search endpoint is stable. Additional endpoints may be added in future versions.
            </div>
        </div>

        <!-- AUTH -->
        <div class="doc-section" id="auth">
            <h2>Authentication</h2>
            <div class="section-sub">All requests must include a valid API key</div>
            <p>Pass your API key using any of these methods — all are equivalent:</p>

            <p style="margin-top:16px"><strong>Option 1 — Query parameter</strong></p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">URL</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code><span class="url">https://ai.cloudhavenx.com/api/public_api.php?q=hello&key=lyr_your_key_here</span></code></pre>
            </div>

            <p><strong>Option 2 — X-API-Key header</strong></p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">HTTP</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code>X-API-Key: lyr_your_key_here</code></pre>
            </div>

            <p><strong>Option 3 — Authorization Bearer header</strong></p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">HTTP</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code>Authorization: Bearer lyr_your_key_here</code></pre>
            </div>

            <div class="callout warn">
                <strong>Keep your key secret</strong>
                Never expose your API key in client-side JavaScript or public repositories. If compromised, revoke it immediately from your dashboard and generate a new one.
            </div>
        </div>

        <!-- ERRORS -->
        <div class="doc-section" id="errors">
            <h2>Errors</h2>
            <div class="section-sub">All errors return JSON with a consistent structure</div>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">JSON</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code>{
  <span class="key">"error"</span>: {
    <span class="key">"code"</span>:    <span class="str">"INVALID_KEY"</span>,
    <span class="key">"message"</span>: <span class="str">"Invalid API key."</span>
  }
}</code></pre>
            </div>

            <table class="params-table" style="margin-top:16px">
                <thead><tr><th>HTTP Status</th><th>Error Code</th><th>Meaning</th></tr></thead>
                <tbody>
                    <tr><td>400</td><td><code class="param-name">MISSING_QUERY</code></td><td class="param-desc">The ?q= parameter is missing</td></tr>
                    <tr><td>400</td><td><code class="param-name">QUERY_TOO_LONG</code></td><td class="param-desc">Query exceeds 500 characters</td></tr>
                    <tr><td>401</td><td><code class="param-name">MISSING_KEY</code></td><td class="param-desc">No API key provided</td></tr>
                    <tr><td>401</td><td><code class="param-name">INVALID_KEY</code></td><td class="param-desc">Key not found or incorrect</td></tr>
                    <tr><td>403</td><td><code class="param-name">KEY_DISABLED</code></td><td class="param-desc">Key has been revoked</td></tr>
                    <tr><td>429</td><td><code class="param-name">RATE_LIMITED</code></td><td class="param-desc">Daily request limit reached</td></tr>
                    <tr><td>503</td><td><code class="param-name">DB_ERROR</code></td><td class="param-desc">Service temporarily unavailable</td></tr>
                </tbody>
            </table>
        </div>

        <!-- RATE LIMITS -->
        <div class="doc-section" id="rate-limits">
            <h2>Rate Limits</h2>
            <div class="section-sub">Limits reset daily at midnight UTC</div>
            <p>Your rate limit is determined by your Lyralink account plan. Rate limit headers are included on every response:</p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">Response Headers</span></div>
                <pre><code>X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1740873600</code></pre>
            </div>

            <table class="rate-table" style="margin-top:16px">
                <thead><tr><th>Plan</th><th>Requests / Day</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td><span class="plan-pill pill-free">Free</span></td><td>100</td><td class="param-desc">Default for all accounts</td></tr>
                    <tr><td><span class="plan-pill pill-basic">Basic</span></td><td>500</td><td class="param-desc">$5/mo</td></tr>
                    <tr><td><span class="plan-pill pill-pro">Pro</span></td><td>2,000</td><td class="param-desc">$15/mo</td></tr>
                    <tr><td><span class="plan-pill pill-enterprise">Enterprise</span></td><td>10,000</td><td class="param-desc">$30/mo — also unlocks LLaMA 70b in chat</td></tr>
                </tbody>
            </table>
            <p><a href="/pages/pricing" style="color:var(--accent-light)">Upgrade your plan →</a></p>
        </div>

        <!-- SEARCH ENDPOINT -->
        <div class="doc-section" id="search">
            <h2>Search Dataset</h2>
            <div class="section-sub">Find relevant Q&A pairs from the Lyralink dataset</div>

            <div class="endpoint">
                <div class="endpoint-header">
                    <span class="method-badge method-get">GET</span>
                    <span class="endpoint-path">/api/public_api.php</span>
                    <span class="endpoint-desc">Search Q&A dataset</span>
                </div>
                <div class="endpoint-body">
                    <p style="margin-bottom:14px">Returns the most relevant Q&A entries from the dataset for a given query. Uses keyword matching with embedding similarity as a fallback.</p>

                    <p><strong>Parameters</strong></p>
                    <table class="params-table">
                        <thead><tr><th>Parameter</th><th>Type</th><th>Required</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code class="param-name">q</code></td>
                                <td><span class="param-type">string</span></td>
                                <td><span class="param-required">required</span></td>
                                <td class="param-desc">Your search query. Max 500 characters.</td>
                            </tr>
                            <tr>
                                <td><code class="param-name">key</code></td>
                                <td><span class="param-type">string</span></td>
                                <td><span class="param-required">required*</span></td>
                                <td class="param-desc">Your API key. Can also be passed as a header.</td>
                            </tr>
                            <tr>
                                <td><code class="param-name">limit</code></td>
                                <td><span class="param-type">integer</span></td>
                                <td><span class="param-optional">optional</span></td>
                                <td class="param-desc">Number of results to return. Default: 3, max: 10.</td>
                            </tr>
                        </tbody>
                    </table>

                    <p style="margin-top:16px"><strong>Example Response</strong></p>
                    <div class="code-block">
                        <div class="code-block-header"><span class="code-lang">JSON</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                        <pre><code>{
  <span class="key">"object"</span>:  <span class="str">"search_results"</span>,
  <span class="key">"query"</span>:   <span class="str">"how does photosynthesis work"</span>,
  <span class="key">"count"</span>:   <span class="num">2</span>,
  <span class="key">"results"</span>: [
    {
      <span class="key">"id"</span>:       <span class="num">42</span>,
      <span class="key">"question"</span>: <span class="str">"Can you explain photosynthesis simply?"</span>,
      <span class="key">"answer"</span>:   <span class="str">"Photosynthesis is how plants convert sunlight..."</span>,
      <span class="key">"score"</span>:    <span class="num">2.8431</span>,
      <span class="key">"method"</span>:   <span class="str">"keyword"</span>
    },
    {
      <span class="key">"id"</span>:       <span class="num">17</span>,
      <span class="key">"question"</span>: <span class="str">"What do plants need to make food?"</span>,
      <span class="key">"answer"</span>:   <span class="str">"Plants need sunlight, water, and CO2..."</span>,
      <span class="key">"score"</span>:    <span class="num">0.7812</span>,
      <span class="key">"method"</span>:   <span class="str">"embedding"</span>
    }
  ],
  <span class="key">"meta"</span>: {
    <span class="key">"plan"</span>:                <span class="str">"free"</span>,
    <span class="key">"requests_today"</span>:     <span class="num">4</span>,
    <span class="key">"requests_limit"</span>:     <span class="num">100</span>,
    <span class="key">"requests_remaining"</span>: <span class="num">96</span>
  }
}</code></pre>
                    </div>

                    <p style="margin-top:12px"><strong>Result fields</strong></p>
                    <table class="params-table">
                        <thead><tr><th>Field</th><th>Type</th><th>Description</th></tr></thead>
                        <tbody>
                            <tr><td><code class="param-name">id</code></td><td><span class="param-type">integer</span></td><td class="param-desc">Unique dataset entry ID</td></tr>
                            <tr><td><code class="param-name">question</code></td><td><span class="param-type">string</span></td><td class="param-desc">The stored question</td></tr>
                            <tr><td><code class="param-name">answer</code></td><td><span class="param-type">string</span></td><td class="param-desc">The stored answer</td></tr>
                            <tr><td><code class="param-name">score</code></td><td><span class="param-type">float</span></td><td class="param-desc">Relevance score — higher is more relevant</td></tr>
                            <tr><td><code class="param-name">method</code></td><td><span class="param-type">string</span></td><td class="param-desc"><code>keyword</code>, <code>like</code>, or <code>embedding</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- EXAMPLES -->
        <div class="doc-section" id="example-curl">
            <h2>Examples</h2>
            <div class="section-sub">Copy-paste ready code samples</div>

            <p><strong>cURL</strong></p>
            <div class="code-block" id="example-curl">
                <div class="code-block-header"><span class="code-lang">bash</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code>curl -G https://ai.cloudhavenx.com/api/public_api.php \
  --data-urlencode <span class="str">"q=how does AI work"</span> \
  -d <span class="str">"key=lyr_your_key_here"</span> \
  -d <span class="str">"limit=3"</span></code></pre>
            </div>

            <p id="example-js"><strong>JavaScript (fetch)</strong></p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">javascript</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code><span class="kw">const</span> response = <span class="kw">await</span> fetch(
  <span class="str">'https://ai.cloudhavenx.com/api/public_api.php?q=how+does+AI+work&limit=3'</span>,
  { headers: { <span class="str">'X-API-Key'</span>: <span class="str">'lyr_your_key_here'</span> } }
);

<span class="kw">const</span> data = <span class="kw">await</span> response.json();

data.results.forEach(result => {
  console.log(result.question);
  console.log(result.answer);
  console.log(<span class="str">`Score: ${result.score} via ${result.method}`</span>);
});</code></pre>
            </div>

            <p id="example-python"><strong>Python (requests)</strong></p>
            <div class="code-block">
                <div class="code-block-header"><span class="code-lang">python</span><button class="copy-code-btn" onclick="copyCode(this)">Copy</button></div>
                <pre><code><span class="kw">import</span> requests

response = requests.get(
    <span class="str">"https://ai.cloudhavenx.com/api/public_api.php"</span>,
    params={<span class="str">"q"</span>: <span class="str">"how does AI work"</span>, <span class="str">"limit"</span>: <span class="num">3</span>},
    headers={<span class="str">"X-API-Key"</span>: <span class="str">"lyr_your_key_here"</span>}
)

data = response.json()
<span class="kw">for</span> result <span class="kw">in</span> data[<span class="str">"results"</span>]:
    print(result[<span class="str">"question"</span>])
    print(result[<span class="str">"answer"</span>])
    print(<span class="str">f"Score: {result['score']} via {result['method']}"</span>)</code></pre>
            </div>
        </div>

        <!-- CTA -->
        <div class="cta-bar">
            <div>
                <h3>Ready to build?</h3>
                <p>Generate your free API key and start querying in minutes.</p>
            </div>
            <a href="/pages/api_keys" class="btn btn-primary">Get your API key →</a>
        </div>

    </div>
</div>

<div class="toast" id="toast"></div>

<script>
function copyCode(btn) {
    const pre = btn.closest('.code-block').querySelector('pre');
    const text = pre.innerText;
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = '✓ Copied';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2500);
}

// Highlight active sidebar link on scroll
const sections = document.querySelectorAll('.doc-section[id]');
const links     = document.querySelectorAll('.doc-sidebar a');
window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(s => { if (window.scrollY >= s.offsetTop - 80) current = s.id; });
    links.forEach(l => {
        l.classList.toggle('active', l.getAttribute('href') === '#' + current);
    });
}, { passive: true });
</script>
</body>
</html>