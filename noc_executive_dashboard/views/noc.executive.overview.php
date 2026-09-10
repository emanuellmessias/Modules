<?php declare(strict_types = 1);

/**
 * NOC Executive Overview view.
 *
 * Renders the filter bar and empty card scaffolding. Numeric values are
 * placeholders ("—") until the JS loader fills them from the
 * noc.executive.data endpoint.
 *
 * @var CView $this
 * @var array $data
 */

$this->addCssFile('modules/noc_executive_dashboard/assets/css/noc-dashboard.css');
$this->includeJsFile('noc.executive.overview.js.php', $data);

// --- Helpers --------------------------------------------------------------

if (!function_exists('noc_card')) {
	function noc_card(string $id, string $label, string $sublabel, string $accent): CDiv {
		return (new CDiv())
			->addClass('noc-card')
			->addClass('noc-accent-'.$accent)
			->addItem((new CDiv($label))->addClass('noc-card-label'))
			->addItem((new CDiv('—'))->addClass('noc-card-value')->setId($id))
			->addItem((new CDiv($sublabel))->addClass('noc-card-sub'));
	}
}

if (!function_exists('noc_time_box')) {
	function noc_time_box(string $id, string $label): CDiv {
		return (new CDiv())
			->addClass('noc-time-box')
			->addItem((new CDiv($label))->addClass('noc-time-label'))
			->addItem((new CDiv('—'))->addClass('noc-time-value')->setId($id.'-value'))
			->addItem((new CDiv('p50 — · p90 —'))->addClass('noc-time-pct')->setId($id.'-pct'));
	}
}

if (!function_exists('noc_kv_row')) {
	function noc_kv_row(string $name, string $value_id, string $row_class = ''): CDiv {
		$row = (new CDiv())->addClass('noc-kv-row');
		if ($row_class !== '') {
			$row->addClass($row_class);
		}
		return $row
			->addItem((new CSpan($name))->addClass('noc-kv-name'))
			->addItem((new CSpan('—'))->addClass('noc-kv-val')->setId($value_id));
	}
}

// --- Header / filter bar --------------------------------------------------

$period_buttons = [];
foreach ($data['periods'] as $key => $label) {
	$btn = (new CSimpleButton($label))
		->addClass('noc-period-btn')
		->setAttribute('data-period', $key);

	if ($key === $data['period']) {
		$btn->addClass('is-active');
	}

	$period_buttons[] = $btn;
}

$header = (new CDiv())
	->addClass('noc-header')
	->addItem(
		(new CDiv())
			->addClass('noc-header-left')
			->addItem((new CTag('h1', true, _('EXECUTIVE OVERVIEW')))->addClass('noc-title'))
			->addItem((new CTag('p', true, _('Postura cross-tenant, performance de detecção & resposta e saúde de SLA.')))->addClass('noc-subtitle'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-header-right')
			->addItem((new CTag('select', true))
				->setId('noc-tenant-select')
				->addClass('noc-tenant-select')
				->addItem((new CTag('option', true, _('All Tenants')))->setAttribute('value', ''))
			)
			->addItem((new CDiv($period_buttons))->addClass('noc-period-group'))
			->addItem(
				(new CDiv())
					->addClass('noc-data-age')
					->addItem((new CSpan())->addClass('noc-dot'))
					->addItem((new CSpan(_('Data —')))->setId('noc-data-age'))
			)
			->addItem((new CSimpleButton(_('Refresh')))->addClass('noc-refresh-btn')->setId('noc-refresh'))
			->addItem((new CSimpleButton(_('Exportar PDF')))->addClass('noc-export-btn')->setId('noc-export-pdf'))
	);

// --- KPI cards -------------------------------------------------------------

$cards = (new CDiv())
	->addClass('noc-cards')
	->addItem(noc_card('kpi-open', _('OPEN ALERTS'), _('current backlog'), 'red'))
	->addItem(noc_card('kpi-new', _('NEW (RANGE)'), _('vs prior period'), 'blue'))
	->addItem(noc_card('kpi-closed', _('CLOSED ALERTS'), _('resolved in range'), 'green'))
	->addItem(noc_card('kpi-unassigned', _('UNASSIGNED'), _('awaiting triage'), 'yellow'))
	->addItem(noc_card('kpi-automation', _('AUTOMATION'), _('ações automáticas'), 'purple'))
	->addItem(noc_card('kpi-human', _('HUMAN ACTIONS'), _('grupo Monitor'), 'cyan'));

// --- Detection & response times -------------------------------------------

$times = (new CDiv())
	->addClass('noc-panel')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('DETECTION & RESPONSE TIMES')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('Média com p50 / p90 — média correta cross-tenant')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-time-row')
			->addItem(noc_time_box('mttd', _('MTTD')))
			->addItem(noc_time_box('mtta', _('MTTA')))
			->addItem(noc_time_box('mttr_respond', _('MTTR-RESPOND')))
			->addItem(noc_time_box('mttr_resolve', _('MTTR-RESOLVE')))
	);

// --- Severity mix (donut) --------------------------------------------------

$severity = (new CDiv())
	->addClass('noc-panel')
	->addClass('noc-panel-severity')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('SEVERITY MIX')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('Novos alertas no período')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-donut-wrap')
			->addItem((new CTag('canvas', true))->setId('noc-severity-donut')->setAttribute('width', '220')->setAttribute('height', '220'))
			->addItem((new CDiv())->addClass('noc-donut-legend')->setId('noc-severity-legend'))
	);

