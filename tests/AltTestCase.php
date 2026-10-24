<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests;

/**
 * Same suite scaffolding with bigint keys, string morphs and the HTTP API on.
 */
abstract class AltTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('docket.key_type', 'id');
        $app['config']->set('docket.morph_key_type', 'string');
        $app['config']->set('docket.table_prefix', 'dk_');
        $app['config']->set('docket.api.enabled', true);
        $app['config']->set('docket.api.middleware', ['api']);
    }
}
