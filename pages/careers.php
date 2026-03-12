<?php
if (file_exists(__DIR__ . '/../maintenance.flag') && !isset($_COOKIE['lyralink_dev'])) {
    header('Location: /pages/maintenance.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Careers — Lyralink</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <meta name="description" content="Join the team building the future of AI. Open roles at Lyralink.">
    <link rel="canonical" href="https://ai.cloudhavenx.com/pages/careers/">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#08080d; --surface:#0f0f16; --surface2:#14141d; --border:#1c1c28; --border2:#252535;
            --accent:#7c3aed; --accent-light:#a78bfa; --accent-glow:rgba(124,58,237,0.15);
            --text:#e8e8f0; --text-muted:#5a5a7a; --text-dim:#9898b8;
            --green:#22c55e; --green-bg:rgba(34,197,94,0.08); --green-border:rgba(34,197,94,0.2);
            --yellow:#f59e0b;
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

        /* GRAIN TEXTURE */
        body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");pointer-events:none;z-index:0;opacity:0.4}

        /* GRID BG */
        .grid-bg{position:fixed;inset:0;z-index:0;background-image:linear-gradient(rgba(124,58,237,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.03) 1px,transparent 1px);background-size:60px 60px;pointer-events:none}

        /* NAV */
        nav{position:sticky;top:0;z-index:100;padding:0 40px;height:60px;display:flex;align-items:center;gap:16px;background:rgba(8,8,13,0.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border)}
        .nav-logo{height:26px;mix-blend-mode:lighten}
        .nav-sep{width:1px;height:20px;background:var(--border2)}
        .nav-title{font-size:12px;color:var(--text-muted);letter-spacing:0.5px}
        .nav-right{margin-left:auto;display:flex;gap:10px;align-items:center}
        .nav-btn{font-family:'DM Mono',monospace;font-size:11px;padding:6px 14px;border-radius:6px;text-decoration:none;transition:all 0.2s;cursor:pointer;border:none}
        .nav-btn.ghost{background:none;border:1px solid var(--border2);color:var(--text-muted)}
        .nav-btn.ghost:hover{border-color:var(--accent);color:var(--accent-light)}
        .nav-btn.primary{background:var(--accent);color:#fff}
        .nav-btn.primary:hover{background:#6d28d9}

        /* LAYOUT */
        .page{max-width:1100px;margin:0 auto;padding:0 24px 80px;position:relative;z-index:1}

        /* HERO */
        .hero{padding:80px 0 60px;border-bottom:1px solid var(--border)}
        .hero-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border2);border-radius:4px;padding:4px 12px;font-size:10px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;margin-bottom:24px}
        .hero-tag-dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:blink 2s infinite}
        @keyframes blink{0%,100%{opacity:1}50%{opacity:0.3}}
        .hero h1{font-family:'Syne',sans-serif;font-size:clamp(38px,6vw,72px);font-weight:800;line-height:1.05;letter-spacing:-2px;margin-bottom:20px}
        .hero h1 em{font-style:normal;color:var(--accent-light)}
        .hero-sub{font-size:14px;color:var(--text-dim);max-width:500px;line-height:1.8;margin-bottom:32px}
        .hero-stats{display:flex;gap:40px}
        .hero-stat-num{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--text)}
        .hero-stat-label{font-size:11px;color:var(--text-muted);margin-top:2px}

        /* FILTERS */
        .filters{padding:28px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--border)}
        .filter-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.5px;margin-right:4px}
        .filter-btn{font-family:'DM Mono',monospace;font-size:11px;padding:5px 14px;border-radius:4px;border:1px solid var(--border2);background:none;color:var(--text-muted);cursor:pointer;transition:all 0.15s}
        .filter-btn:hover,.filter-btn.active{border-color:var(--accent);color:var(--accent-light);background:var(--accent-glow)}
        .filter-sep{width:1px;height:18px;background:var(--border2);margin:0 4px}

        /* MAIN GRID */
        .content-grid{display:grid;grid-template-columns:1fr 380px;gap:28px;padding-top:32px;align-items:start}
        @media(max-width:860px){.content-grid{grid-template-columns:1fr}}

        /* JOB LIST */
        .jobs-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
        .jobs-count{font-size:12px;color:var(--text-muted)}
        .job-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:22px 24px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;position:relative;overflow:hidden}
        .job-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:transparent;transition:background 0.2s}
        .job-card:hover,.job-card.active{border-color:var(--border2);background:var(--surface2)}
        .job-card:hover::before,.job-card.active::before{background:var(--accent)}
        .job-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
        .job-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700}
        .job-dept{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--accent-light);margin-bottom:4px}
        .job-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
        .badge{padding:3px 10px;border-radius:3px;font-size:10px;font-weight:500;border:1px solid}
        .badge-type{border-color:var(--border2);color:var(--text-muted)}
        .badge-loc{border-color:var(--border2);color:var(--text-dim)}
        .badge-new{border-color:var(--green-border);color:var(--green);background:var(--green-bg)}
        .job-salary{font-size:12px;color:var(--text-dim);white-space:nowrap}

        /* JOB DETAIL PANEL */
        .job-detail{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:28px;position:sticky;top:80px;max-height:calc(100vh - 100px);overflow-y:auto}
        .job-detail::-webkit-scrollbar{width:4px}
        .job-detail::-webkit-scrollbar-track{background:transparent}
        .job-detail::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
        .detail-empty{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:13px}
        .detail-empty-icon{font-size:32px;margin-bottom:12px;opacity:0.3}
        .detail-dept{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--accent-light);margin-bottom:8px}
        .detail-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:14px;line-height:1.2}
        .detail-meta{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)}
        .detail-section{margin-bottom:20px}
        .detail-section-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:10px}
        .detail-body{font-size:13px;color:var(--text-dim);line-height:1.9;white-space:pre-wrap}
        .detail-apply-btn{width:100%;padding:13px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:8px;letter-spacing:0.3px}
        .detail-apply-btn:hover{background:#6d28d9;transform:translateY(-1px)}

        /* EMPTY / LOADING */
        .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted)}
        .empty-icon{font-size:36px;margin-bottom:16px;opacity:0.3}
        .skeleton{background:linear-gradient(90deg,var(--surface) 25%,var(--surface2) 50%,var(--surface) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:6px}
        @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

        /* APPLICATION MODAL */
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:500;display:none;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;backdrop-filter:blur(4px)}
        .modal-overlay.open{display:flex}
        .modal{background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:36px;max-width:640px;width:100%;margin:auto;position:relative}
        .modal-close{position:absolute;top:16px;right:16px;background:none;border:1px solid var(--border2);color:var(--text-muted);width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:14px;transition:all 0.2s}
        .modal-close:hover{border-color:var(--accent);color:var(--accent-light)}
        .modal-job-label{font-size:11px;color:var(--accent-light);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px}
        .modal-job-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-bottom:24px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
        .form-row.full{grid-template-columns:1fr}
        .field{display:flex;flex-direction:column;gap:5px}
        .field label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted)}
        .field input,.field select,.field textarea{background:var(--bg);border:1px solid var(--border2);color:var(--text);border-radius:7px;padding:10px 13px;font-family:'DM Mono',monospace;font-size:12px;outline:none;transition:border-color 0.2s}
        .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--accent)}
        .field select option{background:var(--bg)}
        .field textarea{resize:vertical;min-height:120px;line-height:1.7}
        .field .hint{font-size:10px;color:var(--text-muted);margin-top:2px}
        .field-required::after{content:' *';color:var(--accent-light)}
        .file-drop{border:1px dashed var(--border2);border-radius:7px;padding:20px;text-align:center;cursor:pointer;transition:all 0.2s;font-size:12px;color:var(--text-muted)}
        .file-drop:hover,.file-drop.drag{border-color:var(--accent);color:var(--accent-light);background:var(--accent-glow)}
        .file-drop .file-name{color:var(--green);margin-top:4px;font-size:11px}
        .submit-btn{width:100%;padding:14px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;transition:all 0.2s;margin-top:8px;letter-spacing:0.3px}
        .submit-btn:hover:not(:disabled){background:#6d28d9}
        .submit-btn:disabled{opacity:0.5;cursor:not-allowed}
        .form-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:7px;padding:10px 14px;font-size:12px;color:#ef4444;margin-bottom:14px;display:none}
        .form-success{background:var(--green-bg);border:1px solid var(--green-border);border-radius:7px;padding:20px;text-align:center;display:none}
        .form-success .success-icon{font-size:36px;margin-bottom:10px}
        .form-success h3{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:6px;color:var(--green)}
        .form-success p{font-size:12px;color:var(--text-dim);line-height:1.7}

        /* PERKS STRIP */
        .perks-strip{margin:48px 0;padding:32px;background:var(--surface);border:1px solid var(--border);border-radius:10px}
        .perks-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:20px}
        .perks-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
        .perk-item{display:flex;align-items:flex-start;gap:10px}
        .perk-icon{font-size:18px;flex-shrink:0;margin-top:1px}
        .perk-text{font-size:12px;color:var(--text-dim);line-height:1.6}
        .perk-text strong{color:var(--text);display:block;font-size:12px;margin-bottom:2px}

        /* REVEAL */
        .reveal{opacity:0;transform:translateY(12px);transition:opacity 0.5s,transform 0.5s}
        .reveal.visible{opacity:1;transform:none}

        /* FOOTER */
        footer{border-top:1px solid var(--border);padding:28px 24px;text-align:center;position:relative;z-index:1;margin-top:40px}
        footer a{color:var(--text-muted);text-decoration:none;font-size:12px;margin:0 10px}
        footer a:hover{color:var(--accent-light)}
        .footer-copy{font-size:11px;color:var(--border2);margin-top:10px}

        @media(max-width:600px){
            .hero{padding:48px 0 36px} .hero h1{font-size:36px;letter-spacing:-1px}
            .hero-stats{gap:24px} .form-row{grid-template-columns:1fr}
            .modal{padding:24px 18px}
        }
        @media(max-width:480px){
            nav{padding:0 16px;height:54px}
            .nav-btn{display:none}
            .nav-btn.primary{display:inline-block;font-size:11px;padding:5px 10px}
            .page{padding:0 16px 60px}
            .hero{padding:36px 0 28px}
            .hero h1{font-size:clamp(28px,8vw,36px);letter-spacing:-0.5px}
            .hero-sub{font-size:13px}
            .hero-stats{flex-direction:column;gap:12px}
            .filters{gap:6px;padding:20px 0}
            .filter-btn{font-size:11px;padding:5px 10px}
            .job-card{padding:18px}
            .job-card-header{flex-direction:column;gap:10px;align-items:flex-start}
            .job-tags{flex-wrap:wrap}
            .job-meta{flex-wrap:wrap;gap:6px}
            .values-grid{grid-template-columns:1fr !important}
            .perks-grid{grid-template-columns:1fr 1fr !important}
            input,select,textarea{font-size:16px}
            .modal-overlay{padding:0}
            .modal{border-radius:0;height:100%;overflow-y:auto}
        }
    </style>
