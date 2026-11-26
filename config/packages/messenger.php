<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'messenger' => [
            // Every message carries an id, and a message dispatched by a handler records its cause and shares the root's correlation.
            'identity_stamps' => true,
            'failure_transport' => 'failed',
            'transports' => [
                // The transports of the async workflow transitions (bundle.load, bundle.fetch.docs,
                // bundle.embed, bundle.extract) are declared by survos/workflow-messenger-bundle
                // from the #[Transition(async: true)] attributes in src/Workflow; see
                // config/packages/survos_workflow_messenger.yaml. Add a transport here only
                // to override one of them.
                // "elastic" is declared by survos/elastic-bundle; see config/packages/survos_elastic.yaml.
                'failed' => 'doctrine://default?queue_name=failed&auto_setup=true',
                'sync' => 'sync://',
            ],
            'default_bus' => 'messenger.bus.default',
            'buses' => [
                'messenger.bus.default' => [
                    'middleware' => ['logging'],
                ],
            ],
        ],
    ],
]);
