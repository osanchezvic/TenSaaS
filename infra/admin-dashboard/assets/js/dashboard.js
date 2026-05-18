/**
 * TenSaaS Dashboard Logic — v3
 * Compatible con el rediseño Terminal Noir
 */

(function () {
  const Dashboard = {
    metricsChart: null,
    pollingIntervals: [],

    init() {
      this.setupCharts();
      this.startPolling();
      this.updateAll();
    },

    /* ── CHARTS ──────────────────────────────────────────── */
    setupCharts() {
      const ctx = document.getElementById('metricsChart')?.getContext('2d');
      if (!ctx) return;

      const light = document.documentElement.classList.contains('light');
      const tc    = light ? '#475569' : '#64748b';
      const gc    = light ? 'rgba(0,0,0,.06)' : 'rgba(255,255,255,.06)';

      this.metricsChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: [],
          datasets: [
            {
              label: 'CPU %',
              data: [],
              borderColor: '#38bdf8',
              backgroundColor: 'rgba(56,189,248,0.08)',
              borderWidth: 1.5,
              tension: 0.45,
              fill: true,
              pointRadius: 0,
              pointHoverRadius: 3,
            },
            {
              label: 'RAM %',
              data: [],
              borderColor: '#a78bfa',
              backgroundColor: 'rgba(167,139,250,0.08)',
              borderWidth: 1.5,
              tension: 0.45,
              fill: true,
              pointRadius: 0,
              pointHoverRadius: 3,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { intersect: false, mode: 'index' },
          scales: {
            y: {
              beginAtZero: true, max: 100,
              grid: { color: gc },
              ticks: { color: tc, font: { family: "'JetBrains Mono'", size: 9 }, callback: v => v + '%' },
              border: { color: 'transparent' },
            },
            x: {
              grid: { display: false },
              ticks: { color: tc, font: { family: "'JetBrains Mono'", size: 9 }, maxTicksLimit: 8 },
              border: { color: 'transparent' },
            },
          },
          plugins: {
            legend: {
              display: true, position: 'bottom',
              labels: { color: tc, boxWidth: 8, usePointStyle: true, pointStyle: 'circle',
                        font: { family: "'JetBrains Mono'", size: 9 } },
            },
            tooltip: {
              backgroundColor: 'rgba(13,19,32,0.95)',
              borderColor: 'rgba(255,255,255,0.08)', borderWidth: 1,
              titleColor: '#94a3b8', bodyColor: '#e2e8f0',
              titleFont: { family: "'JetBrains Mono'", size: 9 },
              bodyFont:  { family: "'JetBrains Mono'", size: 10 },
              padding: 10,
              callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)}%` },
            },
          },
        },
      });
    },

    /* ── POLLING ─────────────────────────────────────────── */
    startPolling() {
      this.pollingIntervals.push(setInterval(() => this.updateMetrics(), 5000));
      this.pollingIntervals.push(setInterval(() => this.updateContainersStatus(), 10000));
      this.pollingIntervals.push(setInterval(() => this.updateApiStatus(), 15000));
    },

    async updateAll() {
      await Promise.all([this.updateMetrics(), this.updateContainersStatus(), this.updateApiStatus()]);
    },

    /* ── METRICS ─────────────────────────────────────────── */
    async updateMetrics() {
      try {
        const r = await fetch('?action=get_metrics');
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const data = await r.json();
        if (!this.metricsChart) return;

        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const MAX = 20;

        if (this.metricsChart.data.labels.length >= MAX) {
          this.metricsChart.data.labels.shift();
          this.metricsChart.data.datasets[0].data.shift();
          this.metricsChart.data.datasets[1].data.shift();
        }

        this.metricsChart.data.labels.push(time);
        this.metricsChart.data.datasets[0].data.push(data.cpu ?? 0);
        this.metricsChart.data.datasets[1].data.push(data.ram ?? 0);
        this.metricsChart.update('none');
      } catch (e) {
        console.warn('[TenSaaS] Metrics failed:', e.message);
      }
    },

    /* ── CONTAINER STATUS ────────────────────────────────── */
    async updateContainersStatus() {
      try {
        const r = await fetch('?action=get_real_status');
        if (!r.ok) throw new Error(`HTTP ${r.status}`);

        const text = await r.text();
        if (!text?.trim()) throw new Error('Empty response from API');

        let data;
        try { data = JSON.parse(text); }
        catch { throw new Error('JSON inválido: ' + text.substring(0, 80)); }

        console.debug('[TenSaaS] API:', data);

        let containers = null;
        if (Array.isArray(data))                 containers = data;
        else if (Array.isArray(data.containers)) containers = data.containers;
        else if (Array.isArray(data.data))       containers = data.data;

        if (!containers) {
          console.warn('[TenSaaS] Sin contenedores:', JSON.stringify(data).substring(0, 160));
          this.setAllStatus('unreachable');
          return;
        }

        console.debug(`[TenSaaS] ${containers.length} contenedores`);
        this.renderInfraCards(containers);
        this.renderTenantStatus(containers);

      } catch (e) {
        console.error('[TenSaaS] Status failed:', e.message);
        this.setAllStatus('unreachable');
      }
    },

    setAllStatus(type) {
      document.querySelectorAll('.service-card').forEach(card => {
        const badge = card.querySelector('.status-badge');
        const dot   = card.querySelector('.status-dot');
        if (!badge || !dot) return;
        badge.textContent    = 'Unreachable';
        badge.style.color    = 'var(--warning)';
        dot.style.background = 'var(--warning)';
        dot.style.boxShadow  = 'none';
      });
    },

    /* ── INFRA TABLE ─────────────────────────────────────── */
    renderInfraCards(containers) {
      const wrap = document.getElementById('infra-list');
      if (!wrap) return;

      const corePrefixes = ['infra_', 'npm_'];
      const coreKeywords = ['proxy', 'prometheus', 'grafana', 'authelia', 'portainer',
                            'cloudflared', 'node_exporter', 'redis'];

      const infra = containers.filter(c => {
        if (!c?.name) return false;
        const n = c.name.toLowerCase().replace(/^\//, '');
        const isCore = corePrefixes.some(p => n.startsWith(p)) || coreKeywords.some(k => n.includes(k));
        const isTenantRedis = n.includes('_redis') && !n.startsWith('infra_');
        return isCore && !isTenantRedis;
      }).sort((a, b) => {
        // Running first
        const ra = a.state === 'running' ? 0 : 1;
        const rb = b.state === 'running' ? 0 : 1;
        return ra - rb || (a.name || '').localeCompare(b.name || '');
      });

      // Update summary pill
      const summaryEl = document.getElementById('infra-summary');
      if (summaryEl) {
        const up = infra.filter(c => c.state === 'running').length;
        summaryEl.textContent = `${up}/${infra.length} online`;
        summaryEl.style.color = up === infra.length ? 'var(--success)' : up === 0 ? 'var(--danger)' : 'var(--warning)';
      }

      if (!infra.length) {
        wrap.innerHTML = '<div style="padding:1.25rem;text-align:center;color:var(--muted);font-size:12px">Sin contenedores core detectados.</div>';
        return;
      }

      // Icon SVG per container type (best-effort matching)
      const iconFor = name => {
        if (name.includes('proxy') || name.includes('nginx'))  return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>';
        if (name.includes('prometheus') || name.includes('node_exporter')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>';
        if (name.includes('grafana'))  return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>';
        if (name.includes('auth'))     return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>';
        if (name.includes('portainer'))return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>';
        if (name.includes('cloud') || name.includes('tunnel')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>';
        if (name.includes('redis'))    return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>';
        if (name.includes('fail2ban') || name.includes('watch')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>';
        return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0V4"/>';
      };

      // Parse uptime from status string e.g. "Up 2 days" → "2d" or "Up 3 hours" → "3h"
      const parseUptime = status => {
        if (!status) return '—';
        const m = status.match(/Up\s+([\d]+)\s+(second|minute|hour|day|week|month)/i);
        if (!m) return status.split(' ').slice(0,2).join(' ');
        const unit = m[2].toLowerCase();
        const map  = {second:'s',minute:'m',hour:'h',day:'d',week:'w',month:'mo'};
        return m[1] + (map[unit] || unit[0]);
      };

      wrap.innerHTML = infra.map(c => {
        const state   = c.state || 'unknown';
        const running = state === 'running';
        const paused  = ['paused','restarting'].includes(state);
        const col     = running ? '#34d399' : paused ? '#fbbf24' : '#fb7185';
        const bgcol   = running ? 'rgba(52,211,153,.1)' : paused ? 'rgba(251,191,36,.1)' : 'rgba(251,113,133,.1)';
        const rawName = c.name.replace(/^\//, '');
        const display = rawName
          .replace('infra_','').replace('_global','')
          .replace('global_','').replace('npm_','npm/');
        const img     = (c.image || '').split(':')[0].split('/').pop();
        const uptime  = parseUptime(c.status || '');
        const icon    = iconFor(rawName.toLowerCase());

        return `<div class="infra-row">
          <div class="infra-row-name">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:13px;height:13px;color:var(--muted);flex-shrink:0">${icon}</svg>
            <span class="infra-name-txt">${display}</span>
          </div>
          <div class="infra-image">${img}</div>
          <div>
            <span class="infra-state-badge" style="background:${bgcol};color:${col};border:1px solid ${col}22">
              <span style="width:5px;height:5px;border-radius:50%;background:${col};flex-shrink:0;${running?'box-shadow:0 0 4px '+col:''}"></span>
              ${state}
            </span>
          </div>
          <div class="infra-uptime">${uptime}</div>
        </div>`;
      }).join('');
    },

    /* ── TENANT STATUS ───────────────────────────────────── */
    renderTenantStatus(containers) {
      const cards = document.querySelectorAll('.service-card');

      const map = {};
      containers.forEach(c => {
        if (!c?.name) return;
        map[c.name.toLowerCase().replace(/^\//, '')] = c;
      });

      console.debug('[TenSaaS] Mapa Docker:', Object.keys(map));

      cards.forEach(card => {
        const svc = card.dataset.serviceName?.toLowerCase().trim();
        const emp = card.dataset.empresaName?.toLowerCase().trim();
        if (!svc || !emp) return;

        const exact = `${emp}_${svc}`;
        let info = map[exact];

        if (!info) {
          const k = Object.keys(map).find(n => n.includes(emp) && n.includes(svc));
          if (k) { info = map[k]; console.debug(`[TenSaaS] Parcial: "${exact}" → "${k}"`); }
          else     { console.debug(`[TenSaaS] Sin match: "${exact}"`); }
        }

        const badge = card.querySelector('.status-badge');
        const dot   = card.querySelector('.status-dot');
        if (!badge || !dot) return;

        if (info) {
          const run            = info.state === 'running';
          badge.textContent    = run ? 'Online' : 'Stopped';
          badge.style.color    = run ? 'var(--success)' : 'var(--danger)';
          dot.style.background = run ? 'var(--success)' : 'var(--danger)';
          dot.style.boxShadow  = run ? '0 0 6px var(--success)' : 'none';
        } else {
          badge.textContent    = 'Offline';
          badge.style.color    = 'var(--muted)';
          dot.style.background = 'var(--muted)';
          dot.style.boxShadow  = 'none';
        }
      });
    },

    /* ── SIDEBAR API STATUS ──────────────────────────────── */
    async updateApiStatus() {
      const t0 = performance.now();
      const setRow = (id, up, label) => {
        const dot   = document.getElementById(`dot-${id}`);
        const badge = document.getElementById(`badge-${id}`);
        if (!dot || !badge) return;
        dot.className   = `api-dot ${up ? 'up' : 'down'}`;
        badge.textContent = label;
        badge.className = `api-badge ${up ? 'up' : 'down'}`;
      };

      // Mark all as checking
      ['api','prom','graf','auth','port'].forEach(id => {
        const dot = document.getElementById(`dot-${id}`);
        if (dot) dot.className = 'api-dot checking';
      });

      try {
        const r = await fetch('?action=get_real_status');
        const ms = Math.round(performance.now() - t0);
        const latEl = document.getElementById('api-latency');
        if (latEl) latEl.textContent = `${ms}ms`;

        const text = await r.text();
        let data; try { data = JSON.parse(text); } catch { throw new Error('bad json'); }

        let containers = Array.isArray(data) ? data
          : Array.isArray(data.containers) ? data.containers
          : Array.isArray(data.data) ? data.data : null;

        const apiUp = r.ok && containers !== null;
        setRow('api', apiUp, apiUp ? 'online' : 'error');

        if (containers) {
          const find = kw => containers.find(c => c?.name?.toLowerCase().includes(kw));
          const st   = c => c?.state === 'running';

          const prom = find('prometheus');
          const graf = find('grafana');
          const auth = find('authelia');
          const port = find('portainer');

          setRow('prom', st(prom), prom ? (st(prom) ? 'online' : prom.state) : 'offline');
          setRow('graf', st(graf), graf ? (st(graf) ? 'online' : graf.state) : 'offline');
          setRow('auth', st(auth), auth ? (st(auth) ? 'online' : auth.state) : 'offline');
          setRow('port', st(port), port ? (st(port) ? 'online' : port.state) : 'offline');
        } else {
          ['prom','graf','auth','port'].forEach(id => setRow(id, false, 'unknown'));
        }

      } catch {
        const latEl = document.getElementById('api-latency');
        if (latEl) latEl.textContent = 'timeout';
        ['api','prom','graf','auth','port'].forEach(id => setRow(id, false, 'offline'));
      }
    },

    /* ── DESTROY ─────────────────────────────────────────── */
    async destroyService(empresa, servicio) {
      if (!confirm(`¿Eliminar "${servicio}" de "${empresa}"? Esta acción no se puede deshacer.`)) return;
      this.showNotification('Procesando', `Eliminando ${servicio}...`, 'info');
      try {
        const r = await fetch(`?action=destroy_service&empresa=${encodeURIComponent(empresa)}&servicio=${encodeURIComponent(servicio)}`);
        const d = await r.json();
        if (d.status === 'success') {
          this.showNotification('Eliminado', 'El servicio ha sido destruido.', 'success');
          setTimeout(() => location.reload(), 1500);
        } else {
          this.showNotification('Error', d.message || 'No se pudo eliminar', 'error');
        }
      } catch {
        this.showNotification('Error', 'Error de red', 'error');
      }
    },

    /* ── DELETE COMPANY ──────────────────────────────────── */
    async deleteCompany(empresa) {
      if (!confirm(`¿ELIMINAR COMPLETAMENTE la empresa "${empresa}" y TODOS sus servicios? Esta acción es IRREVERSIBLE.`)) return;
      this.showNotification('Procesando', `Eliminando empresa ${empresa}...`, 'info');
      try {
        const r = await fetch(`?action=delete_company&empresa=${encodeURIComponent(empresa)}`);
        const d = await r.json();
        if (d.status === 'success') {
          this.showNotification('Eliminado', 'La empresa ha sido eliminada del sistema.', 'success');
          setTimeout(() => location.reload(), 1500);
        } else {
          this.showNotification('Error', d.message || 'No se pudo eliminar la empresa', 'error');
        }
      } catch {
        this.showNotification('Error', 'Error de red', 'error');
      }
    },

    /* ── NOTIFICATIONS ───────────────────────────────────── */
    showNotification(title, message, type = 'info') {
      const palette = {
        success: { bg: 'rgba(52,211,153,.1)',  border: 'rgba(52,211,153,.25)',  color: '#34d399' },
        error:   { bg: 'rgba(251,113,133,.1)', border: 'rgba(251,113,133,.25)', color: '#fb7185' },
        info:    { bg: 'rgba(56,189,248,.1)',  border: 'rgba(56,189,248,.25)',  color: '#38bdf8' },
        warning: { bg: 'rgba(251,191,36,.1)',  border: 'rgba(251,191,36,.25)',  color: '#fbbf24' },
      };
      const icons = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        error:   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        info:    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-1.41-6.14L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>',
      };
      const p = palette[type] || palette.info;

      // Toast Container
      let container = document.getElementById('toast-container');
      if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = `position:fixed;bottom:1.25rem;right:1.25rem;z-index:9999;
          display:flex;flex-direction:column;gap:0.5rem;pointer-events:none`;
        document.body.appendChild(container);
      }

      if (!document.getElementById('toast-kf')) {
        const s = document.createElement('style');
        s.id = 'toast-kf';
        s.textContent = '@keyframes toast-in{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}';
        document.head.appendChild(s);
      }

      const el = document.createElement('div');
      el.style.cssText = `display:flex;align-items:center;gap:.625rem;padding:.75rem 1rem;border-radius:10px;
        width:280px;background:${p.bg};border:1px solid ${p.border};color:${p.color};
        font-family:'DM Sans',sans-serif;font-size:12.5px;pointer-events:auto;
        box-shadow:0 8px 32px rgba(0,0,0,.35);animation:toast-in .25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px)`;
      
      el.innerHTML = `
        <svg style="width:15px;height:15px;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24">${icons[type]||icons.info}</svg>
        <div style="flex:1">
          <div style="font-weight:600;line-height:1.2">${title}</div>
          <div style="opacity:.75;font-size:11px;margin-top:2px">${message}</div>
        </div>`;

      container.appendChild(el);
      
      setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(20px)';
        el.style.transition = 'all 0.3s ease';
        setTimeout(() => el.remove(), 300);
      }, 4000);
    },
  };

  window.Dashboard = window.Dashboard || {};
  Object.assign(window.Dashboard, Dashboard);
  document.addEventListener('DOMContentLoaded', () => window.Dashboard.init());
})();

