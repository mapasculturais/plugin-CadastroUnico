<?php
use MapasCulturais\i;

return [
    'msg_sem_cadastro' => i::__('Você ainda não fez seu cadastro único.'),

    // %1$s = nome da categoria obrigatória faltante (ex.: "Certidões").
    'msg_categoria_obrigatoria_faltante' => i::__('Você ainda não enviou os documentos da categoria %1$s.'),

    // %1$s = nome da categoria opcional.
    'msg_selo_vencido_opcional' => i::__('Sua categoria %1$s está com o selo inválido.'),

    // %1$s = nome do campo que expirou (ex.: "comprovante de residência")
    // %2$s = nome da categoria (ex.: "Documentos obrigatórios")
    // %3$s = data de expiração no formato DD/MM/AAAA
    'msg_selo_vencido_obrigatorio' => i::__('Atenção: %1$s da categoria %2$s expirou em %3$s.'),

    'cta_iniciar'   => i::__('Iniciar agora'),  // sem_cadastro
    'cta_enviar'    => i::__('Enviar'),         // categoria_obrigatoria_faltante
    'cta_ver'       => i::__('Ver'),            // selo_vencido_opcional
    'cta_atualizar' => i::__('Atualizar'),      // selo_vencido_obrigatorio

    'banner_aria_prefix' => i::__('Aviso'),

    'close_aria' => i::__('Fechar aviso de cadastro único'),
];
