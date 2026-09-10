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
			dataUrl: new Curl('zabbix.php').getUrl(),
			action: 'noc.executive.data',
			period: <?= json_encode($data['period']) ?>,
			tenant: <?= json_encode($data['tenant']) ?>,
			refreshMs: 60000
		};

		document.addEventListener('DOMContentLoaded', function() {
			if (window.NocExecutiveDashboard) {
				window.NocExecutiveDashboard.init(config);
			}
		});
	})();
</script>
