<?php
/**
 *
 * @package    CadastroUnico2
 * @subpackage Services
 */

namespace CadastroUnico2\Services;

use MapasCulturais\App;

class OpportunityVisibilityFilter
{
    /**
     * Padrões de URI que indicam contexto privado (UX), em ordem de avaliação.
     *
     * Todos são prefixos de path (sem query string). O match é feito contra
     * o path da URL, não contra domínio ou scheme.
     */
    private const PRIVATE_URI_PATTERNS = [
        '#^/painel#',
        '#^/oportunidade/#',
        '#^/cadastro-unico#',
        '#^/inscricao#',
        '#^/minhas-inscricoes#',
    ];

    /**
     * Decide se o request atual está em contexto público.
     *
     * @param App $app
     * @return bool true = contexto público (deve filtrar); false = privado.
     */
    public static function isPublicContext(App $app): bool
    {
        if ($app->user->is('admin')) {
            return false;
        }

        $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
        if ($opportunity && $opportunity->canUser('@control', $app->user)) {
            return false;
        }

        if (self::requestUriMatchesPrivatePattern()) {
            return false;
        }

        return true;
    }

    /**
     * Aplica o filtro de não-listagem pública em uma ApiQuery de Opportunity.
     *
     * Em contexto público, adiciona uma cláusula DQL que exclui oportunidades
     * marcadas com o metadata `isCadastroUnico2 = '1'`.
     *
     * @param App    $app
     * @param string $query String DQL passada por referência pelo hook
     *                      ApiQuery(Opportunity).where.
     */
    public static function applyFilter(App $app, string &$query): void
    {
        if (!self::isPublicContext($app)) {
            return;
        }

        $where = (string) $query;

        $where .= " AND NOT EXISTS (
            SELECT 1 FROM MapasCulturais\\Entities\\OpportunityMeta om_filter
            WHERE om_filter.owner = e
            AND om_filter.key = 'isCadastroUnico2'
            AND om_filter.value = '1'
        )";

        $query = $where;
    }

    /**
     * Verifica se o path do REQUEST_URI atual casa com algum padrão privado.
     *
     * @return bool
     */
    private static function requestUriMatchesPrivatePattern(): bool
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($request_uri, PHP_URL_PATH) ?: $request_uri;

        foreach (self::PRIVATE_URI_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
