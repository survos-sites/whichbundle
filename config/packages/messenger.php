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
                // Both call GitHub. When it rate-limits (HTTP 429), stop consuming the queue for a
                // while instead of spending every message's retries: five failures in a row pause
                // it for a minute, doubling up to fifteen. Merged over the declared transports.
                'bundle.load' => ['cooldown_strategy' => ['failure_threshold' => 5, 'delay' => 60_000, 'multiplier' => 2, 'max_delay' => 900_000]],
                'bundle.fetch.docs' => ['cooldown_strategy' => ['failure_threshold' => 5, 'delay' => 60_000, 'multiplier' => 2, 'max_delay' => 900_000]],
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
