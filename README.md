# Cadastro Único

> Plugin de validação e gestão centralizada de documentos do agente na plataforma Mapas Culturais.

## Efeitos do uso do plugin

- Cria automaticamente uma oportunidade interna do tipo **Cadastro Único**, organizada em três categorias de inscrição: **Certidões**, **Documentos obrigatórios** e **Autodeclarações**.
- Gera três selos vinculados às categorias e aplica o selo correspondente automaticamente quando uma inscrição é aprovada.
- Mantém sincronização bidirecional entre inscrição e selo: aprovar/reprovar a inscrição aplica ou remove o selo; aplicar ou remover o selo manualmente cria ou atualiza a inscrição correspondente.
- Adiciona uma página customizada em `/cadastro-unico` para que o agente gerencie seus documentos por categoria, em seções colapsáveis.
- Exibe uma tarja amarela persistente no topo do site quando há documentos vencidos, próximos do vencimento ou pendentes. A tarja reaparece em todas as navegações e recargas, sem depender de `localStorage`.
- Insere um card de resumo do Cadastro Único na aba principal do painel do usuário.
- Adiciona o item **Cadastro único** no menu dropdown do usuário, nas versões desktop e mobile.
- Impede a edição via API de campos ainda válidos, mesmo que o frontend seja manipulado.
- Garante, por índice único no banco de dados, que cada agente tenha no máximo uma inscrição ativa por categoria.
- Oculta a oportunidade do Cadastro Único das listagens públicas de oportunidades.

## Requisitos Mínimos

- Mapas Culturais v7.9.0^

## Configuração básica

### Ativação

No arquivo `config/plugins.php`, adicione `'CadastroUnico2'` à lista de plugins ativos:

```php
<?php

return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'CadastroUnico2',
    ]
];
```

### Execução do seed

Após ativar o plugin, execute os db-updates para criar a oportunidade, os selos, as categorias e o índice único:

```bash
php scripts/db-update.sh
```

Os seguintes db-updates serão executados:

- `cria oportunidade selos e categorias do cadastro unico`
- `cria indice unico de inscricao por categoria do cadastro unico`

O seed é idempotente: pode ser executado várias vezes sem criar registros duplicados.

## Como funciona

### Oportunidade e categorias

O plugin cria uma única oportunidade do tipo `AgentOpportunity`, identificada pelo metadata `isCadastroUnico2 = true`. Essa oportunidade possui três categorias de inscrição:

| Categoria        | Slug              |
|------------------|-------------------|
| Certidões        | `certidoes`       |
| Documentos obrigatórios | `documentos` |
| Autodeclarações  | `autodeclaracoes` |

Cada categoria tem um selo correspondente. O mapeamento entre categoria e selo é armazenado no metadata `cadastroUnicoCategorySeals` da oportunidade.

### Selos e sincronização

Quando uma inscrição é aprovada, o selo da categoria correspondente é aplicado ao agente. Se a inscrição for reprovada ou enviada para a lixeira, o selo é removido.

A sincronização também funciona no sentido inverso:

- Ao aplicar um selo manualmente, o plugin cria ou atualiza a inscrição correspondente como aprovada.
- Ao remover um selo manualmente, o plugin atualiza a inscrição correspondente para o status de reprovado.

### Página customizada

A página `/cadastro-unico` substitui a visualização padrão da oportunidade para usuários sem permissão de controle. Ela exibe três seções colapsáveis — uma para cada categoria — permitindo ao agente visualizar, enviar e acompanhar a situação de cada documento de forma agrupada.

### Tarja amarela de alerta

Uma tarja amarela é exibida no topo de todas as páginas quando o agente logado possui documentos vencidos, próximos do vencimento ou pendentes. A tarja é recalculada a cada carregamento de página e não usa `localStorage`, de modo que o aviso sempre reflita o estado atual dos documentos.

### Widget no painel

Na aba principal do painel do usuário, o plugin adiciona um card **Cadastro Único** com o resumo do status dos documentos por categoria.

### Menu do usuário

O item **Cadastro único** é inserido no menu dropdown do usuário, direcionando para `/cadastro-unico`, tanto na versão desktop quanto na versão mobile.

### Segurança server-side

O plugin bloqueia, no backend, a edição de campos de inscrição que ainda estejam dentro do prazo de validade. Mesmo que o frontend seja manipulado para enviar dados alterados, a API rejeita a operação e retorna uma mensagem de permissão negada.

### Limite de uma inscrição por categoria

Um índice único no banco de dados garante que cada agente tenha, no máximo, uma inscrição ativa por categoria na oportunidade do Cadastro Único:

```sql
CREATE UNIQUE INDEX registration_cadastrounico_uniq
ON registration (opportunity_id, agent_id, category)
WHERE status >= 0 AND opportunity_id = <id_da_oportunidade>
```

### Ocultação em listagens públicas

A oportunidade do Cadastro Único não aparece em listagens públicas de oportunidades. O plugin aplica um filtro automático nas consultas da API para excluir registros marcados com `isCadastroUnico2 = true` quando o usuário não tem permissão de controle sobre eles.

## Personalização

### Agente proprietário da oportunidade e dos selos

Por padrão, o plugin busca automaticamente um agente administrador para ser o proprietário da oportunidade e dos selos. Para definir um agente específico, use a configuração `ownerAgentId`:

```php
<?php

return [
    'plugins' => [
        'CadastroUnico2' => [
            'namespace' => 'CadastroUnico2',
            'config' => [
                'ownerAgentId' => 1,
            ]
        ]
    ]
];
```

> **Nota:** A configuração acima deve ser feita em `docker/common/config.d/plugins.php` ou no arquivo equivalente do ambiente, antes de executar o seed.

## Observações

- O seed só precisa ser executado uma vez após a ativação do plugin. Execuções subsequentes são seguras e não duplicam dados.
- A configuração `ownerAgentId` é opcional. Se não for informada, o plugin tenta resolver o owner pelas roles `saasSuperAdmin`, `saasAdmin`, `superAdmin` e `admin`, nesta ordem.
- A correspondência entre campos do formulário de inscrição e campos bloqueados do selo é configurada pelo administrador após a instalação, no form builder da oportunidade e na edição do selo. O plugin não cria essa configuração automaticamente no seed.
- A tarja amarela e o widget do painel são exibidos apenas para usuários autenticados que possuem um agente de perfil associado.
- A página `/cadastro-unico` redireciona a single padrão da oportunidade do Cadastro Único para usuários sem permissão de controle, garantindo uma experiência de preenchimento simplificada.
