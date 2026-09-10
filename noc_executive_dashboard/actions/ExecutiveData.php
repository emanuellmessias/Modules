<?php declare(strict_types = 1);

namespace Modules\NocExecutiveDashboard\Actions;

use CController;
use CControllerResponseData;
use CRoleHelper;
use API;

/**
 * AJAX data endpoint for the NOC Executive Overview.
 *
 * Returns a JSON payload with:
 *   - headline KPI cards (open / new / closed / unassigned backlog)
 *   - response times (MTTD, MTTA, MTTR-respond, MTTR-resolve)
 *   - severity mix (donut)
 *   - automation vs human action breakdown (WhatsApp / Email / Cervello / human)
 *   - per-tenant aggregation (tenant = host group prefix before the separator)
 *
 * Data source: Zabbix API (event.get / problem.get / alert.get / usergroup.get).
 */
class ExecutiveData extends CController {

	/* ---------------------------------------------------------------------
	 * Configuration. Adjust here if media type / user group names change.
	 * Each client (tenant) has its own host group and its own "alert sender"
	 * user, so we DO NOT classify automation by user name. We classify by:
	 *   - automation  => alert sent through one of the automation media types
	 *   - human        => acknowledge/message action by a user in HUMAN_USER_GROUP
	 * ------------------------------------------------------------------- */
	private const AUTOMATION_MEDIATYPES = ['WhatsApp', 'Email HTML NOC Betta', 'Chamado Cervello'];
	private const MEDIATYPE_WHATSAPP    = 'WhatsApp';
	private const MEDIATYPE_EMAIL       = 'Email HTML NOC Betta';
	private const MEDIATYPE_CERVELLO    = 'Chamado Cervello';
	private const HUMAN_USER_GROUP      = 'Monitor';
	private const TENANT_SEPARATOR      = '|';

