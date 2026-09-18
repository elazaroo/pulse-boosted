<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase, WithWorkbench;

    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        $this->usesTestingFeature(new WithMigration('laravel', 'queue'));

        parent::setUp();

        AliasLoader::getInstance()->setAliases([]);
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config) {
            $config->set('queue.failed.driver', 'null');

            // SQL Server returns every column as a string unless the driver is told
            // to preserve numeric types, which would make assertions on integers
            // fail for reasons that have nothing to do with the code under test.
            if ($config->get('database.default') === 'sqlsrv') {
                $config->set('database.connections.sqlsrv.options', [
                    \PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
                ]);

                // The server used for testing has a self signed certificate, which
                // newer versions of the ODBC driver reject by default.
                $config->set('database.connections.sqlsrv.trust_server_certificate', true);
            }
        });
    }
}
