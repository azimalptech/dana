<?php

declare(strict_types=1);

namespace Dana\Database;

use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

final class Bootstrap
{
    private static bool $booted = false;

    public static function boot(Config $config): Capsule
    {
        static $capsule = null;

        if (self::$booted && $capsule !== null) {
            return $capsule;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $config->get('DB_HOST', '127.0.0.1'),
            'port'      => $config->get('DB_PORT', '3306'),
            'database'  => $config->require('DB_DATABASE'),
            'username'  => $config->require('DB_USERNAME'),
            'password'  => $config->get('DB_PASSWORD', '') ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            // FR-8.10: the streak day boundary is decided by the server,
            // in Asia/Ashgabat, never by the device clock.
            'timezone'  => '+05:00',
            'strict'    => true,
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$booted = true;

        return $capsule;
    }
}
