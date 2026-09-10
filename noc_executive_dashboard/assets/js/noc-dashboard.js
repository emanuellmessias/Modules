/**
 * NOC Executive Dashboard - client-side loader & renderers.
 *
 * Fetches aggregated data from the noc.executive.data action and paints the
 * KPI cards, response-time boxes, severity donut, risk gauge and tenant table.
 */
window.NocExecutiveDashboard = (function() {
	'use strict';

	const SEVERITY_COLORS = {
		critical:      '#e45959',
		high:          '#ff9f40',
		medium:        '#f5c451',
		low:           '#2fd0c4',
		informational: '#4f9df0'
	};

	const SEVERITY_LABELS = {
		critical:      'Critical',
		high:          'High',
		medium:        'Medium',
		low:           'Low',
		informational: 'Informational'
	};

	let cfg = null;
	let timer = null;

	function init(config) {
		cfg = config;
		bindFilters();
		load();

		if (cfg.refreshMs > 0) {
			timer = window.setInterval(load, cfg.refreshMs);
		}
	}

	function bindFilters() {
		document.querySelectorAll('.noc-period-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				document.querySelectorAll('.noc-period-btn').forEach(function(b) {
					b.classList.remove('is-active');
				});
				btn.classList.add('is-active');
				cfg.period = btn.getAttribute('data-period');
				load();
			});
		});

		const refresh = document.getElementById('noc-refresh');
		if (refresh) {
			refresh.addEventListener('click', load);
		}

		const exportBtn = document.getElementById('noc-export-pdf');
		if (exportBtn) {
			exportBtn.addEventListener('click', exportPdf);
		}

		const tenant = document.getElementById('noc-tenant-select');
		if (tenant) {
			tenant.addEventListener('change', function() {
				cfg.tenant = tenant.value;
				load();
			});
		}
	}

	function populateTenants(allTenants) {
		const select = document.getElementById('noc-tenant-select');
		if (!select || !allTenants) {
			return;
		}
		// Only build the option list once (the full client list is stable).
		if (select.dataset.populated === '1') {
			return;
		}
		allTenants.forEach(function(name) {
			const opt = document.createElement('option');
			opt.value = name;
			opt.textContent = name;
			select.appendChild(opt);
		});
		select.dataset.populated = '1';
		// Keep "All Tenants" selected unless a tenant is explicitly chosen.
		select.value = cfg.tenant || '';
	}

	function load() {
		setLoading(true, 'Carregando dados da API…');

		// Build the endpoint URL relative to the current Zabbix frontend path.
		const base = (cfg.dataUrl && cfg.dataUrl.length) ? cfg.dataUrl : 'zabbix.php';
		const url = new URL(base, window.location.href);
		url.searchParams.set('action', cfg.action);
		url.searchParams.set('period', cfg.period);
		url.searchParams.set('tenant', cfg.tenant || '');

		fetch(url.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			headers: {'X-Requested-With': 'XMLHttpRequest'}
		})
			.then(function(resp) {
				if (!resp.ok) {
					throw new Error('HTTP ' + resp.status + ' ' + resp.statusText);
				}
				return resp.text();
			})
			.then(function(text) {
				let payload;
				try {
					payload = JSON.parse(text);
				}
				catch (e) {
					throw new Error('Resposta nao-JSON (' + text.slice(0, 120) + ')');
				}

				// Zabbix error envelope.
				if (payload && payload.error) {
					const msg = payload.error.messages
						? payload.error.messages.join('; ')
						: (payload.error.title || 'erro');
					throw new Error('API: ' + msg);
				}

				const data = (payload && payload.main_block)
					? JSON.parse(payload.main_block)
					: payload;

				render(data);
				setLoading(false);
			})
			.catch(function(err) {
				console.error('NOC dashboard load failed', err);
				setLoading(true, 'Falha ao carregar: ' + err.message);
			});
	}

	function setLoading(on, message) {
		const el = document.getElementById('noc-loading');
		if (el) {
			el.style.display = on ? 'flex' : 'none';
			if (on && message) {
				el.textContent = message;
			}
		}
	}

	function render(data) {
		if (!data) {
			return;
		}

		renderCards(data);
		renderTimes(data.times);
		renderSeverity(data.severity);
		renderBacklog(data.backlog);
		renderActions(data.actions);
		renderRisk(data.risk);
		renderTenants(data.tenants);
		renderAnalysts(data.analysts);
		populateTenants(data.all_tenants);
		renderDataAge(data.generated);
	}

	// --- KPI cards ---------------------------------------------------------

	function renderCards(data) {
		setText('kpi-open', fmtNum(data.cards.open));
		setText('kpi-new', fmtNum(data.cards.new));
		setText('kpi-closed', fmtNum(data.cards.closed));
		setText('kpi-unassigned', fmtNum(data.cards.unassigned));
		setText('kpi-automation', fmtNum(data.actions.automation.total));
		setText('kpi-human', fmtNum(data.actions.human.total));
	}

	// --- Response times ----------------------------------------------------

	function renderTimes(times) {
		['mttd', 'mtta', 'mttr_respond', 'mttr_resolve'].forEach(function(key) {
			const t = times[key];
			setText(key + '-value', fmtDuration(t.mean));
			setText(key + '-pct', 'p50 ' + fmtDuration(t.p50) + ' · p90 ' + fmtDuration(t.p90));
		});
	}

	// --- Severity donut ----------------------------------------------------

	function renderSeverity(sev) {
		const canvas = document.getElementById('noc-severity-donut');
		if (!canvas || !canvas.getContext) {
			return;
		}

		const order = ['critical', 'high', 'medium', 'low', 'informational'];
		const segments = order.map(function(k) {
			return {value: sev[k] || 0, color: SEVERITY_COLORS[k], key: k};
		});

		drawDonut(canvas, segments, sev.total || 0, 'ALERTS');
		renderSeverityLegend(sev, order);
	}

	function renderSeverityLegend(sev, order) {
		const legend = document.getElementById('noc-severity-legend');
		if (!legend) {
			return;
		}
		legend.innerHTML = '';
		order.forEach(function(k) {
			const row = document.createElement('div');
			row.className = 'noc-legend-row';
			row.innerHTML =
				'<span class="noc-legend-dot" style="background:' + SEVERITY_COLORS[k] + '"></span>' +
				'<span class="noc-legend-name">' + SEVERITY_LABELS[k] + '</span>' +
				'<span class="noc-legend-val">' + fmtNum(sev[k] || 0) + '</span>';
			legend.appendChild(row);
		});
	}

	// --- Backlog -----------------------------------------------------------

	function renderBacklog(backlog) {
		setText('backlog-new', fmtNum(backlog.new));
		setText('backlog-inprogress', fmtNum(backlog.in_progress));
		setText('backlog-reopened', fmtNum(backlog.reopened));
	}

	// --- Action breakdown --------------------------------------------------

	function renderActions(actions) {
		setText('act-whatsapp', fmtNum(actions.automation.whatsapp));
		setText('act-email', fmtNum(actions.automation.email));
		setText('act-cervello', fmtNum(actions.automation.cervello));
		setText('act-automation-total', fmtNum(actions.automation.total));
		setText('act-human-total', fmtNum(actions.human.total));
	}

	// --- Risk gauge --------------------------------------------------------

	function renderRisk(risk) {
		const canvas = document.getElementById('noc-risk-gauge');
		const score = risk ? (risk.score || 0) : 0;
		setText('noc-risk-value', score + '%');
		if (canvas && canvas.getContext) {
			drawGauge(canvas, score);
		}
	}

	// --- Tenant table ------------------------------------------------------

	function renderTenants(tenants) {
		const table = document.getElementById('noc-tenant-tbody');
		if (!table) {
			return;
		}

		let tbody = table.querySelector('tbody');
		if (!tbody) {
			tbody = document.createElement('tbody');
			table.appendChild(tbody);
		}
		tbody.innerHTML = '';

		if (!tenants || tenants.length === 0) {
			const tr = document.createElement('tr');
			tr.innerHTML = '<td colspan="5" class="noc-empty">' + escapeHtml('Sem dados no período') + '</td>';
			tbody.appendChild(tr);
			return;
		}

		tenants.forEach(function(t) {
			const tr = document.createElement('tr');
			tr.innerHTML =
				'<td class="noc-tenant-name">' + escapeHtml(t.tenant) + '</td>' +
				'<td>' + fmtNum(t.events) + '</td>' +
				'<td>' + fmtNum(t.resolved) + '</td>' +
				'<td class="noc-tenant-auto">' + fmtNum(t.automation) + '</td>' +
				'<td class="noc-tenant-human">' + fmtNum(t.human) + '</td>';
			tbody.appendChild(tr);
		});
	}

	function renderAnalysts(analysts) {
		const table = document.getElementById('noc-analyst-tbody');
		const totalEl = document.getElementById('noc-analyst-total');
		if (!table) {
			return;
		}

		const total = analysts ? (analysts.total || 0) : 0;
		const rows = analysts ? (analysts.analysts || []) : [];

		if (totalEl) {
			totalEl.textContent = ' · ' + fmtNum(total) + ' ações no total';
		}

		let tbody = table.querySelector('tbody');
		if (!tbody) {
			tbody = document.createElement('tbody');
			table.appendChild(tbody);
		}
		tbody.innerHTML = '';

		if (rows.length === 0) {
			const tr = document.createElement('tr');
			tr.innerHTML = '<td colspan="4" class="noc-empty">' + escapeHtml('Sem ações humanas no período') + '</td>';
			tbody.appendChild(tr);
			return;
		}

		rows.forEach(function(a) {
			const tr = document.createElement('tr');
			tr.innerHTML =
				'<td class="noc-analyst-name">' + escapeHtml(a.name) + '</td>' +
				'<td>' + fmtNum(a.events) + '</td>' +
				'<td class="noc-analyst-actions">' + fmtNum(a.actions) + '</td>' +
				'<td class="noc-analyst-pct">' + (a.percent != null ? a.percent : 0) + '%</td>';
			tbody.appendChild(tr);
		});
	}

	function renderDataAge(generated) {
		const el = document.getElementById('noc-data-age');
		if (el && generated) {
			el.textContent = 'Data ' + fmtAge(Date.now() / 1000 - generated) + ' atras';
		}
	}

	// --- Canvas drawing ----------------------------------------------------

	function drawDonut(canvas, segments, centerNum, centerLabel) {
		const ctx = canvas.getContext('2d');
		const w = canvas.width, h = canvas.height;
		const cx = w / 2, cy = h / 2;
		const outer = Math.min(cx, cy) - 6;
		const inner = outer * 0.66;

		ctx.clearRect(0, 0, w, h);

		const total = segments.reduce(function(s, seg) { return s + seg.value; }, 0);
		let start = -Math.PI / 2;

		if (total === 0) {
			ctx.beginPath();
			ctx.arc(cx, cy, outer, 0, Math.PI * 2);
			ctx.arc(cx, cy, inner, 0, Math.PI * 2, true);
			ctx.fillStyle = '#1c2330';
			ctx.fill();
		}
		else {
			segments.forEach(function(seg) {
				if (seg.value <= 0) {
					return;
				}
				const angle = (seg.value / total) * Math.PI * 2;
				ctx.beginPath();
				ctx.arc(cx, cy, outer, start, start + angle);
				ctx.arc(cx, cy, inner, start + angle, start, true);
				ctx.closePath();
				ctx.fillStyle = seg.color;
				ctx.fill();
				start += angle;
			});
		}

		ctx.fillStyle = '#e8ecf3';
		ctx.font = 'bold 30px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText(fmtNum(centerNum), cx, cy - 6);
		ctx.fillStyle = '#7a869a';
		ctx.font = '11px sans-serif';
		ctx.fillText(centerLabel, cx, cy + 16);
	}

	function drawGauge(canvas, score) {
		const ctx = canvas.getContext('2d');
		const w = canvas.width, h = canvas.height;
		const cx = w / 2, cy = h / 2;
		const radius = Math.min(cx, cy) - 14;
		const startA = 0.75 * Math.PI;
		const endA = 2.25 * Math.PI;
		const ticks = 48;

		ctx.clearRect(0, 0, w, h);

		const filled = Math.round((score / 100) * ticks);
		for (let i = 0; i < ticks; i++) {
			const a = startA + (endA - startA) * (i / (ticks - 1));
			const on = i < filled;
			const r1 = radius, r2 = radius - 16;
			ctx.beginPath();
			ctx.lineWidth = 4;
			ctx.strokeStyle = on ? riskColor(score) : '#232b3a';
			ctx.moveTo(cx + Math.cos(a) * r2, cy + Math.sin(a) * r2);
			ctx.lineTo(cx + Math.cos(a) * r1, cy + Math.sin(a) * r1);
			ctx.stroke();
		}
	}

	function riskColor(score) {
		if (score >= 70) { return '#e45959'; }
		if (score >= 40) { return '#f5c451'; }
		return '#4bd07b';
	}

	// --- Formatting helpers ------------------------------------------------

	function setText(id, value) {
		const el = document.getElementById(id);
		if (el) {
			el.textContent = value;
		}
	}

	function fmtNum(n) {
		n = Number(n) || 0;
		if (n >= 1000000) { return (n / 1000000).toFixed(1) + 'M'; }
		if (n >= 1000) { return (n / 1000).toFixed(1) + 'k'; }
		return String(n);
	}

	function fmtDuration(seconds) {
		seconds = Math.max(0, Math.round(Number(seconds) || 0));
		if (seconds === 0) { return '—'; }

		const d = Math.floor(seconds / 86400);
		const h = Math.floor((seconds % 86400) / 3600);
		const m = Math.floor((seconds % 3600) / 60);
		const s = seconds % 60;

		if (d > 0) { return d + 'd ' + h + 'h'; }
		if (h > 0) { return h + 'h ' + m + 'm'; }
		if (m > 0) { return m + 'm ' + s + 's'; }
		return s + 's';
	}

	function fmtAge(seconds) {
		seconds = Math.max(0, Math.round(seconds));
		if (seconds < 60) { return seconds + 's'; }
		const m = Math.floor(seconds / 60);
		if (m < 60) { return m + 'm'; }
		return Math.floor(m / 60) + 'h';
	}

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str == null ? '' : String(str);
		return div.innerHTML;
	}

	// --- PDF export --------------------------------------------------------

	/**
	 * Captures the whole dashboard with html2canvas and produces a real,
	 * multi-page PDF via jsPDF (A4 landscape). Downloads automatically.
	 * Requires window.html2canvas and window.jspdf to be loaded.
	 */
	function exportPdf() {
		const btn = document.getElementById('noc-export-pdf');
		const target = document.querySelector('.noc-exec-inner');

		if (!target) {
			return;
		}

		const h2c = window.html2canvas;
		const jsPdfNs = window.jspdf || window.jsPDF ? (window.jspdf || window) : null;

		if (typeof h2c !== 'function' || !jsPdfNs || !jsPdfNs.jsPDF) {
			alert('Bibliotecas de exportação (html2canvas / jsPDF) não carregaram. '
				+ 'Verifique a pasta assets/js/vendor/ do módulo ou o acesso ao CDN.');
			return;
		}
		const JsPDF = jsPdfNs.jsPDF;

		if (btn) {
			btn.disabled = true;
			btn.textContent = 'Gerando PDF…';
		}

		// Pause auto-refresh so the DOM does not change mid-capture.
		const hadTimer = timer;
		if (timer) {
			window.clearInterval(timer);
			timer = null;
		}

		// Capture each top-level section separately so we can paginate on
		// section boundaries instead of slicing panels in half.
		const sections = Array.prototype.filter.call(
			target.children,
			function(el) { return !el.classList.contains('noc-loading'); }
		);

		const opts = {backgroundColor: '#0d1117', scale: 2, useCORS: true, logging: false};

		Promise.all(sections.map(function(el) { return h2c(el, opts); }))
			.then(function(canvases) {
				const pdf = new JsPDF({orientation: 'landscape', unit: 'pt', format: 'a4'});
				const pageW = pdf.internal.pageSize.getWidth();
				const pageH = pdf.internal.pageSize.getHeight();
				const margin = 20;
				const usableW = pageW - margin * 2;
				const usableH = pageH - margin * 2;

				let cursorY = margin;
				let first = true;

				canvases.forEach(function(canvas) {
					let imgW = usableW;
					let imgH = (canvas.height * imgW) / canvas.width;

					// If a single section is taller than a page, scale it down to fit.
					if (imgH > usableH) {
						const ratio = usableH / imgH;
						imgH = usableH;
						imgW = usableW * ratio;
					}

					// New page if this section would overflow the current one.
					if (!first && cursorY + imgH > pageH - margin) {
						pdf.addPage();
						cursorY = margin;
					}

					const x = margin + (usableW - imgW) / 2;
					pdf.addImage(canvas.toDataURL('image/png'), 'PNG', x, cursorY, imgW, imgH);
					cursorY += imgH + 12;
					first = false;
				});

				const stamp = new Date().toISOString().slice(0, 16).replace('T', '_').replace(':', 'h');
				const tenant = (cfg && cfg.tenant) ? cfg.tenant : 'all-tenants';
				pdf.save('noc-executive_' + tenant + '_' + stamp + '.pdf');
			})
			.catch(function(err) {
				console.error('NOC dashboard: falha ao gerar PDF', err);
				alert('Falha ao gerar o PDF: ' + err.message);
			})
			.finally(function() {
				if (btn) {
					btn.disabled = false;
					btn.textContent = 'Exportar PDF';
				}
				// Resume auto-refresh if it was active.
				if (hadTimer && cfg && cfg.refreshMs > 0) {
					timer = window.setInterval(load, cfg.refreshMs);
				}
			});
	}

	return {init: init};
})();
