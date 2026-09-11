<?php

/**
 * Executive Report
 *
 * @var array $data
 */

$clients = $data['clients'] ?? [];
$selected_groupid = $data['selected_groupid'] ?? 0;
$selected_period = (int)($data['selected_period'] ?? 30);
$is_admin = (bool)($data['is_admin'] ?? false);
$report = $data['report'] ?? [];

$summary = $report['summary'] ?? [];
$health = $report['health'] ?? [];
$categories = $report['categories'] ?? [];
$executive_score = $report['executive_score'] ?? [];

/*
|--------------------------------------------------------------------------
| Strings acentuadas (via escape unicode - ver explicação na versão anterior)
|--------------------------------------------------------------------------
*/

$str_saude_ambiente   = "Sa\u{00fa}de Cr\u{00ed}tica";
$str_media            = "M\u{00e9}dia";
$str_atencao          = "Aten\u{00e7}\u{00e3}o";
$str_informacao       = "Informa\u{00e7}\u{00e3}o";
$str_nao_classificada = "N\u{00e3}o classificada";
$str_sla_global       = "SLA ICMP";
$str_periodo          = "Per\u{00ed}odo";
$str_score_executivo  = "Executive Score";
$str_hosts_indisp     = "HOSTS INDISPONIVEIS NO PERIODO";

/*
|--------------------------------------------------------------------------
| CSS - visual em cards + regras de impressão (base para o "PDF" via navegador)
|--------------------------------------------------------------------------
*/

$css = <<<CSS
.er-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    margin: 10px 0 20px 0;
}
.er-kpi-card {
    background: #2b2b2b;
    border: 1px solid #3a3a3a;
    border-radius: 6px;
    padding: 14px 16px;
    min-height: 76px;
}
.er-kpi-card.er-highlight {
    border: 1px solid #59DB59;
    box-shadow: inset 0 3px 0 #59DB59;
}
.er-kpi-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #9c9c9c;
    margin-bottom: 6px;
}
.er-kpi-value {
    font-size: 26px;
    font-weight: bold;
    color: #ffffff;
}
.er-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin: 10px 0 20px 0;
}
.er-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #2b2b2b;
    border: 1px solid #3a3a3a;
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 13px;
    color: #dcdcdc;
}
.er-chip-badge {
    border-radius: 3px;
    padding: 2px 8px;
    font-weight: bold;
    min-width: 22px;
    text-align: center;
}
.er-score-panel {
    background: #2b2b2b;
    border: 1px solid #3a3a3a;
    border-radius: 6px;
    border-left: 5px solid currentColor;
    padding: 20px 22px;
    margin: 10px 0 20px 0;
}
.er-score-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 12px;
}
.er-score-value {
    font-size: 46px;
    line-height: 1;
    font-weight: bold;
}
.er-score-status {
    font-size: 16px;
    font-weight: bold;
}
.er-score-track {
    height: 12px;
    background: #1f1f1f;
    border-radius: 999px;
    overflow: hidden;
}
.er-score-bar {
    height: 12px;
    border-radius: 999px;
}
.er-muted-note {
    color: #9c9c9c;
    font-size: 12px;
    margin-top: -8px;
    margin-bottom: 12px;
}
.er-report-brand {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    min-height: 42px;
    margin: 0 0 6px 0;
}
.er-report-logo {
    display: block;
    max-width: 210px;
    max-height: 48px;
    object-fit: contain;
}
.er-form-columns {
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: center;
    max-width: 760px;
    margin: 0 auto 20px;
}
.er-form-column {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    max-width: 620px;
    padding: 14px 16px;
    background: #1f1f1f;
    border: 1px solid #2f2f2f;
    border-radius: 12px;
    box-shadow: 0 0 0 1px rgba(255,255,255,.02), 0 12px 24px rgba(0,0,0,.08);
}
.er-form-column label,
.er-form-column .er-label {
    margin-bottom: 4px;
    font-weight: 600;
    color: #dcdcdc;
}
.er-form-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 14px 20px;
    border-radius: 10px;
    border: none;
    background: #4bb543;
    color: #111;
    font-weight: bold;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: .03em;
    box-shadow: inset 0 -3px 0 rgba(0,0,0,.08);
}
.er-form-button:hover {
    background: #3ea93e;
}
.er-static-value {
    padding: 14px 16px;
    background: #111;
    border: 1px solid #333;
    border-radius: 8px;
    color: #f5f5f5;
    min-height: 44px;
    display: flex;
    align-items: center;
}
h2 {
    margin-top: 32px;
    margin-bottom: 18px;
}
@media print {
    .er-no-print {
        display: none !important;
    }
    body, .er-kpi-card, .er-chip, .er-score-panel {
        background: #ffffff !important;
        color: #000000 !important;
    }
    .er-kpi-value, .er-kpi-label, .er-score-value, .er-score-status {
        color: #000000 !important;
    }
    .er-report-logo {
        max-width: 180px;
    }
}
CSS;

