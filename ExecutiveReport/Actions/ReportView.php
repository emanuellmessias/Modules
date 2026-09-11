<?php

namespace Modules\ExecutiveReport\Actions;

use CController;
use CControllerResponseData;

use CWebUser;

use Modules\ExecutiveReport\Classes\Client;
use Modules\ExecutiveReport\Classes\ExecutiveReport;
use Modules\ExecutiveReport\Classes\Security;

/**
 * Controller principal do Executive Report.
 *
 * Responsável apenas por:
 *  - validar entrada;
 *  - verificar permissões;
 *  - carregar clientes;
 *  - solicitar a geração do relatório;
 *  - enviar os dados para a View.
 */
class ReportView extends CController {

    /**
     * Períodos permitidos no filtro (em dias).
     */
    private const ALLOWED_PERIODS = [7, 15, 30, 90];

    /**
     * Inicialização do controller.
     */
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    /**
     * Validação dos parâmetros recebidos.
     */
    protected function checkInput(): bool {

        return $this->validateInput([
            'groupid' => 'int32',
            'period'  => 'in ' . implode(',', self::ALLOWED_PERIODS)
        ]);
    }

    /**
     * Verifica se o usuário possui permissão para acessar o módulo.
     */
    protected function checkPermissions(): bool {

        return Security::hasAccess();
    }

    /**
     * Executa a ação.
     */
    protected function doAction(): void {

        $groupid = (int) $this->getInput('groupid', 0);
        $period = (int) $this->getInput('period', 30);

        $this->setResponse(
            new CControllerResponseData([

                'title' => 'Executive Report',

                /*
                 * Lista de clientes
                 */
                'clients' => Client::getAll(),

                /*
                 * Indica se o usuário é Super Admin (libera troca de cliente
                 * mesmo quando existe apenas um cliente cadastrado).
                 */
                'is_admin' => (CWebUser::$data['type'] ?? 0) == USER_TYPE_SUPER_ADMIN,

                /*
                 * Cliente selecionado
                 */
                'selected_groupid' => $groupid,

                /*
                 * Período selecionado (em dias)
                 */
                'selected_period' => $period,

                /*
                 * Relatório completo
                 */
                'report' => ExecutiveReport::build($groupid, $period)

            ])
        );
    }

}
