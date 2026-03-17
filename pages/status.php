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
    <title>Lyralink Status</title>
    <link rel="icon" type="image/x-icon" href="/images/cloudhavenx.ico">
    <meta name="description" content="Real-time status and uptime information for all Lyralink services.">
    <link rel="canonical" href="https://status.cloudhavenx.com/">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#0a0a0f; --surface:#111118; --surface2:#16161f; --border:#1e1e2e;
            --accent:#7c3aed; --accent-glow:rgba(124,58,237,0.25); --accent-light:#a78bfa;
            --text:#e2e8f0; --text-muted:#64748b; --text-dim:#94a3b8;
            --op:#22c55e; --op-bg:rgba(34,197,94,0.1); --op-border:rgba(34,197,94,0.25);
            --deg:#f59e0b; --deg-bg:rgba(245,158,11,0.1); --deg-border:rgba(245,158,11,0.25);
            --par:#f97316; --par-bg:rgba(249,115,22,0.1); --par-border:rgba(249,115,22,0.25);
            --maj:#ef4444; --maj-bg:rgba(239,68,68,0.1); --maj-border:rgba(239,68,68,0.25);
            --mai:#38bdf8; --mai-bg:rgba(56,189,248,0.1); --mai-border:rgba(56,189,248,0.25);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html{scroll-behavior:smooth}
        body{font-family:'DM Mono',monospace;background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}

        /* BG */
        .orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0}
        .orb1{top:-300px;left:20%;width:800px;height:600px;background:radial-gradient(ellipse,rgba(124,58,237,0.06) 0%,transparent 65%)}
        .orb2{bottom:-200px;right:-100px;width:500px;height:400px;background:radial-gradient(ellipse,rgba(34,197,94,0.03) 0%,transparent 65%)}

        /* NAV */
        nav{position:sticky;top:0;z-index:100;padding:14px 32px;display:flex;align-items:center;gap:14px;background:rgba(10,10,15,0.9);backdrop-filter:blur(16px);border-bottom:1px solid rgba(30,30,46,0.7)}
        .nav-logo{height:28px;width:auto;mix-blend-mode:lighten}
        .nav-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text-muted)}
        .nav-right{margin-left:auto;display:flex;align-items:center;gap:10px}
        .nav-link{font-size:12px;color:var(--text-muted);text-decoration:none;padding:5px 12px;border:1px solid var(--border);border-radius:20px;transition:all 0.2s}
        .nav-link:hover{border-color:var(--accent);color:var(--accent-light)}
        .last-updated{font-size:11px;color:var(--text-muted)}

        /* LAYOUT */
        .page{max-width:860px;margin:0 auto;padding:48px 24px 80px;position:relative;z-index:1}

        /* OVERALL STATUS BANNER */
        .overall-banner{border-radius:18px;padding:32px 36px;margin-bottom:36px;display:flex;align-items:center;gap:20px;border:1px solid;transition:all 0.4s}
        .overall-banner.operational {background:var(--op-bg);border-color:var(--op-border)}
        .overall-banner.degraded    {background:var(--deg-bg);border-color:var(--deg-border)}
        .overall-banner.partial_outage{background:var(--par-bg);border-color:var(--par-border)}
        .overall-banner.major_outage{background:var(--maj-bg);border-color:var(--maj-border)}
        .overall-banner.maintenance {background:var(--mai-bg);border-color:var(--mai-border)}
        .overall-icon{font-size:32px;flex-shrink:0}
        .overall-text h2{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:4px}
        .overall-text p{font-size:12px;opacity:0.7}
        .overall-pulse{width:12px;height:12px;border-radius:50%;flex-shrink:0;margin-left:auto;animation:pulse-ring 2s infinite}
        .operational  .overall-pulse{background:var(--op);box-shadow:0 0 0 0 rgba(34,197,94,0.4)}
        .degraded      .overall-pulse{background:var(--deg);box-shadow:0 0 0 0 rgba(245,158,11,0.4)}
        .partial_outage .overall-pulse{background:var(--par);box-shadow:0 0 0 0 rgba(249,115,22,0.4)}
        .major_outage  .overall-pulse{background:var(--maj);box-shadow:0 0 0 0 rgba(239,68,68,0.4);animation:pulse-ring-red 1s infinite}
        .maintenance   .overall-pulse{background:var(--mai);box-shadow:0 0 0 0 rgba(56,189,248,0.4)}
        @keyframes pulse-ring{0%{box-shadow:0 0 0 0 currentColor}70%{box-shadow:0 0 0 8px transparent}100%{box-shadow:0 0 0 0 transparent}}
        @keyframes pulse-ring-red{0%{box-shadow:0 0 0 0 rgba(239,68,68,0.6)}70%{box-shadow:0 0 0 10px transparent}100%{box-shadow:0 0 0 0 transparent}}

        /* SECTIONS */
        .section{margin-bottom:36px}
        .section-label{font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--text-muted);margin-bottom:12px;padding-left:2px}

        /* SERVICE CARDS */
        .services-group{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:12px}
        .group-header{padding:12px 18px;border-bottom:1px solid var(--border);font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;background:rgba(124,58,237,0.03)}
        .service-row{display:flex;align-items:center;gap:10px;padding:13px 18px;border-bottom:1px solid rgba(30,30,46,0.5);transition:background 0.15s;min-width:0;overflow:hidden}
        .service-row:last-child{border-bottom:none}
        .service-row:hover{background:rgba(124,58,237,0.03)}
        .service-name{flex:0 0 110px;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .service-uptime-bar{display:flex;gap:1.5px;align-items:center;flex:1;min-width:0;overflow:hidden}
        .uptime-day{flex:1;min-width:3px;max-width:8px;height:22px;border-radius:2px;background:var(--border);transition:background 0.2s;cursor:pointer;position:relative}
        .uptime-day.up     {background:var(--op)}
        .uptime-day.down   {background:var(--maj)}
        .uptime-day.partial{background:var(--deg)}
        .uptime-day:hover::after{content:attr(data-tip);position:absolute;bottom:28px;left:50%;transform:translateX(-50%);background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:4px 8px;font-size:10px;white-space:nowrap;color:var(--text);z-index:10;pointer-events:none}
        .uptime-pct{font-size:11px;color:var(--text-muted);width:40px;text-align:right;flex-shrink:0}
        .status-pill{padding:3px 8px;border-radius:20px;font-size:10px;font-weight:700;flex-shrink:0;white-space:nowrap}
        .pill-operational  {background:var(--op-bg);color:var(--op);border:1px solid var(--op-border)}
        .pill-degraded     {background:var(--deg-bg);color:var(--deg);border:1px solid var(--deg-border)}
        .pill-partial_outage{background:var(--par-bg);color:var(--par);border:1px solid var(--par-border)}
        .pill-major_outage {background:var(--maj-bg);color:var(--maj);border:1px solid var(--maj-border)}
        .pill-maintenance  {background:var(--mai-bg);color:var(--mai);border:1px solid var(--mai-border)}

        /* INCIDENTS */
        .incident-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:10px}
        .incident-header{padding:16px 20px;display:flex;align-items:flex-start;gap:12px;border-bottom:1px solid var(--border)}
        .incident-impact{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;flex-shrink:0;margin-top:2px}
        .impact-none    {background:rgba(100,116,139,0.1);color:var(--text-muted);border:1px solid var(--border)}
        .impact-minor   {background:var(--deg-bg);color:var(--deg);border:1px solid var(--deg-border)}
        .impact-major   {background:var(--par-bg);color:var(--par);border:1px solid var(--par-border)}
        .impact-critical{background:var(--maj-bg);color:var(--maj);border:1px solid var(--maj-border)}
        .incident-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;flex:1}
        .incident-time{font-size:11px;color:var(--text-muted);flex-shrink:0;margin-top:2px}
        .incident-updates{padding:0 20px}
        .update-row{padding:12px 0;border-bottom:1px solid rgba(30,30,46,0.5);display:flex;gap:14px}
        .update-row:last-child{border-bottom:none}
        .update-status{font-size:10px;font-weight:700;flex-shrink:0;width:90px;color:var(--text-muted);padding-top:2px}
        .update-status.investigating{color:var(--maj)}
        .update-status.identified  {color:var(--deg)}
        .update-status.monitoring  {color:var(--mai)}
        .update-status.resolved    {color:var(--op)}
        .update-message{font-size:12px;color:var(--text-dim);line-height:1.7;flex:1}
        .update-time{font-size:10px;color:var(--text-muted);flex-shrink:0;padding-top:2px}

        /* NO INCIDENTS */
        .no-incidents{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px;text-align:center;color:var(--text-muted);font-size:13px}
        .no-incidents .ni-icon{font-size:28px;margin-bottom:10px}

        /* HISTORY */
        .history-item{display:flex;gap:16px;padding:14px 0;border-bottom:1px solid rgba(30,30,46,0.4)}
        .history-item:last-child{border-bottom:none}
        .history-date{font-size:11px;color:var(--text-muted);flex-shrink:0;width:80px}
        .history-title{font-size:13px;font-weight:600;margin-bottom:3px}
        .history-msg{font-size:11px;color:var(--text-muted);line-height:1.6}

        /* UPTIME LEGEND */
        .uptime-legend{display:flex;align-items:center;gap:14px;margin-top:8px;font-size:10px;color:var(--text-muted);justify-content:flex-end}
        .legend-dot{width:8px;height:8px;border-radius:2px}

        /* SKELETON */
        .skeleton{background:linear-gradient(90deg,var(--surface) 25%,var(--surface2) 50%,var(--surface) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:6px;height:16px}
        @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

        /* FOOTER */
        footer{border-top:1px solid var(--border);padding:28px 24px;text-align:center;position:relative;z-index:1}
        footer a{color:var(--text-muted);text-decoration:none;font-size:12px;margin:0 10px;transition:color 0.2s}
        footer a:hover{color:var(--accent-light)}
        .footer-copy{font-size:11px;color:#2a2a3a;margin-top:10px}

        /* REVEAL */
        .reveal{opacity:0;transform:translateY(16px);transition:opacity 0.5s,transform 0.5s}
        .reveal.visible{opacity:1;transform:none}

        @media (max-width: 640px) {
            nav { padding: 10px 14px; gap: 8px; }
            nav img { height: 24px; }
            .nav-title { display: none; }
            .nav-link { font-size: 11px; padding: 4px 8px; }
            #lastUpdated { font-size: 10px; }
            .page { padding: 24px 14px 60px; }

            .overall-banner { padding: 20px; gap: 14px; border-radius: 14px; }
            .overall-icon { font-size: 24px; }
            .overall-text h2 { font-size: 17px; }
            .overall-text p { font-size: 11px; }

            .section-label { font-size: 9px; }
            .group-header { padding: 8px 12px; font-size: 9px; }

            /* Stack service rows on small screens */
            .service-row { padding: 10px 12px; gap: 8px; }
            .service-name { flex: 0 0 80px; font-size: 11px; }
            .uptime-pct { width: 34px; font-size: 10px; }
            .status-pill { font-size: 9px; padding: 2px 6px; }
            .uptime-day { min-width: 2px; height: 18px; }
            .uptime-label { padding: 2px 12px 8px; font-size: 9px; }

            .incident-header { padding: 12px 14px; gap: 8px; }
            .incident-title { font-size: 13px; }
            .incident-time { display: none; }
            .incident-updates { padding: 0 14px; }
            .update-row { gap: 8px; flex-wrap: wrap; }
            .update-status { width: auto; font-size: 9px; }
            .update-message { font-size: 11px; width: 100%; }
            .update-time { font-size: 9px; }

            .history-item { gap: 10px; }
            .history-date { width: 60px; font-size: 10px; }
            .history-title { font-size: 12px; }
            .history-msg { font-size: 11px; }

            .legend { flex-wrap: wrap; gap: 8px; padding: 10px 14px; font-size: 10px; }
        }
    </style>
    <link rel="stylesheet" href="/assets/css/mobile.css">
</head>
<body>
<div class="orb orb1"></div>
<div class="orb orb2"></div>

<nav>
    <img src="/assets/lyralinklogo.png" alt="Lyralink" class="nav-logo">
    <span class="nav-title">/ Status</span>
    <div class="nav-right">
        <span class="last-updated" id="lastUpdated">Loading...</span>
        <a href="/chat" class="nav-link"> ← Back</a>
    </div>
</nav>

<div class="page">

    <!-- OVERALL BANNER -->
    <div class="overall-banner operational reveal" id="overallBanner">
        <div class="overall-icon" id="overallIcon">\u2705</div>
        <div class="overall-text">
            <h2 id="overallTitle">All Systems Operational</h2>
            <p id="overallSub">No incidents reported. All services running normally.</p>
        </div>
        <div class="overall-pulse" id="overallPulse"></div>
    </div>

    <!-- SERVICES -->
    <div class="section reveal" id="servicesSection">
        <div class="section-label">Services</div>
        <div id="serviceGroups">
            <div class="services-group">
                <div class="group-header">Loading...</div>
                <div class="service-row"><div class="skeleton" style="width:200px"></div></div>
                <div class="service-row"><div class="skeleton" style="width:160px"></div></div>
            </div>
        </div>
        <div class="uptime-legend">
            <span>90 days ago</span>
            <div style="flex:1;height:1px;background:var(--border)"></div>
            <span>Today</span>
            <div class="legend-dot" style="background:var(--op)"></div><span>Operational</span>
            <div class="legend-dot" style="background:var(--deg)"></div><span>Degraded</span>
            <div class="legend-dot" style="background:var(--maj)"></div><span>Outage</span>
            <div class="legend-dot" style="background:var(--border)"></div><span>No data</span>
        </div>
    </div>

    <!-- ACTIVE INCIDENTS -->
    <div class="section reveal" id="incidentsSection">
        <div class="section-label">Active Incidents</div>
        <div id="activeIncidents">
            <div class="no-incidents"><div class="ni-icon">\u2705</div>No active incidents</div>
        </div>
    </div>

    <!-- INCIDENT HISTORY -->
    <div class="section reveal" id="historySection" style="display:none">
        <div class="section-label">Recent Incident History</div>
        <div class="services-group">
            <div id="incidentHistory" style="padding:0 20px"></div>
        </div>
    </div>

</div>

<footer>
    <a href="/">Lyralink AI</a>
    <a href="/pages/support/">Support</a>
    <a href="https://discord.gg/JhyPNs5Khn" target="_blank">Discord</a>
    <div class="footer-copy"> <?= date('Y') ?> Lyralink | An CloudHavenX Company</div>
</footer>

<script>
const STATUS_META = {
    operational:   { label: 'Operational',    icon: '\u2705', text: 'All Systems Operational',      sub: 'No incidents reported. All services running normally.' },
    degraded:      { label: 'Degraded',        icon: '\u26a0\ufe0f', text: 'Degraded Performance',          sub: 'Some services are experiencing degraded performance.' },
    partial_outage:{ label: 'Partial Outage',  icon: '\ud83d\udfe0', text: 'Partial System Outage',         sub: 'Some services are currently unavailable.' },
    major_outage:  { label: 'Major Outage',    icon: '\ud83d\udd34', text: 'Major Service Outage',           sub: 'We are experiencing a significant service disruption.' },
    maintenance:   { label: 'Maintenance',     icon: '\ud83d\udd27', text: 'Scheduled Maintenance',          sub: 'Maintenance is currently in progress.' },
};

const IMPACT_META = {
    none:     'impact-none',
    minor:    'impact-minor',
    major:    'impact-major',
    critical: 'impact-critical',
};

async function loadStatus() {
    const res  = await fetch('/api/status.php?action=get_status').catch(() => null);
    const data = res ? await res.json().catch(() => null) : null;
    if (!data?.success) {
        document.getElementById('overallTitle').textContent = 'Unable to Load Status';
        document.getElementById('overallSub').textContent   = 'Retrying automatically…';
        document.getElementById('lastUpdated').textContent  = 'Failed — retrying in 30s';
        document.getElementById('serviceGroups').innerHTML  = '<div style="padding:20px;color:#64748b;font-size:13px;text-align:center">Could not load service data. Retrying…</div>';
        return;
    }

    updateBanner(data.overall);
    renderServices(data.services, data.uptime);
    renderIncidents(data.incidents);
    renderHistory(data.resolved);

    const d = new Date(data.generated);
    document.getElementById('lastUpdated').textContent = 'Updated ' + d.toLocaleTimeString('en-US', { timeZone: 'America/New_York' });
}

function updateBanner(overall) {
    const meta   = STATUS_META[overall] || STATUS_META.operational;
    const banner = document.getElementById('overallBanner');
    banner.className = `overall-banner ${overall} reveal visible`;
    document.getElementById('overallIcon').textContent  = meta.icon;
    document.getElementById('overallTitle').textContent = meta.text;
    document.getElementById('overallSub').textContent   = meta.sub;
}

function renderServices(services, uptime) {
    // Group by category
    const groups = {};
    services.forEach(s => {
        if (!groups[s.category]) groups[s.category] = [];
        groups[s.category].push(s);
    });

    const today = new Date();
    const days  = [];
    for (let i = 89; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        days.push(d.toISOString().slice(0, 10));
    }

    let html = '';
    for (const [cat, svcs] of Object.entries(groups)) {
        html += `<div class="services-group">
            <div class="group-header">${escHtml(cat)}</div>`;
        svcs.forEach(s => {
            // uptime keys may be int or string depending on PHP encoding
            const sid = String(s.id);
            let svcUptime = {};
            if (uptime && typeof uptime === 'object') {
                svcUptime = uptime[sid] || uptime[parseInt(sid)] || {};
                // Handle case where PHP encoded as array (sequential int keys)
                if (Array.isArray(uptime)) svcUptime = uptime[parseInt(sid)-1] || {};
            }
            // Build 90-day bars
            let totalDays = 0, uptimeSum = 0;
            const bars = days.map(d => {
                const pct = svcUptime[d] ?? null;
                if (pct !== null) { totalDays++; uptimeSum += pct; }
                let cls = 'uptime-day', tip = '';
                if (pct === null)      { cls = 'uptime-day'; tip = d + ': No data'; }
                else if (pct >= 99.9)  { cls = 'uptime-day up';      tip = d + ': 100%'; }
                else if (pct >= 50)    { cls = 'uptime-day partial';  tip = d + ': ' + pct.toFixed(1) + '%'; }
                else                   { cls = 'uptime-day down';     tip = d + ': ' + pct.toFixed(1) + '%'; }
                return `<div class="${cls}" data-tip="${tip}"></div>`;
            }).join('');

            const avgUptime = totalDays > 0 ? (uptimeSum / totalDays).toFixed(2) : '100.00';
            html += `<div class="service-row">
                <span class="service-name">${escHtml(s.name)}</span>
                <div class="service-uptime-bar">${bars}</div>
                <span class="uptime-pct">${avgUptime}%</span>
                <span class="status-pill pill-${s.status}">${STATUS_META[s.status]?.label || s.status}</span>
            </div>`;
        });
        html += `</div>`;
    }
    document.getElementById('serviceGroups').innerHTML = html || '<div style="padding:20px;color:#64748b;font-size:13px">No services configured.</div>';
}

function renderIncidents(incidents) {
    const el = document.getElementById('activeIncidents');
    if (!incidents.length) {
        el.innerHTML = '<div class="no-incidents"><div class="ni-icon">\u2705</div>No active incidents</div>';
        return;
    }
    el.innerHTML = incidents.map(inc => {
        const updates = (inc.update_messages || inc.updates || []);
        const statuses = (inc.update_statuses || []);
        const times    = (inc.update_times || []);
        const updatesHtml = updates.map((msg, i) => `
            <div class="update-row">
                <span class="update-status ${statuses[i] || ''}">${ucFirst(statuses[i] || '')}</span>
                <span class="update-message">${escHtml(msg)}</span>
                <span class="update-time">${formatDate(times[i])}</span>
            </div>`).join('');
        return `<div class="incident-card">
            <div class="incident-header">
                <span class="incident-impact ${IMPACT_META[inc.impact]}">${ucFirst(inc.impact)}</span>
                <span class="incident-title">${escHtml(inc.title)}</span>
                <span class="incident-time">${formatDate(inc.created_at)}</span>
            </div>
            <div class="incident-updates">${updatesHtml}</div>
        </div>`;
    }).join('');
}

function renderHistory(resolved) {
    const section = document.getElementById('historySection');
    if (!resolved.length) { section.style.display = 'none'; return; }
    section.style.display = 'block';
    document.getElementById('incidentHistory').innerHTML = resolved.map(inc => `
        <div class="history-item">
            <span class="history-date">${formatDate(inc.created_at)}</span>
            <div>
                <div class="history-title">${escHtml(inc.title)}</div>
                <div class="history-msg">${escHtml(inc.last_update || 'Resolved')}</div>
            </div>
        </div>`).join('');
}

function escHtml(t) { return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function ucFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

// Scroll reveal
const obs = new IntersectionObserver(entries => entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } }), { threshold: 0.05 });
document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

// Load + auto-refresh every 60s
loadStatus();
setInterval(loadStatus, 30000);
</script>
</body>
</html>