</head>
<body>
<div class="grid-bg"></div>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <div class="nav-sep"></div>
    <span class="nav-title">Careers</span>
    <div class="nav-right">
        <a href="/chat" class="nav-btn ghost">← Back</a>
    </div>
</nav>

<div class="page">

    <!-- HERO -->
    <div class="hero reveal">
        <div class="hero-tag"><span class="hero-tag-dot"></span>Now Hiring</div>
        <h1>Build the future<br>of <em>AI</em> with us.</h1>
        <p class="hero-sub">We're a small, ambitious team pushing the boundaries of what AI can do. Every role here has outsized impact. No bureaucracy. Ship things that matter.</p>
        <div class="hero-stats">
            <div><div class="hero-stat-num" id="statJobs">—</div><div class="hero-stat-label">Open Roles</div></div>
            <div><div class="hero-stat-num">100%</div><div class="hero-stat-label">Remote</div></div>
            <div><div class="hero-stat-num">&lt; 2w</div><div class="hero-stat-label">Hiring Speed</div></div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="filters reveal">
        <span class="filter-label">Filter</span>
        <button class="filter-btn active" onclick="setFilter('dept','',this)">All Departments</button>
        <div class="filter-sep" id="deptSep" style="display:none"></div>
        <div id="deptFilters"></div>
        <div class="filter-sep"></div>
        <button class="filter-btn active" id="typeAll" onclick="setTypeFilter('',this)">All Types</button>
        <button class="filter-btn" onclick="setTypeFilter('full_time',this)">Full-time</button>
        <button class="filter-btn" onclick="setTypeFilter('part_time',this)">Part-time</button>
        <button class="filter-btn" onclick="setTypeFilter('contract',this)">Contract</button>
        <button class="filter-btn" onclick="setTypeFilter('internship',this)">Internship</button>
    </div>

    <!-- PERKS STRIP -->
    <div class="perks-strip reveal">
        <div class="perks-title">Why Lyralink?</div>
        <div class="perks-grid">
            <div class="perk-item"><div class="perk-icon">🌍</div><div class="perk-text"><strong>Fully Remote</strong>Work from anywhere in the world, async-first culture.</div></div>
            <div class="perk-item"><div class="perk-icon">⚡</div><div class="perk-text"><strong>Move Fast</strong>No red tape. Ideas ship in days, not quarters.</div></div>
            <div class="perk-item"><div class="perk-icon">🧠</div><div class="perk-text"><strong>AI-First Workplace</strong>Use the latest AI tools every day — you're building them.</div></div>
            <div class="perk-item"><div class="perk-icon">📈</div><div class="perk-text"><strong>Equity</strong>Everyone gets a stake in what they're building.</div></div>
            <div class="perk-item"><div class="perk-icon">🎯</div><div class="perk-text"><strong>Ownership</strong>Small team = massive scope. You own entire product surfaces.</div></div>
            <div class="perk-item"><div class="perk-icon">💻</div><div class="perk-text"><strong>Top Equipment</strong>Best-in-class hardware and software budget, no questions asked.</div></div>
        </div>
    </div>

    <!-- JOB LISTINGS -->
    <div class="content-grid">
        <div>
            <div class="jobs-header reveal">
                <span class="jobs-count" id="jobsCount">Loading...</span>
            </div>
            <div id="jobList"></div>
        </div>
        <div>
            <div class="job-detail" id="jobDetail">
                <div class="detail-empty">
                    <div class="detail-empty-icon">📋</div>
                    Select a role to view details
                </div>
            </div>
        </div>
    </div>