/*
|--------------------------------------------------------------------------
| Página
|--------------------------------------------------------------------------
*/

$html_page = new CHtmlPage();
$html_page->setTitle(_('Executive Report'));
$styleTag = new CTag('style', true, $css);
$html_page->addItem($styleTag);

$logo = new CTag('img', false);
$logo->setAttribute('src', 'modules/ExecutiveReport/main_logo.svg');
$logo->setAttribute('alt', 'Logo');
$logo->addClass('er-report-logo');

$brandDiv = new CDiv($logo);
$brandDiv->addClass('er-report-brand');

$html_page->addItem($brandDiv);

/*
|--------------------------------------------------------------------------
| Filtro (Cliente + Período)
|--------------------------------------------------------------------------
*/

$form = new CForm('post');
$form->addVar('action', 'executivereport.view');
$form->addClass('er-no-print');

$client_select = new CSelect('groupid');
$client_select->setFocusableElementId('groupid');
$client_select->addOption(new CSelectOption(0, _('Selecione...')));

$selected_client_name = '';
foreach ($clients as $client) {
    $client_select->addOption(new CSelectOption($client['groupid'], $client['name']));
    if ((int)$client['groupid'] === $selected_groupid) {
        $selected_client_name = $client['name'];
    }
}

$client_select->setValue($selected_groupid);

$period_select = new CSelect('period');
$period_select->setFocusableElementId('period');
$period_select->addOption(new CSelectOption(7, _('7 dias')));
$period_select->addOption(new CSelectOption(15, _('15 dias')));
$period_select->addOption(new CSelectOption(30, _('30 dias')));
$period_select->addOption(new CSelectOption(90, _('90 dias')));
$period_select->setValue($selected_period);

$left_column = new CDiv();
$left_column->addClass('er-form-column');
$left_column->addItem(new CLabel(_('Cliente'), 'groupid'));

if ($is_admin || count($clients) > 1) {
    $left_column->addItem($client_select);
} else {
    $select_label = new CTag('div', true, htmlspecialchars($selected_client_name, ENT_QUOTES, 'UTF-8'));
    $select_label->addClass('er-static-value');
    $left_column->addItem($select_label);

    $hidden_group = new CTag('input', false);
    $hidden_group->setAttribute('type', 'hidden');
    $hidden_group->setAttribute('name', 'groupid');
    $hidden_group->setAttribute('value', $selected_groupid);
    $form->addItem($hidden_group);
}

$left_column->addItem(new CLabel(_($str_periodo), 'period'));
$left_column->addItem($period_select);
$left_column->addItem(new CSubmit('search', _('Carregar')));

$form_columns = new CDiv();
$form_columns->addClass('er-form-columns');
$form_columns->addItem($left_column);

$form->addItem($form_columns);

$html_page->addItem($form);

if ($selected_groupid == 0) {
    $html_page->show();
    return;
}

/*
|--------------------------------------------------------------------------
| SLA Global
|--------------------------------------------------------------------------
|
| Prioridade: usa $report['summary']['sla_global'] se o controller já
| calcular isso (ideal, pois considera o período real consultado na API).
| Se não vier pronto, cai para uma média simples das SLAs por categoria
| só como fallback visual - o cálculo correto precisa vir do backend.
*/

if (isset($summary['sla_global']) && is_numeric($summary['sla_global'])) {
    $sla_global = (float)$summary['sla_global'];
} else {
    $sla_values = array_filter(array_map(function ($cat) {
        return isset($cat['sla']) && is_numeric($cat['sla']) ? (float)$cat['sla'] : null;
    }, $categories));

    $sla_global = count($sla_values) > 0 ? array_sum($sla_values) / count($sla_values) : 100;
}

$sla_color = $sla_global >= 99 ? '#59DB59' : ($sla_global >= 95 ? '#FFC859' : '#E45959');

/*
|--------------------------------------------------------------------------
| Executive Score
|--------------------------------------------------------------------------
*/

$score_value = isset($executive_score['score']) && is_numeric($executive_score['score'])
    ? (float)$executive_score['score']
    : 100.0;

$score_status = $executive_score['status'] ?? 'Excelente';
$score_color = $executive_score['color'] ?? '#59DB59';

