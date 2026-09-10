<?php declare(strict_types = 1);

/**
 * NOC Executive Overview body partial.
 *
 * Static scaffolding. Numeric values are placeholders ("—") until the
 * JS loader fills them from the noc.executive.data endpoint.
 *
 * @var CPartial $this
 * @var array    $data
 */

// --- Header / filter bar --------------------------------------------------

$period_buttons = [];
foreach ($data['periods'] as $key => $label) {
	$period_buttons[] = (new CSimpleButton($label))
		->addClass('noc-period-btn')
		->addClass($key === $data['period'] ? 'is-active' : null)
		->setAttribute('data-period', $key);
}

$header = (new CDiv())
	->addClass('noc-header')
	->addItem(
		(new CDiv())
			->addClass('noc-header-left')
			->addItem((new CTag('h1', true, _('EXECUTIVE OVERVIEW')))->addClass('noc-title'))
			->addItem((new CTag('p', true, _('Postura cross-tenant, performance de deteccao & resposta e saude de SLA.')))->addClass('noc-subtitle'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-header-right')
			->addItem(
				(new CDiv())
					->addClass('noc-tenant-filter')
					->addItem((new CSelect('tenant'))
						->addClass('noc-tenant-select')
						->addOption(new CSelectOption('', _('All Tenants')))
						->setValue($data['tenant'])
					)
			)
			->addItem((new CDiv($period_buttons))->addClass('noc-period-group'))
			->addItem(
				(new CDiv())
					->addClass('noc-data-age')
					->addItem((new CSpan())->addClass('noc-dot'))
					->addItem((new CSpan(_('Data —')))->addClass('noc-data-age-label')->setId('noc-data-age'))
			)
			->addItem((new CSimpleButton(_('Refresh')))->addClass('noc-refresh-btn')->setId('noc-refresh'))
	);

// --- KPI cards -------------------------------------------------------------

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

$cards = (new CDiv())
	->addClass('noc-cards')
	->addItem(noc_card('kpi-open', _('OPEN ALERTS'), _('current backlog'), 'red'))
	->addItem(noc_card('kpi-new', _('NEW (RANGE)'), _('vs prior period'), 'blue'))
	->addItem(noc_card('kpi-closed', _('CLOSED ALERTS'), _('resolved in range'), 'green'))
	->addItem(noc_card('kpi-unassigned', _('UNASSIGNED'), _('awaiting triage'), 'yellow'))
	->addItem(noc_card('kpi-automation', _('AUTOMATION'), _('acoes automaticas'), 'purple'))
	->addItem(noc_card('kpi-human', _('HUMAN ACTIONS'), _('grupo Monitor'), 'cyan'));

// --- Detection & response times -------------------------------------------

if (!function_exists('noc_time_box')) {
	function noc_time_box(string $id, string $label): CDiv {
		return (new CDiv())
			->addClass('noc-time-box')
			->addItem((new CDiv($label))->addClass('noc-time-label'))
			->addItem((new CDiv('—'))->addClass('noc-time-value')->setId($id.'-value'))
			->addItem((new CDiv('p50 — · p90 —'))->addClass('noc-time-pct')->setId($id.'-pct'));
	}
}

$times = (new CDiv())
	->addClass('noc-panel')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('DETECTION & RESPONSE TIMES')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('Media com p50 / p90 — media correta cross-tenant')))->addClass('noc-panel-sub'))
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
			->addItem((new CDiv(_('Novos alertas no periodo')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-donut-wrap')
			->addItem((new CTag('canvas', true))->setId('noc-severity-donut')->setAttribute('width', '220')->setAttribute('height', '220'))
			->addItem(
				(new CDiv())
					->addClass('noc-donut-legend')
					->setId('noc-severity-legend')
			)
	);

// --- Action breakdown (automation vs human) --------------------------------

$actions = (new CDiv())
	->addClass('noc-panel')
	->addItem(
		(new CDiv())
			->addClass('noc-panel-head')
			->addItem((new CTag('h2', true, _('ACOES: AUTOMACAO x HUMANO')))->addClass('noc-panel-title'))
			->addItem((new CDiv(_('WhatsApp / Email / Cervello vs grupo Monitor')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CDiv())
			->addClass('noc-action-grid')
			->addItem((new CDiv())->addClass('noc-action-row')
				->addItem((new CSpan(_('WhatsApp')))->addClass('noc-action-name'))
				->addItem((new CSpan('—'))->addClass('noc-action-val')->setId('act-whatsapp')))
			->addItem((new CDiv())->addClass('noc-action-row')
				->addItem((new CSpan(_('Email HTML NOC')))->addClass('noc-action-name'))
				->addItem((new CSpan('—'))->addClass('noc-action-val')->setId('act-email')))
			->addItem((new CDiv())->addClass('noc-action-row')
				->addItem((new CSpan(_('Chamado Cervello')))->addClass('noc-action-name'))
				->addItem((new CSpan('—'))->addClass('noc-action-val')->setId('act-cervello')))
			->addItem((new CDiv())->addClass('noc-action-row noc-action-total')
				->addItem((new CSpan(_('Total automacao')))->addClass('noc-action-name'))
				->addItem((new CSpan('—'))->addClass('noc-action-val')->setId('act-automation-total')))
			->addItem((new CDiv())->addClass('noc-action-row noc-action-human')
				->addItem((new CSpan(_('Acoes humanas (Monitor)')))->addClass('noc-action-name'))
				->addItem((new CSpan('—'))->addClass('noc-action-val')->setId('act-human-total')))
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
			->addClass('noc-backlog')
			->addItem((new CDiv())->addClass('noc-backlog-row')
				->addItem((new CSpan(_('New')))->addClass('noc-backlog-name'))
				->addItem((new CSpan('—'))->addClass('noc-backlog-val')->setId('backlog-new')))
			->addItem((new CDiv())->addClass('noc-backlog-row')
				->addItem((new CSpan(_('In Progress')))->addClass('noc-backlog-name'))
				->addItem((new CSpan('—'))->addClass('noc-backlog-val')->setId('backlog-inprogress')))
			->addItem((new CDiv())->addClass('noc-backlog-row')
				->addItem((new CSpan(_('Reopened')))->addClass('noc-backlog-name'))
				->addItem((new CSpan('—'))->addClass('noc-backlog-val')->setId('backlog-reopened')))
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
			->addItem((new CDiv(_('Eventos, resolucao, automacao e acoes humanas por cliente')))->addClass('noc-panel-sub'))
	)
	->addItem(
		(new CTable())
			->addClass('noc-tenant-table')
			->setHeader([_('TENANT'), _('EVENTOS'), _('RESOLVIDOS'), _('AUTOMACAO'), _('HUMANO')])
			->setId('noc-tenant-tbody')
	);

// --- Assemble --------------------------------------------------------------

return (new CDiv())
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
	->addItem((new CDiv())->addClass('noc-loading')->setId('noc-loading')->addItem(new CSpan(_('Carregando dados da API...'))));