// --- Action breakdown (automation vs human) --------------------------------

$actions = (new CDiv())
	->addClass('noc-panel')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('AÇÕES: AUTOMAÇÃO x HUMANO')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('WhatsApp / Email / Cervello vs grupo Monitor')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-kv')
			->addItem(noc_kv_row(_('WhatsApp'), 'act-whatsapp'))
			->addItem(noc_kv_row(_('Email HTML NOC'), 'act-email'))
			->addItem(noc_kv_row(_('Chamado Cervello'), 'act-cervello'))
			->addItem(noc_kv_row(_('Total automação'), 'act-automation-total', 'noc-kv-total'))
			->addItem(noc_kv_row(_('Ações humanas (Monitor)'), 'act-human-total', 'noc-kv-human'))
	);

// --- Backlog by status -----------------------------------------------------

$backlog = (new CDiv())
	->addClass('noc-panel')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('OPEN BACKLOG BY STATUS')))->addClass('noc-panel-title'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-kv')
			->addItem(noc_kv_row(_('New'), 'backlog-new'))
			->addItem(noc_kv_row(_('In Progress'), 'backlog-inprogress'))
			->addItem(noc_kv_row(_('Reopened'), 'backlog-reopened'))
	);

// --- Risk gauge ------------------------------------------------------------

$risk = (new CDiv())
	->addClass('noc-panel')
	->addClass('noc-panel-risk')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('NOC RISK LEVEL')))->addClass('noc-panel-title'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-gauge-wrap')
			->addItem((new CTag('canvas', true))->setId('noc-risk-gauge')->setAttribute('width', '260')->setAttribute('height', '260'))
			->addItem(
				(new CDiv())
					->addClass('noc-gauge-center')
					->addItem((new CDiv('—'))->addClass('noc-gauge-value')->setId('noc-risk-value'))
					->addItem((new CDiv(_('COMPOSITE RISK')))->addClass('noc-gauge-label'))
			)
	);

// --- Per-tenant table ------------------------------------------------------

$tenants = (new CDiv())
	->addClass('noc-panel')
	->addClass('noc-panel-tenants')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('POR TENANT')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('Eventos, resolução, automação e ações humanas por cliente')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CTable())
			->addClass('noc-tenant-table')
			->setHeader([_('TENANT'), _('EVENTOS'), _('RESOLVIDOS'), _('AUTOMAÇÃO'), _('HUMANO')])
			->setId('noc-tenant-tbody')
	);

// --- Analyst performance (Monitor group) ----------------------------------

$analysts = (new CDiv())
	->addClass('noc-panel')
	->addClass('noc-panel-analysts')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('ANALYST PERFORMANCE')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('Ações humanas tratadas por analista do grupo Monitor')))->addClass('noc-panel-sub')
				->addItem((new CSpan(''))->setId('noc-analyst-total')->addClass('noc-analyst-total')))
	)
	->addItem(
		(new CTable())
			->addClass('noc-analyst-table')
			->setHeader([_('ANALISTA'), _('EVENTOS'), _('AÇÕES'), _('% DO TOTAL')])
			->setId('noc-analyst-tbody')
	);

// --- Assemble page --------------------------------------------------------

$body = (new CDiv())
	->addClass('noc-exec')
	->setAttribute('data-period', $data['period'])
	->setAttribute('data-tenant', $data['tenant'])
	->addItem(
		(new CDiv())
			->addClass('noc-exec-inner')
			->addItem($header)
			->addItem($cards)
			->addItem((new CDiv())->addClass('noc-grid-2')
				->addItem($times)
				->addItem($severity))
			->addItem((new CDiv())->addClass('noc-grid-3')
				->addItem($backlog)
				->addItem($risk)
				->addItem($actions))
			->addItem($tenants)
			->addItem($analysts)
			->addItem((new CDiv())->addClass('noc-loading')->setId('noc-loading')
				->addItem(new CSpan(_('Carregando dados da API...'))))
	);

(new CHtmlPage())
	->setTitle(_('NOC - Dashboard Executivo'))
	->addItem($body)
	->show();