$score_panel = new CDiv();
$score_panel->addClass('er-score-panel');
$score_panel->setAttribute('style', "color: {$score_color};");
$score_head = new CDiv();
$score_head->addClass('er-score-head');
$score_value_div = new CDiv(number_format($score_value, 2));
$score_value_div->addClass('er-score-value');
$score_value_div->setAttribute('style', "color: {$score_color};");
$score_head->addItem($score_value_div);
$score_status_div = new CDiv($score_status);
$score_status_div->addClass('er-score-status');
$score_status_div->setAttribute('style', "color: {$score_color};");
$score_head->addItem($score_status_div);

$score_track = new CDiv();
$score_track->addClass('er-score-track');
$score_bar = new CDiv();
$score_bar->addClass('er-score-bar');
$score_bar->setAttribute('style', 'width: ' . max(0, min(100, $score_value)) . "%; background-color: {$score_color};");
$score_track->addItem($score_bar);

$score_panel->addItem($score_head);
$score_panel->addItem($score_track);

$html_page->addItem(new CTag('h2', true, _($str_score_executivo)));
$html_page->addItem($score_panel);
$score_note = new CDiv(_('Score baseado em disponibilidade ICMP, impactos ativos e eventos em desastre.'));
$score_note->addClass('er-muted-note');
$html_page->addItem($score_note);

/*
|--------------------------------------------------------------------------
| Resumo Geral + SLA Global (KPI cards)
|--------------------------------------------------------------------------
*/

function er_kpi_card($label, $value, $value_color = '#ffffff', $highlight = false)
{
    $card = new CDiv();
    $card->addClass('er-kpi-card' . ($highlight ? ' er-highlight' : ''));
    $labelDiv = new CDiv($label);
    $labelDiv->addClass('er-kpi-label');
    $card->addItem($labelDiv);
    $valueDiv = new CDiv($value);
    $valueDiv->addClass('er-kpi-value');
    $valueDiv->setAttribute('style', "color: {$value_color};");
    $card->addItem($valueDiv);

    return $card;
}

$total_problems = $summary['problems'] ?? 0;
$hosts_down = $summary['hosts_down'] ?? 0;
$problems_color = $total_problems > 0 ? '#E45959' : '#59DB59';
$hosts_down_color = $hosts_down > 0 ? '#FFC859' : '#59DB59';

$kpi_grid = new CDiv();
$kpi_grid->addClass('er-kpi-grid');
$kpi_grid->addItem(er_kpi_card(_($str_sla_global) . " ({$selected_period}d)", number_format($sla_global, 2) . '%', $sla_color, true));
$kpi_grid->addItem(er_kpi_card(_('Hosts'), number_format($summary['hosts'] ?? 0)));
$kpi_grid->addItem(er_kpi_card(_($str_hosts_indisp), number_format($hosts_down), $hosts_down_color));
$kpi_grid->addItem(er_kpi_card(_('Itens'), number_format($summary['items'] ?? 0)));
$kpi_grid->addItem(er_kpi_card(_('Triggers'), number_format($summary['triggers'] ?? 0)));
$kpi_grid->addItem(er_kpi_card(_('Impactos ativos'), number_format($total_problems), $problems_color));

$html_page->addItem(new CTag('h2', true, _('Resumo Geral')));
$html_page->addItem($kpi_grid);

/*
|--------------------------------------------------------------------------
| Saúde do Ambiente - só exibe severidades com valor > 0
|--------------------------------------------------------------------------
*/

$v_disaster   = (int)($health['disaster']     ?? $health[5] ?? 0);
$v_high       = (int)($health['high']         ?? $health[4] ?? 0);
$v_average    = (int)($health['average']      ?? $health[3] ?? 0);
$v_warning    = (int)($health['warning']      ?? $health[2] ?? 0);
$v_info       = (int)($health['information']  ?? $health[1] ?? 0);
$v_unclass    = (int)($health['not_classified'] ?? $health[0] ?? 0);

$severities = [
    ['label' => _('Desastre'), 'value' => $v_disaster, 'bg' => '#E45959', 'color' => '#FFFFFF'],
];

$chip_row = new CDiv();
$chip_row->addClass('er-chip-row');

foreach ($severities as $sev) {
    if ($sev['value'] <= 0) {
        continue;
    }

    $badge = (new CSpan(number_format($sev['value'])))
        ->addClass('er-chip-badge')
        ->setAttribute('style', "background-color: {$sev['bg']}; color: {$sev['color']};");

    $chip = new CDiv();
    $chip->addClass('er-chip');
    $chip->addItem(new CSpan($sev['label']));
    $chip->addItem($badge);

    $chip_row->addItem($chip);
}

$html_page->addItem(new CTag('h2', true, _($str_saude_ambiente)));

if ($chip_row->items) {
    $html_page->addItem($chip_row);
} else {
    $html_page->addItem(new CDiv(_('Nenhum evento em desastre ativo.')));
}

/*
|--------------------------------------------------------------------------
| Top Ofensores - hosts com mais indisponibilidade no período
|--------------------------------------------------------------------------
*/