</div>

<footer>
    <a href="/">Home</a>
    <a href="/pages/support/">Support</a>
    <a href="/pages/tos/">Terms</a>
    <a href="/pages/status/">Status</a>
    <div class="footer-copy">© <?= date('Y') ?> Lyralink · An ARXD Hosting Company</div>
</footer>

<!-- APPLICATION MODAL -->
<div class="modal-overlay" id="applyModal">
    <div class="modal">
        <button class="modal-close" onclick="closeApply()">✕</button>
        <div class="modal-job-label" id="modalDept"></div>
        <div class="modal-job-title" id="modalTitle">Apply</div>

        <div class="form-error" id="formError"></div>
        <div class="form-success" id="formSuccess">
            <div class="success-icon">✅</div>
            <h3>Application Sent!</h3>
            <p>Thanks for applying. We review every application personally and will be in touch within a few days if there's a match.</p>
        </div>

        <div id="applicationForm">
            <div class="form-row">
                <div class="field"><label class="field-required">Full Name</label><input type="text" id="appName" placeholder="Your full name"></div>
                <div class="field"><label class="field-required">Email</label><input type="email" id="appEmail" placeholder="you@example.com"></div>
            </div>
            <div class="form-row">
                <div class="field"><label>Phone</label><input type="tel" id="appPhone" placeholder="+1 (555) 000-0000"></div>
                <div class="field"><label>Location</label><input type="text" id="appLocation" placeholder="City, Country"></div>
            </div>
            <div class="form-row">
                <div class="field"><label>LinkedIn</label><input type="url" id="appLinkedin" placeholder="linkedin.com/in/yourname"></div>
                <div class="field"><label>Portfolio / GitHub</label><input type="url" id="appPortfolio" placeholder="github.com/you"></div>
            </div>
            <div class="form-row">
                <div class="field"><label>Years of Experience</label>
                    <select id="appExperience">
                        <option value="">Select...</option>
                        <option value="0-1">0–1 years (Entry level)</option>
                        <option value="1-3">1–3 years</option>
                        <option value="3-5">3–5 years</option>
                        <option value="5-10">5–10 years</option>
                        <option value="10+">10+ years</option>
                    </select>
                </div>
            </div>
            <div class="form-row full">
                <div class="field">
                    <label class="field-required">Cover Letter</label>
                    <textarea id="appCover" placeholder="Tell us why you want this role and what makes you a great fit. Be specific — generic cover letters don't get through."></textarea>
                    <span class="hint">Minimum 50 characters. Quality > length.</span>
                </div>
            </div>
            <div class="form-row full">
                <div class="field">
                    <label>Resume / CV</label>
                    <div class="file-drop" id="fileDrop" onclick="document.getElementById('resumeFile').click()" ondragover="dragOver(event)" ondragleave="dragLeave(event)" ondrop="dropFile(event)">
                        <div>📄 Click or drag your resume here</div>
                        <div class="hint">PDF, DOC, DOCX — max 5MB</div>
                        <div class="file-name" id="fileName"></div>
                    </div>
                    <input type="file" id="resumeFile" accept=".pdf,.doc,.docx" style="display:none" onchange="fileSelected(this)">
                </div>
            </div>
            <button class="submit-btn" id="submitBtn" onclick="submitApplication()">Submit Application →</button>
        </div>
    </div>
