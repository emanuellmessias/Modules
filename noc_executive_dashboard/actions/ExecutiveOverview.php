<?php declare(strict_types = 1);

namespace Modules\NocExecutiveDashboard\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;

/**
 * Controller for the NOC Executive Overview page.
 *
 * Renders the dashboard shell (filters + empty cards). All heavy data is
 * loaded asynchronously by the ExecutiveData controller (noc.executive.data).
 */
class ExecutiveOverview extends CController {

	protected function init(): void {
		// The page renders its own layout; disable SID validation for a GET page.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'period'  => 'in today,6h,24h,7d,14d,30d',
			'tenant'  => 'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		// Any authenticated user with UI access may open the page.
		return $this->checkAccess(CRoleHelper::UI_MONITORING_DASHBOARD)
			|| $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$data = [
			'period' => $this->hasInput('period') ? $this->getInput('period') : '24h',
			'tenant' => $this->hasInput('tenant') ? $this->getInput('tenant') : '',
			'periods' => [
				'today' => _('Today'),
				'6h'    => _('6H'),
				'24h'   => _('24H'),
				'7d'    => _('7D'),
				'14d'   => _('14D'),
				'30d'   => _('30D')
			]
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('NOC - Dashboard Executivo'));
		$this->setResponse($response);
	}
}
