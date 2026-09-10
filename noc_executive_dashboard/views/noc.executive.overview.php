<?php declare(strict_types = 1);

/**
 * NOC Executive Overview view (shell).
 *
 * Renders the filter bar and empty card scaffolding. The data is fetched
 * asynchronously from the noc.executive.data action and rendered by JS.
 *
 * @var CView $this
 * @var array $data
 */

$this->addCssFile('modules/noc_executive_dashboard/assets/css/noc-dashboard.css');
$this->includeJsFile('noc.executive.overview.js.php', $data);

$html_page = (new CHtmlPage())
	->setTitle(_('NOC - Dashboard Executivo'))
	->addItem(
		(new CDiv())
			->addClass('noc-exec')
			->setAttribute('data-period', $data['period'])
			->setAttribute('data-tenant', $data['tenant'])
			->addItem(new CPartial('noc.executive.body', $data))
	);

$html_page->show();
