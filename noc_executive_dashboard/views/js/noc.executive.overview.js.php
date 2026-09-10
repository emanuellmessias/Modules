<?php declare(strict_types = 1);

/**
 * Inline JS bootstrap for the NOC Executive Overview.
 *
 * @var CView $this
 * @var array $data
 */
?>

<script src="modules/noc_executive_dashboard/assets/js/noc-dashboard.js"></script>
<script>
	(function() {
		const config = {
			dataUrl: 'zabbix.php',
			action: 'noc.executive.data',
			period: <?= json_encode($data['period']) ?>,
			tenant: <?= json_encode($data['tenant']) ?>,
			refreshMs: 60000
		};

		// Lazily load the PDF export libraries: local (offline) first, CDN fallback.
		function loadScript(src) {
			return new Promise(function(resolve, reject) {
				const s = document.createElement('script');
				s.src = src;
				s.onload = resolve;
				s.onerror = function() { reject(new Error('failed: ' + src)); };
				document.head.appendChild(s);
			});
		}

		function loadWithFallback(local, cdn) {
			return loadScript(local).catch(function() { return loadScript(cdn); });
		}

		function loadExportLibs() {
			const base = 'modules/noc_executive_dashboard/assets/js/vendor/';
			loadWithFallback(base + 'html2canvas.min.js',
				'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js')
				.catch(function() { /* export button will warn if unavailable */ });
			loadWithFallback(base + 'jspdf.umd.min.js',
				'https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js')
				.catch(function() { /* export button will warn if unavailable */ });
		}

		function boot(attempt) {
			attempt = attempt || 0;
			if (window.NocExecutiveDashboard && typeof window.NocExecutiveDashboard.init === 'function') {
				window.NocExecutiveDashboard.init(config);
				loadExportLibs();
				return;
			}
			// The external asset may not have parsed yet; retry briefly.
			if (attempt < 50) {
				window.setTimeout(function() { boot(attempt + 1); }, 100);
			}
			else {
				console.error('NOC dashboard: script noc-dashboard.js nao carregou.');
			}
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function() { boot(0); });
		}
		else {
			boot(0);
		}
	})();
</script>
