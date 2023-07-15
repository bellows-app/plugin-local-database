<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Database;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Facades\Artisan;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Bellows\PluginSdk\PluginResults\InteractsWithDatabases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class LocalDatabase extends Plugin implements Installable, Database
{
    use CanBeInstalled, InteractsWithDatabases;

    protected string $connection;

    protected string $database;

    protected string $username;

    protected string $password;

    protected bool $enableForeignKeys;

    public function install(): ?InstallationResult
    {
        $this->setupLocalDatabase();

        return InstallationResult::create()
            ->environmentVariables($this->environmentVariables())
            ->wrapUp(function () {
                try {
                    Process::runWithOutput(Artisan::local('migrate'));
                } catch (\Exception $e) {
                    Console::error($e->getMessage());
                }
            });
    }

    protected function setupLocalDatabase(): void
    {
        $database = Console::choice('Database', ['MySQL', 'PostgreSQL', 'SQLite'], 0);

        $this->connection = match ($database) {
            'MySQL'      => 'mysql',
            'PostgreSQL' => 'pgsql',
            'SQLite'     => 'sqlite',
        };

        $databaseDefault = $this->connection === 'sqlite'
            ? Project::path('/database/database.sqlite')
            : Str::snake(Project::appName());

        $this->database = Console::ask('Database name', $databaseDefault);
        $this->username = Console::ask('Database user', 'root');
        $this->password = Console::ask('Database password') ?? '';

        $this->createDatabase();
    }

    protected function createDatabase(): void
    {
        if ($this->connection === 'sqlite') {
            $this->enableForeignKeys = Console::confirm('Enable foreign keys?', true);

            if (File::missing($this->database)) {
                File::put($this->database, '');
            }

            return;
        }

        config([
            'database.connections.local_plugin' => [
                'driver'    => $this->connection,
                'host'      => '127.0.0.1',
                'port'      => 3306,
                // Leave blank, otherwise it will try to connect to the database which probably doesn't exist yet
                'database'  => '',
                'username'  => $this->username,
                'password'  => $this->password,
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);

        DB::purge('local_plugin');
        DB::connection('local_plugin')->statement('CREATE DATABASE IF NOT EXISTS ' . $this->database);
    }

    protected function environmentVariables(): array
    {
        $params = [
            'DB_CONNECTION' => $this->connection,
            'DB_DATABASE'   => $this->database,
            'DB_USERNAME'   => $this->username,
            'DB_PASSWORD'   => $this->password,
        ];

        if (isset($this->enableForeignKeys)) {
            $params['DB_FOREIGN_KEYS'] = $this->enableForeignKeys;
        }

        return $params;
    }
}