	/** @var array<int,string> map userid => display name (filled by fetchHumanUserIds) */
	private array $human_user_names = [];

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'period' => 'in today,6h,24h,7d,14d,30d',
			'tenant' => 'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			// Return a JSON error instead of letting the base controller emit
			// a 403 HTML page (which breaks the AJAX JSON parsing on the client).
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'error' => ['messages' => [_('Parametros invalidos.')]]
				])
			]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// Restricted to Super Admin only (matches the page/menu restriction).
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$period = $this->hasInput('period') ? $this->getInput('period') : '24h';
			$tenant = $this->hasInput('tenant') ? trim($this->getInput('tenant')) : '';

			[$time_from, $time_till] = $this->resolvePeriod($period);

			// Resolve host groups for the selected tenant (or all).
			$groupids = $this->resolveTenantGroupIds($tenant);

			// Pull the datasets we need.
			$events = $this->fetchEvents($time_from, $time_till, $groupids);
			$alerts = $this->fetchAlerts($time_from, $time_till, $groupids);
			$human_userids = $this->fetchHumanUserIds();

			// Build per-tenant + global aggregates.
			$data = [
				'period'      => $period,
				'tenant'      => $tenant,
				'range'       => ['from' => $time_from, 'till' => $time_till],
				'generated'   => time(),
				'cards'       => $this->buildCards($events),
				'times'       => $this->buildResponseTimes($events),
				'severity'    => $this->buildSeverityMix($events),
				'backlog'     => $this->buildBacklogByStatus($events),
				'actions'     => $this->buildActionBreakdown($alerts, $events, $human_userids),
				'analysts'    => $this->buildAnalystBreakdown($events, $human_userids),
				'tenants'     => $this->buildTenantBreakdown($events, $alerts, $human_userids),
				'all_tenants' => $this->fetchAllTenants(),
				'risk'        => $this->buildRiskLevel($events)
			];

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
		}
		catch (\Throwable $e) {
			// Never leak an HTML error page to the AJAX client.
			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode([
					'error' => ['messages' => [$e->getMessage()]]
				])
			]));
		}
	}

	/* ------------------------------------------------------------------ */
	/* Period handling                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{0:int,1:int} [from, till] unix timestamps.
	 */
	private function resolvePeriod(string $period): array {
		$till = time();

		switch ($period) {
			case 'today': $from = strtotime('today'); break;
			case '6h':    $from = $till - 6 * SEC_PER_HOUR; break;
			case '24h':   $from = $till - SEC_PER_DAY; break;
			case '7d':    $from = $till - 7 * SEC_PER_DAY; break;
			case '14d':   $from = $till - 14 * SEC_PER_DAY; break;
			case '30d':   $from = $till - 30 * SEC_PER_DAY; break;
			default:      $from = $till - SEC_PER_DAY;
		}

		return [$from, $till];
	}

	/* ------------------------------------------------------------------ */
	/* Tenant / host group resolution                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Derive the tenant (client) name from a host group name.
	 * "BLUE6IX | LINUX" => "BLUE6IX"; "BLUE6IX" => "BLUE6IX".
	 */
	private function tenantFromGroupName(string $group_name): string {
		$parts = explode(self::TENANT_SEPARATOR, $group_name, 2);

		return trim($parts[0]);
	}

	/**
	 * Resolve host group ids that belong to the selected tenant.
	 * Empty tenant => return null (all groups).
	 *
	 * @return array<int,string>|null
	 */
	private function resolveTenantGroupIds(string $tenant): ?array {
		if ($tenant === '') {
			return null;
		}

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'preservekeys' => true
		]);

		if (!is_array($groups)) {
			return null;
		}

		$ids = [];
		foreach ($groups as $group) {
			if ($this->tenantFromGroupName($group['name']) === $tenant) {
				$ids[] = $group['groupid'];
			}
		}

		return $ids ?: ['0'];
	}

	/**
	 * Full, de-duplicated list of tenant (client) names derived from ALL host
	 * groups — used to populate the tenant filter, independent of the period.
	 *
	 * @return array<int,string> sorted client names
	 */
	private function fetchAllTenants(): array {
		$groups = API::HostGroup()->get([
			'output' => ['name']
		]);

		if (!is_array($groups)) {
			return [];
		}

		$tenants = [];
		foreach ($groups as $group) {
			$name = $this->tenantFromGroupName($group['name']);
			if ($name !== '') {
				$tenants[$name] = true;
			}
		}

		$names = array_keys($tenants);
		natcasesort($names);

		return array_values($names);
	}

	/* ------------------------------------------------------------------ */
	/* Data fetch                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch problem events (trigger-based) in range, with acknowledges,
	 * host groups and recovery info so we can compute all timings.
	 */
	private function fetchEvents(int $from, int $till, ?array $groupids): array {
		// NOTE: event.get does NOT support selectHostGroups. We fetch the host
		// ids here and resolve their groups with a separate host.get call.
		$params = [
			'output'          => ['eventid', 'clock', 'ns', 'name', 'severity', 'r_eventid', 'acknowledged'],
			'selectAcknowledges' => ['clock', 'action', 'userid', 'message'],
			'selectHosts'     => ['hostid'],
			'source'          => EVENT_SOURCE_TRIGGERS,
			'object'          => EVENT_OBJECT_TRIGGER,
			'value'           => TRIGGER_VALUE_TRUE,
			'time_from'       => $from,
			'time_till'       => $till,
			'sortfield'       => ['clock'],
			'sortorder'       => 'ASC'
		];

		if ($groupids !== null) {
			$params['groupids'] = $groupids;
		}

		$events = API::Event()->get($params);
		if (!is_array($events)) {
			return [];
		}

		// Resolve host groups for every host referenced by the events.
		$host_groups = $this->fetchHostGroupsMap($events);

		// Enrich with recovery timestamps (resolution time) in one extra call.
		$r_eventids = [];
		foreach ($events as $e) {
			if ($e['r_eventid'] != 0) {
				$r_eventids[] = $e['r_eventid'];
			}
		}

		$recovery = [];
		if ($r_eventids) {
			$rows = API::Event()->get([
				'output'    => ['eventid', 'clock'],
				'eventids'  => $r_eventids,
				'preservekeys' => true
			]);
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$recovery[$row['eventid']] = (int) $row['clock'];
				}
			}
		}

		foreach ($events as &$e) {
			$e['r_clock'] = ($e['r_eventid'] != 0 && isset($recovery[$e['r_eventid']]))
				? $recovery[$e['r_eventid']]
				: 0;

			// Attach the host groups (name list) derived from the event hosts.
			$groups = [];
			if (!empty($e['hosts'])) {
				foreach ($e['hosts'] as $host) {
					$hid = $host['hostid'];
					if (isset($host_groups[$hid])) {
						foreach ($host_groups[$hid] as $g) {
							$groups[$g['groupid']] = $g;
						}
					}
				}
			}
			$e['hostgroups'] = array_values($groups);
		}
		unset($e);

		return $events;
	}

	/**
	 * Map hostid => list of {groupid, name} for all hosts referenced by events.
	 *
	 * @return array<int|string, array<int, array{groupid:string,name:string}>>
	 */
	private function fetchHostGroupsMap(array $events): array {
		$hostids = [];
		foreach ($events as $e) {
			if (!empty($e['hosts'])) {
				foreach ($e['hosts'] as $host) {
					$hostids[$host['hostid']] = true;
				}
			}
		}

		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output'    => ['hostid'],
			'hostids'   => array_keys($hostids),
			'selectHostGroups' => ['groupid', 'name'],
			'preservekeys' => true
		]);

		if (!is_array($hosts)) {
			return [];
		}

		$map = [];
		foreach ($hosts as $hostid => $host) {
			// Zabbix 7.x returns groups under 'hostgroups'; older under 'groups'.
			$groups = $host['hostgroups'] ?? ($host['groups'] ?? []);
			$map[$hostid] = $groups;
		}

		return $map;
	}

	/**
	 * Fetch alerts (notifications/commands sent by actions) in range,
	 * including the media type name so we can classify automation channels.
	 */
	private function fetchAlerts(int $from, int $till, ?array $groupids): array {
		$params = [
			'output'          => ['alertid', 'eventid', 'clock', 'mediatypeid', 'userid', 'status', 'alerttype'],
			'selectMediatypes' => ['name'],
			'time_from'       => $from,
			'time_till'       => $till
		];

		if ($groupids !== null) {
			$params['groupids'] = $groupids;
		}

		$alerts = API::Alert()->get($params);

		return is_array($alerts) ? $alerts : [];
	}

	/**
	 * User ids that belong to the human analyst group (Monitor).
	 *
	 * @return array<int,bool> map userid => true
	 */
	private function fetchHumanUserIds(): array {
		$groups = API::UserGroup()->get([
			'output'     => ['usrgrpid', 'name'],
			'filter'     => ['name' => self::HUMAN_USER_GROUP],
			'selectUsers' => ['userid', 'username', 'name', 'surname']
		]);

		$map = [];
		if (is_array($groups)) {
			foreach ($groups as $group) {
				if (empty($group['users'])) {
					continue;
				}
				foreach ($group['users'] as $user) {
					$map[(int) $user['userid']] = true;
					$this->human_user_names[(int) $user['userid']] = $this->displayName($user);
				}
			}
		}

		return $map;
	}

	/**
	 * Build a friendly display name for a user: "Name Surname" or username.
	 */
	private function displayName(array $user): string {
		$full = trim(($user['name'] ?? '').' '.($user['surname'] ?? ''));

		return $full !== '' ? $full : ($user['username'] ?? _('Unknown'));
	}

	/* ------------------------------------------------------------------ */
	/* Aggregations                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Headline KPI cards.
	 */
	private function buildCards(array $events): array {
		$new = count($events);
		$closed = 0;
		$open = 0;
		$unassigned = 0;

		foreach ($events as $e) {
			$resolved = ($e['r_eventid'] != 0);
			if ($resolved) {
				$closed++;
			}
			else {
				$open++;
				if (!$this->hasHumanAssignment($e)) {
					$unassigned++;
				}
			}
		}

		return [
			'new'        => $new,
			'closed'     => $closed,
			'open'       => $open,
			'unassigned' => $unassigned
		];
	}

	/**
	 * An event is "assigned" once any acknowledge exists (human touched it).
	 */
	private function hasHumanAssignment(array $event): bool {
		return isset($event['acknowledges']) && count($event['acknowledges']) > 0;
	}

	/**
	 * Response time metrics (seconds), reported as p50/p90/mean.
	 *   - MTTD: detection latency (proxied by ns->0; kept for extensibility).
	 *   - MTTA: create -> first acknowledge.
	 *   - MTTR-respond: create -> first human action.
	 *   - MTTR-resolve: create -> recovery.
	 */
	private function buildResponseTimes(array $events): array {
		$mtta = [];
		$mttr_resolve = [];

		foreach ($events as $e) {
			$create = (int) $e['clock'];

			$first_ack = $this->firstAckClock($e);
			if ($first_ack !== null) {
				$mtta[] = $first_ack - $create;
			}

			if ($e['r_clock'] > 0) {
				$mttr_resolve[] = (int) $e['r_clock'] - $create;
			}
		}

		return [
			// MTTD is 0 here (event.clock is the detection instant in Zabbix).
			'mttd'         => $this->stats([0]),
			'mtta'         => $this->stats($mtta),
			'mttr_respond' => $this->stats($mtta),
			'mttr_resolve' => $this->stats($mttr_resolve)
		];
	}

	/**
	 * Clock of the first acknowledge on an event, or null.
	 */
	private function firstAckClock(array $event): ?int {
		if (empty($event['acknowledges'])) {
			return null;
		}

		$clocks = array_map(static fn ($a) => (int) $a['clock'], $event['acknowledges']);
		sort($clocks);

		return $clocks[0];
	}

	/**
	 * @param array<int,int> $values seconds
	 * @return array{p50:int,p90:int,mean:int,count:int}
	 */
	private function stats(array $values): array {
		$values = array_values(array_filter($values, static fn ($v) => $v >= 0));
		$count = count($values);

		if ($count === 0) {
			return ['p50' => 0, 'p90' => 0, 'mean' => 0, 'count' => 0];
		}

		sort($values);

		return [
			'p50'   => $this->percentile($values, 0.50),
			'p90'   => $this->percentile($values, 0.90),
			'mean'  => (int) round(array_sum($values) / $count),
			'count' => $count
		];
	}

	/**
	 * @param array<int,int> $sorted ascending
	 */
	private function percentile(array $sorted, float $p): int {
		$count = count($sorted);
		if ($count === 0) {
			return 0;
		}
		if ($count === 1) {
			return $sorted[0];
		}

		$rank = $p * ($count - 1);
		$low = (int) floor($rank);
		$high = (int) ceil($rank);
		$weight = $rank - $low;

		return (int) round($sorted[$low] * (1 - $weight) + $sorted[$high] * $weight);
	}

	/**
	 * Severity distribution for the donut, mapping Zabbix severities to the
	 * SOC buckets: Critical / High / Medium / Low / Informational.
	 */
	private function buildSeverityMix(array $events): array {
		$buckets = [
			'critical'      => 0,
			'high'          => 0,
			'medium'        => 0,
			'low'           => 0,
			'informational' => 0
		];

		foreach ($events as $e) {
			switch ((int) $e['severity']) {
				case TRIGGER_SEVERITY_DISASTER:      $buckets['critical']++; break;
				case TRIGGER_SEVERITY_HIGH:          $buckets['high']++; break;
				case TRIGGER_SEVERITY_AVERAGE:       $buckets['medium']++; break;
				case TRIGGER_SEVERITY_WARNING:       $buckets['low']++; break;
				case TRIGGER_SEVERITY_INFORMATION:
				case TRIGGER_SEVERITY_NOT_CLASSIFIED:
				default:                             $buckets['informational']++; break;
			}
		}

		$buckets['total'] = array_sum($buckets);

		return $buckets;
	}

	/**
	 * Open backlog split by workflow status.
	 */
	private function buildBacklogByStatus(array $events): array {
		$new = 0;
		$in_progress = 0;
		$reopened = 0;

		foreach ($events as $e) {
			if ($e['r_eventid'] != 0) {
				continue; // resolved => not in backlog
			}

			if ($this->hasHumanAssignment($e)) {
				$in_progress++;
			}
			else {
				$new++;
			}
		}

		return [
			'new'         => $new,
			'in_progress' => $in_progress,
			'reopened'    => $reopened
		];
	}

	/**
	 * Automation vs human action breakdown.
	 *
	 * Automation = alerts sent through automation media types.
	 * Human      = acknowledge/message actions by users in the Monitor group.
	 */
	private function buildActionBreakdown(array $alerts, array $events, array $human_userids): array {
		$whatsapp = 0;
		$email = 0;
		$cervello = 0;
		$automation_other = 0;
		$automation_failed = 0;

		foreach ($alerts as $a) {
			$mt_name = isset($a['mediatypes'][0]['name']) ? $a['mediatypes'][0]['name'] : '';

			if (!in_array($mt_name, self::AUTOMATION_MEDIATYPES, true)) {
				continue;
			}

			if ((int) $a['status'] == ALERT_STATUS_FAILED) {
				$automation_failed++;
			}

			switch ($mt_name) {
				case self::MEDIATYPE_WHATSAPP: $whatsapp++; break;
				case self::MEDIATYPE_EMAIL:    $email++; break;
				case self::MEDIATYPE_CERVELLO: $cervello++; break;
				default:                       $automation_other++; break;
			}
		}

		// Human actions: acknowledges/messages authored by Monitor users.
		$human_actions = 0;
		foreach ($events as $e) {
			if (empty($e['acknowledges'])) {
				continue;
			}
			foreach ($e['acknowledges'] as $ack) {
				if (isset($human_userids[(int) $ack['userid']])) {
					$human_actions++;
				}
			}
		}

		$automation_total = $whatsapp + $email + $cervello + $automation_other;

		return [
			'automation' => [
				'total'    => $automation_total,
				'whatsapp' => $whatsapp,
				'email'    => $email,
				'cervello' => $cervello,
				'other'    => $automation_other,
				'failed'   => $automation_failed
			],
			'human' => [
				'total' => $human_actions
			]
		];
	}

	/**
	 * Per-analyst breakdown for the Monitor group.
	 *
	 * Counts how many human actions (acknowledges/messages) each analyst
	 * performed in the period, with the percentage of the human total.
	 * Only users in the Monitor group are counted; unique events per analyst
	 * are also tracked so we can show "events handled".
	 *
	 * @return array{total:int, analysts:array<int,array{name:string,actions:int,events:int,percent:float}>}
	 */
	private function buildAnalystBreakdown(array $events, array $human_userids): array {
		$actions_by_user = [];   // userid => action count
		$events_by_user = [];    // userid => set of eventids handled

		foreach ($events as $e) {
			if (empty($e['acknowledges'])) {
				continue;
			}
			foreach ($e['acknowledges'] as $ack) {
				$uid = (int) $ack['userid'];
				if (!isset($human_userids[$uid])) {
					continue;
				}
				$actions_by_user[$uid] = ($actions_by_user[$uid] ?? 0) + 1;
				$events_by_user[$uid][$e['eventid']] = true;
			}
		}

		$total_actions = array_sum($actions_by_user);

		$analysts = [];
		foreach ($actions_by_user as $uid => $count) {
			$analysts[] = [
				'name'    => $this->human_user_names[$uid] ?? ('#'.$uid),
				'actions' => $count,
				'events'  => isset($events_by_user[$uid]) ? count($events_by_user[$uid]) : 0,
				'percent' => $total_actions > 0 ? round(($count / $total_actions) * 100, 1) : 0.0
			];
		}

		// Sort by actions desc.
		usort($analysts, static fn ($x, $y) => $y['actions'] <=> $x['actions']);

		return [
			'total'    => $total_actions,
			'analysts' => $analysts
		];
	}

	/**
	 * Per-tenant rollup keyed by client name (host group prefix).
	 */
	private function buildTenantBreakdown(array $events, array $alerts, array $human_userids): array {
		$tenants = [];

		$ensure = static function (array &$tenants, string $name): void {
			if (!isset($tenants[$name])) {
				$tenants[$name] = [
					'tenant'     => $name,
					'events'     => 0,
					'resolved'   => 0,
					'automation' => 0,
					'human'      => 0
				];
			}
		};

		// Map eventid -> tenant name(s) from host groups on the event.
		$event_tenant = [];
		foreach ($events as $e) {
			$name = $this->primaryTenantForEvent($e);
			$event_tenant[$e['eventid']] = $name;

			$ensure($tenants, $name);
			$tenants[$name]['events']++;
			if ($e['r_eventid'] != 0) {
				$tenants[$name]['resolved']++;
			}
			if (!empty($e['acknowledges'])) {
				foreach ($e['acknowledges'] as $ack) {
					if (isset($human_userids[(int) $ack['userid']])) {
						$tenants[$name]['human']++;
					}
				}
			}
		}

		foreach ($alerts as $a) {
			$mt_name = isset($a['mediatypes'][0]['name']) ? $a['mediatypes'][0]['name'] : '';
			if (!in_array($mt_name, self::AUTOMATION_MEDIATYPES, true)) {
				continue;
			}
			$name = $event_tenant[$a['eventid']] ?? _('Unknown');
			$ensure($tenants, $name);
			$tenants[$name]['automation']++;
		}

		// Sort by event volume desc.
		usort($tenants, static fn ($x, $y) => $y['events'] <=> $x['events']);

		return array_values($tenants);
	}

	/**
	 * Pick the tenant (client) name for an event from its host groups.
	 */
	private function primaryTenantForEvent(array $event): string {
		if (empty($event['hostgroups'])) {
			return _('Unknown');
		}

		// Prefer a base group (no separator) if present; else derive from first.
		foreach ($event['hostgroups'] as $g) {
			if (strpos($g['name'], self::TENANT_SEPARATOR) === false) {
				return $this->tenantFromGroupName($g['name']);
			}
		}

		return $this->tenantFromGroupName($event['hostgroups'][0]['name']);
	}

	/**
	 * Composite risk score (0-100) blending open backlog and severity weight.
	 */
	private function buildRiskLevel(array $events): array {
		if (!$events) {
			return ['score' => 0];
		}

		$weight = 0;
		$max = 0;
		foreach ($events as $e) {
			$open = ($e['r_eventid'] == 0);
			$sev = (int) $e['severity'];
			$max += TRIGGER_SEVERITY_DISASTER;
			if ($open) {
				$weight += $sev;
			}
		}

		$score = $max > 0 ? (int) round(($weight / $max) * 100) : 0;

		return ['score' => min(100, max(0, $score))];
	}
}