$offenders = $report['offenders'] ?? [];

$html_page->addItem(new CTag('h2', true, _('Indisponibilidade ICMP')));

$colHeader1 = new CColHeader(_('Falhas ICMP'));
$colHeader1->setAttribute('style', 'text-align: center; width: 100px;');
$colHeader2 = new CColHeader(_('Tempo sem resposta'));
$colHeader2->setAttribute('style', 'text-align: center; width: 150px;');
$colHeader3 = new CColHeader(_('Disponibilidade'));
$colHeader3->setAttribute('style', 'text-align: center; width: 130px;');

if ($offenders) {
    $offenders_table = new CTableInfo();
    $offenders_table->setHeader([
        _('Host'),
        _('Categoria'),
        $colHeader1,
        $colHeader2,
        $colHeader3
    ]);

    foreach ($offenders as $offender) {
        $avail = $offender['availability'];
        $avail_color = $avail >= 99 ? '#59DB59' : ($avail >= 95 ? '#FFC859' : '#E45959');

        $problemCol = new CCol(number_format($offender['problems']));
        $problemCol->setAttribute('style', 'text-align: center;');
        $downtimeCol = new CCol($offender['downtime_hours'] . 'h');
        $downtimeCol->setAttribute('style', 'text-align: center;');
        $availabilityCol = new CCol(number_format($avail, 2) . '%');
        $availabilityCol->setAttribute('style', "text-align: center; font-weight: bold; color: {$avail_color};");

        $offenders_table->addRow([
            new CTag('strong', true, $offender['host']),
            $offender['category'],
            $problemCol,
            $downtimeCol,
            $availabilityCol
        ]);
    }

    $html_page->addItem($offenders_table);
} else {
    $html_page->addItem(new CDiv(_('Nenhum host sem resposta ICMP no período.')));
}

/*
|--------------------------------------------------------------------------
| Categorias
|--------------------------------------------------------------------------
*/

$table = new CTableInfo();
    $tableHeader1 = new CColHeader(_('Hosts'));
    $tableHeader1->setAttribute('style', 'text-align: center; width: 100px;');
    $tableHeader2 = new CColHeader(_('Itens'));
    $tableHeader2->setAttribute('style', 'text-align: center; width: 100px;');
    $tableHeader3 = new CColHeader(_('Triggers'));
    $tableHeader3->setAttribute('style', 'text-align: center; width: 100px;');
    $tableHeader4 = new CColHeader(_('SLA ICMP') . " ({$selected_period}d)");
    $tableHeader4->setAttribute('style', 'text-align: center; width: 140px;');
    $tableHeader5 = new CColHeader(_('Impactos'));
    $tableHeader5->setAttribute('style', 'text-align: center; width: 120px;');

$table->setHeader([
    _('Categoria'),
    $tableHeader1,
    $tableHeader2,
    $tableHeader3,
    $tableHeader4,
    $tableHeader5
]);

foreach ($categories as $category) {
    $cat_problems = $category['problems'] ?? 0;
    $cat_bg = $cat_problems > 0 ? '#E45959' : '#59DB59';
    $cat_color = $cat_problems > 0 ? '#FFFFFF' : '#000000';

    $cat_prob_badge = (new CSpan(number_format($cat_problems)))
        ->setAttribute('style', "background-color: {$cat_bg}; color: {$cat_color}; padding: 3px 10px; border-radius: 3px; font-weight: bold; display: inline-block; min-width: 30px; text-align: center;");

    $sla_value = isset($category['sla']) ? $category['sla'] : '100.00%';
    if (is_numeric($sla_value)) {
        $sla_value = number_format($sla_value, 2) . '%';
    }

    $categoryCell = new CTag('strong', true, $category['category']);
    $hostsCell = new CCol(number_format($category['hosts']));
    $hostsCell->setAttribute('style', 'text-align: center;');
    $itemsCell = new CCol(number_format($category['items']));
    $itemsCell->setAttribute('style', 'text-align: center;');
    $triggersCell = new CCol(number_format($category['triggers']));
    $triggersCell->setAttribute('style', 'text-align: center;');
    $slaCell = new CCol($sla_value);
    $slaCell->setAttribute('style', 'text-align: center; font-weight: bold; color: #59DB59;');
    $badgeCell = new CCol($cat_prob_badge);
    $badgeCell->setAttribute('style', 'text-align: center;');

    $table->addRow([
        $categoryCell,
        $hostsCell,
        $itemsCell,
        $triggersCell,
        $slaCell,
        $badgeCell
    ]);
}

$html_page->addItem(new CTag('h2', true, _('Categorias')));
$html_page->addItem($table);

$html_page->show();