</div>

<script>
let jobs = [], activeJobId = null, activeFilter = { dept: '', type: '' };

// ── LOAD JOBS ──
async function loadJobs() {
    const params = new URLSearchParams({ action: 'list_jobs', ...activeFilter });
    const data   = await fetch('/api/careers.php?' + params).then(r => r.json()).catch(() => null);
    if (!data?.success) { document.getElementById('jobList').innerHTML = '<div class="empty-state"><div class="empty-icon">⚠️</div>Failed to load jobs.</div>'; return; }

    jobs = data.jobs || [];
    renderJobs();

    // Build dept filters
    const depts = data.departments || [];
    if (depts.length > 1) {
        document.getElementById('deptSep').style.display = 'block';
        document.getElementById('deptFilters').innerHTML = depts.map(d =>
            `<button class="filter-btn${activeFilter.dept===d?' active':''}" onclick="setFilter('dept','${escHtml(d)}',this)">${escHtml(d)}</button>`
        ).join('');
    }
    document.getElementById('statJobs').textContent = jobs.length;
    document.getElementById('jobsCount').textContent = `${jobs.length} open position${jobs.length!==1?'s':''}`;
}

function renderJobs() {
    const list = document.getElementById('jobList');
    if (!jobs.length) {
        list.innerHTML = '<div class="empty-state"><div class="empty-icon">🔭</div>No open positions right now.<br>Check back soon.</div>';
        return;
    }
    list.innerHTML = jobs.map(j => {
        const isNew  = (Date.now() - new Date(j.created_at)) < 7 * 24 * 60 * 60 * 1000;
        const salary = formatSalary(j);
        return `<div class="job-card${activeJobId===j.id?' active':''}" onclick="selectJob(${j.id})">
            <div class="job-card-top">
                <div>
                    <div class="job-dept">${escHtml(j.department)}</div>
                    <div class="job-title">${escHtml(j.title)}</div>
                </div>
                ${salary ? `<div class="job-salary">${salary}</div>` : ''}
            </div>
            <div class="job-badges">
                <span class="badge badge-type">${formatType(j.type)}</span>
                <span class="badge badge-loc">📍 ${escHtml(j.location)}</span>
                ${isNew ? '<span class="badge badge-new">NEW</span>' : ''}
            </div>
        </div>`;
    }).join('');
}

