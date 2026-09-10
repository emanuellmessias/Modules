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

		function boot(attempt) {
			attempt = attempt || 0;
			if (window.NocExecutiveDashboard && typeof window.NocExecutiveDashboard.init === 'function') {
				window.NocExecutiveDashboard.init(config);
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
