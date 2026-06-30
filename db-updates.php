<?php

use CadastroUnico\Setup;
use MapasCulturais\App;

return [
    'cria oportunidade selos e categorias do cadastro unico' => function () {
        $app = App::i();

        Setup::install($app, null, false);
    },

    'cria indice unico de inscricao por categoria do cadastro unico' => function () {
        $app = App::i();
        $conn = $app->em->getConnection();

        $opportunity_id = $conn->fetchOne(
            "SELECT o.id
             FROM opportunity o
             INNER JOIN opportunity_meta om ON om.object_id = o.id
             WHERE om.key = 'isCadastroUnico' AND om.value = '1'
             LIMIT 1"
        );

        if (!$opportunity_id) {
            $app->log->info('[CadastroUnico] cria indice unico: oportunidade do Cadastro Unico ainda nao existe. Pulando.');
            return;
        }

        $conn->executeQuery(
            "CREATE UNIQUE INDEX IF NOT EXISTS registration_cadastrounico_uniq
             ON registration (opportunity_id, agent_id, category)
             WHERE status >= 0 AND opportunity_id = " . $conn->quote($opportunity_id, \PDO::PARAM_INT)
        );

        $app->log->info(sprintf(
            '[CadastroUnico] Índice registration_cadastrounico_uniq criado/verificado para opportunity_id %d.',
            $opportunity_id
        ));
    },
];
