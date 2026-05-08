/**
 * TenSaaS Dashboard Logic
 */

(function() {
    const dashboardLogic = {
        metricsChart: null,
        pollingIntervals: [],
        
        init() {
            this.setupCharts();
            this.startPolling();
            this.bindEvents();
            this.updateAll();
        },

        setupCharts() {
            const ctx = document.getElementById('metricsChart')?.getContext('2d');
            if (!ctx) return;

            const isLight = document.documentElement.classList.contains('light');
            const textColor = isLight ? '#475569' : '#94a3b8';

            this.metricsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'CPU %',
                        data: [],
                        borderColor: '#818cf8',
                        backgroundColor: 'rgba(129, 140, 248, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'RAM %',
                        data: [],
                        borderColor: '#a78bfa',
                        backgroundColor: 'rgba(167, 139, 250, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 100, grid: { color: isLight ? 'rgba(0,0,0,0.05)' : 'rgba(255,255,255,0.05)' }, ticks: { color: textColor } },
                        x: { grid: { display: false }, ticks: { color: textColor } }
                    },
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { color: textColor, boxWidth: 12, usePointStyle: true } }
                    }
                }
            });
        },

        bindEvents() {
            document.querySelectorAll('.deploy-form').forEach(form => {
                form.onsubmit = async (e) => {
                    e.preventDefault();
                    const formData = new FormData(form);
                    const btn = form.querySelector('button');
                    const originalText = btn.innerHTML;
                    
                    try {
                        btn.disabled = true;
                        btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                        
                        const response = await fetch('deploy_service.php', {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        
                        const result = await response.json();
                        
                        if (result.status === 'success') {
                            this.showNotification('Éxito', 'Servicio desplegado correctamente', 'success');
                        } else {
                            this.showNotification('Error', result.message || 'Fallo en el despliegue', 'error');
                        }
                    } catch (error) {
                        this.showNotification('Error', 'Error de conexión con el servidor', 'error');
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        this.updateAll();
                    }
                };
            });
        },

        startPolling() {
            this.pollingIntervals.push(setInterval(() => this.updateMetrics(), 5000));
            this.pollingIntervals.push(setInterval(() => this.updateContainersStatus(), 10000));
        },

        async updateAll() {
            await Promise.all([
                this.updateMetrics(),
                this.updateContainersStatus()
            ]);
        },

        async updateMetrics() {
            try {
                const response = await fetch('?action=get_metrics');
                const data = await response.json();
                
                if (!this.metricsChart) return;

                const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                
                if (this.metricsChart.data.labels.length > 15) {
                    this.metricsChart.data.labels.shift();
                    this.metricsChart.data.datasets[0].data.shift();
                    this.metricsChart.data.datasets[1].data.shift();
                }
                
                this.metricsChart.data.labels.push(time);
                this.metricsChart.data.datasets[0].data.push(data.cpu);
                this.metricsChart.data.datasets[1].data.push(data.ram);
                this.metricsChart.update('none');
            } catch (e) { console.error('Metrics update failed', e); }
        },

        async updateContainersStatus() {
            try {
                console.log('--- Actualizando estados ---');
                const response = await fetch('?action=get_real_status');
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                
                const data = await response.json();
                console.log('API Data:', data);
                
                if (data && data.status === 'success' && Array.isArray(data.containers)) {
                    this.renderInfraStatus(data.containers);
                    this.renderTenantStatus(data.containers);
                }
            } catch (e) { 
                console.error('Status update failed', e); 
            }
        },

        async destroyService(empresa, servicio) {
            if (!confirm(`¿Estás seguro de que deseas ELIMINAR el servicio ${servicio} de ${empresa}? Esta acción no se puede deshacer.`)) return;

            this.showNotification('Procesando', `Eliminando ${servicio}...`, 'info');
            
            try {
                const response = await fetch(`?action=destroy_service&empresa=${encodeURIComponent(empresa)}&servicio=${encodeURIComponent(servicio)}`);
                const data = await response.json();
                
                if (data.status === 'success') {
                    this.showNotification('Eliminado', 'El servicio ha sido destruido.', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    this.showNotification('Error', data.message || 'Error al destruir el servicio', 'error');
                }
            } catch (e) {
                this.showNotification('Error', 'Error de red al intentar eliminar el servicio', 'error');
            }
        },

        renderInfraStatus(containers) {
            const container = document.getElementById('infra-list');
            if (!container) return;

            const corePrefixes = ['infra_', 'global_', 'npm_'];
            const coreKeywords = ['proxy', 'api', 'prometheus', 'grafana', 'auth', 'portainer', 'watchtower', 'fail2ban', 'alertmanager', 'dashy'];
            
            const infraContainers = containers.filter(c => {
                if (!c || !c.name) return false;
                const name = c.name.toLowerCase().replace(/^\//, '');
                const isCorePrefix = corePrefixes.some(p => name.startsWith(p));
                const isCoreKeyword = coreKeywords.some(key => name.includes(key));
                const isTenant = !isCorePrefix && name.includes('_') && !isCoreKeyword;
                return (isCorePrefix || isCoreKeyword) && !isTenant;
            });

            container.innerHTML = infraContainers.map(c => {
                const state = c.state || 'unknown';
                const status = c.status || 'No status';
                const image = c.image || 'No image';
                const name = c.name ? c.name.replace(/^\//, '').replace('infra_', '').replace('global_', '') : 'Unknown';

                return `
                <div class="p-6 bg-white/5 rounded-3xl border border-white/5 flex flex-col group hover:bg-white/10 transition-all hover:border-indigo-500/30">
                    <div class="flex items-center justify-between mb-4">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-6 h-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0V4"></path></svg>
                        </div>
                        <span class="px-3 py-1 \${state === 'running' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'} text-[10px] font-black rounded-full uppercase tracking-wider border border-current/10">
                            \${state}
                        </span>
                    </div>
                    <div>
                        <span class="block font-black capitalize text-base text-white group-hover:text-indigo-400 transition-colors">\${name}</span>
                        <span class="block text-[10px] text-slate-500 truncate mt-1 font-bold">\${image}</span>
                    </div>
                    <div class="mt-4 pt-4 border-t border-white/5 flex justify-between items-center">
                        <span class="text-[9px] text-slate-500 uppercase font-black tracking-widest">\${status.split(' ')[0]} \${status.split(' ')[1] || ''}</span>
                        <div class="flex gap-1">
                            <div class="w-1 h-1 rounded-full bg-indigo-500/30"></div>
                            <div class="w-1 h-1 rounded-full bg-indigo-500/30"></div>
                            <div class="w-1 h-1 rounded-full bg-indigo-500/30"></div>
                        </div>
                    </div>
                </div>
            `;}).join('');
        },

        renderTenantStatus(containers) {
            const cards = document.querySelectorAll('.service-card');
            console.log('Emparejando tarjetas:', cards.length);
            
            cards.forEach(card => {
                const serviceName = card.dataset.serviceName;
                const empresaName = card.dataset.empresaName;
                if (!serviceName || !empresaName) return;

                const expectedName = \`\${empresaName}_\${serviceName}\`.toLowerCase();
                
                const dockerInfo = containers.find(c => {
                    if (!c || !c.name) return false;
                    const dName = c.name.toLowerCase().replace(/^\//, '');
                    return dName === expectedName || dName.includes(expectedName);
                });
                
                const statusBadge = card.querySelector('.status-badge');
                const statusDot = card.querySelector('.status-dot');
                
                if (statusBadge && statusDot) {
                    if (dockerInfo) {
                        const isRunning = dockerInfo.state === 'running';
                        statusBadge.textContent = isRunning ? 'Online' : 'Stopped';
                        statusBadge.className = \`status-badge text-[9px] font-black uppercase tracking-widest \${isRunning ? 'text-emerald-400' : 'text-red-400'} italic\`;
                        statusDot.className = \`status-dot w-2 h-2 rounded-full \${isRunning ? 'bg-emerald-500 shadow-[0_0_8px_#10b981]' : 'bg-red-500'}\`;
                    } else {
                        statusBadge.textContent = 'Offline';
                        statusBadge.className = 'status-badge text-[9px] font-black uppercase tracking-widest text-slate-600 italic';
                        statusDot.className = 'status-dot w-2 h-2 rounded-full bg-slate-800';
                    }
                }
            });
        },

        showNotification(title, message, type) {
            const toast = document.createElement('div');
            toast.className = \`fixed bottom-4 right-4 p-4 rounded-2xl border \${type === 'success' ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400' : 'bg-red-500/10 border-red-500/20 text-red-400'} glass z-50 flex items-center shadow-2xl animate-bounce-in\`;
            toast.innerHTML = \`
                <div class="mr-3">
                    \${type === 'success' ? '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>' : '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>'}
                </div>
                <div>
                    <p class="font-bold text-sm">\${title}</p>
                    <p class="text-xs opacity-80">\${message}</p>
                </div>
            \`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }
    };

    window.Dashboard = window.Dashboard || {};
    Object.assign(window.Dashboard, dashboardLogic);
    document.addEventListener('DOMContentLoaded', () => window.Dashboard.init());
})();
