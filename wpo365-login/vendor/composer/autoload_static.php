<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit572f7295f786031ec8846625768b7988
{
    public static $files = array (
        'decc78cc4436b1292c6c0d151b19445c' => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib/bootstrap.php',
    );

    public static $prefixLengthsPsr4 = array (
        'p' => 
        array (
            'phpseclib3\\' => 11,
        ),
        'W' => 
        array (
            'Wpo\\Tests\\' => 10,
            'Wpo\\Sync\\' => 9,
            'Wpo\\Services\\' => 13,
            'Wpo\\Pages\\' => 10,
            'Wpo\\Mail\\' => 9,
            'Wpo\\Graph\\' => 10,
            'Wpo\\Firebase\\' => 13,
            'Wpo\\Core\\' => 9,
            'Wpo\\Blocks\\' => 11,
            'Wpo\\' => 4,
        ),
        'P' => 
        array (
            'ParagonIE\\ConstantTime\\' => 23,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'phpseclib3\\' => 
        array (
            0 => __DIR__ . '/..' . '/phpseclib/phpseclib/phpseclib',
        ),
        'Wpo\\Tests\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Tests',
        ),
        'Wpo\\Sync\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Sync',
        ),
        'Wpo\\Services\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Services',
        ),
        'Wpo\\Pages\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Pages',
        ),
        'Wpo\\Mail\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Mail',
        ),
        'Wpo\\Graph\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Graph',
        ),
        'Wpo\\Firebase\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Firebase/JWT',
        ),
        'Wpo\\Core\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Core',
        ),
        'Wpo\\Blocks\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Blocks',
        ),
        'Wpo\\' => 
        array (
            0 => __DIR__ . '/../..' . '/',
        ),
        'ParagonIE\\ConstantTime\\' => 
        array (
            0 => __DIR__ . '/..' . '/paragonie/constant_time_encoding/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit572f7295f786031ec8846625768b7988::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit572f7295f786031ec8846625768b7988::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