// ── JOB DETAIL ──
async function selectJob(id) {
    activeJobId = id;
    renderJobs();
    const detail = document.getElementById('jobDetail');
    detail.innerHTML = '<div class="detail-empty"><div class="skeleton" style="height:14px;width:80px;margin:0 auto 12px"></div><div class="skeleton" style="height:22px;width:60%;margin:0 auto 20px"></div><div class="skeleton" style="height:200px;margin-top:20px"></div></div>';
    const data = await fetch(`/api/careers.php?action=get_job&id=${id}`).then(r => r.json()).catch(() => null);
    if (!data?.success) { detail.innerHTML = '<div class="detail-empty">Failed to load.</div>'; return; }
    const j = data.job;
    const salary = formatSalary(j);
    detail.innerHTML = `
        <div class="detail-dept">${escHtml(j.department)}</div>
        <div class="detail-title">${escHtml(j.title)}</div>
        <div class="detail-meta">
            <span class="badge badge-type">${formatType(j.type)}</span>
            <span class="badge badge-loc">📍 ${escHtml(j.location)}</span>
            ${salary ? `<span class="badge badge-type">💰 ${salary}</span>` : ''}
        </div>
        <div class="detail-section">
            <div class="detail-section-label">About this role</div>
            <div class="detail-body">${escHtml(j.description)}</div>
        </div>
        <div class="detail-section">
            <div class="detail-section-label">Requirements</div>
            <div class="detail-body">${escHtml(j.requirements)}</div>
        </div>
        ${j.perks ? `<div class="detail-section"><div class="detail-section-label">Perks & Benefits</div><div class="detail-body">${escHtml(j.perks)}</div></div>` : ''}
        <button class="detail-apply-btn" onclick="openApply(${j.id},'${escHtml(j.title)}','${escHtml(j.department)}')">Apply for this role →</button>
    `;
    // Scroll detail panel to top
    detail.scrollTop = 0;
}

// ── FILTERS ──
function setFilter(type, val, el) {
    activeFilter[type] = val;
    if (type === 'dept') {
        document.querySelectorAll('#deptFilters .filter-btn, .filters > .filter-btn').forEach(b => {
            if (b.textContent === 'All Departments' || b.closest('#deptFilters')) b.classList.remove('active');
        });
    }
    el.classList.add('active');
    activeJobId = null;
    loadJobs();
}
function setTypeFilter(val, el) {
    activeFilter.type = val;
    document.querySelectorAll('.filters .filter-btn').forEach(b => {
        if (['All Types','Full-time','Part-time','Contract','Internship'].includes(b.textContent.trim())) b.classList.remove('active');
    });
    el.classList.add('active');
    activeJobId = null;
    loadJobs();
}

// ── APPLICATION MODAL ──
let applyJobId = null;
function openApply(jobId, title, dept) {
    applyJobId = jobId;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalDept').textContent  = dept;
    document.getElementById('applyModal').classList.add('open');
    document.getElementById('formError').style.display   = 'none';
    document.getElementById('formSuccess').style.display = 'none';
    document.getElementById('applicationForm').style.display = 'block';
    document.getElementById('appName').focus();
}
function closeApply() {
    document.getElementById('applyModal').classList.remove('open');
    resetForm();
}
document.getElementById('applyModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeApply(); });

function resetForm() {
    ['appName','appEmail','appPhone','appLocation','appLinkedin','appPortfolio','appCover'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('appExperience').value = '';
    document.getElementById('resumeFile').value   = '';
    document.getElementById('fileName').textContent = '';
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').textContent = 'Submit Application →';
}

async function submitApplication() {
    const btn = document.getElementById('submitBtn');
    const err = document.getElementById('formError');
    err.style.display = 'none';

    const fd = new FormData();
    fd.append('action',       'apply');
    fd.append('job_id',       applyJobId);
    fd.append('name',         document.getElementById('appName').value.trim());
    fd.append('email',        document.getElementById('appEmail').value.trim());
    fd.append('phone',        document.getElementById('appPhone').value.trim());
    fd.append('location',     document.getElementById('appLocation').value.trim());
    fd.append('linkedin',     document.getElementById('appLinkedin').value.trim());
    fd.append('portfolio',    document.getElementById('appPortfolio').value.trim());
    fd.append('cover_letter', document.getElementById('appCover').value.trim());
    fd.append('experience',   document.getElementById('appExperience').value);
    const resumeFile = document.getElementById('resumeFile').files[0];
    if (resumeFile) fd.append('resume', resumeFile);

    btn.disabled = true; btn.textContent = 'Sending...';

    const data = await fetch('/api/careers.php', { method:'POST', body:fd }).then(r=>r.json()).catch(()=>null);
    if (data?.success) {
        document.getElementById('applicationForm').style.display = 'none';
        document.getElementById('formSuccess').style.display     = 'block';
    } else {
        err.textContent = data?.error || 'Something went wrong. Please try again.';
        err.style.display = 'block';
        btn.disabled = false; btn.textContent = 'Submit Application →';
    }
}

// ── FILE DRAG & DROP ──
function fileSelected(input) {
    const f = input.files[0];
    document.getElementById('fileName').textContent = f ? '✓ ' + f.name : '';
}
function dragOver(e) { e.preventDefault(); document.getElementById('fileDrop').classList.add('drag'); }
function dragLeave()  { document.getElementById('fileDrop').classList.remove('drag'); }
function dropFile(e)  {
    e.preventDefault(); dragLeave();
    const f = e.dataTransfer.files[0];
    if (!f) return;
    const input = document.getElementById('resumeFile');
    const dt = new DataTransfer(); dt.items.add(f); input.files = dt.files;
    document.getElementById('fileName').textContent = '✓ ' + f.name;
}

// ── UTILS ──
function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function formatType(t) {
    return { full_time:'Full-time', part_time:'Part-time', contract:'Contract', internship:'Internship' }[t] || t;
}
function formatSalary(j) {
    if (!j.salary_min && !j.salary_max) return '';
    const cur = j.salary_currency || 'USD';
    const fmt = n => '$' + (n >= 1000 ? (n/1000).toFixed(0)+'k' : n);
    if (j.salary_min && j.salary_max) return `${fmt(j.salary_min)}–${fmt(j.salary_max)}`;
    if (j.salary_min) return `From ${fmt(j.salary_min)}`;
    return `Up to ${fmt(j.salary_max)}`;
}

// Scroll reveal
const obs = new IntersectionObserver(entries => entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } }), { threshold: 0.05 });
document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

loadJobs();
</script>
</body>
</html>