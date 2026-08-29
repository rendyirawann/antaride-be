<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "monthly", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | matching — jejak pencarian driver
        |----------------------------------------------------------------------
        |
        | Dipisah dari log utama karena volumenya beda kelas: setiap order
        | menghasilkan sampai empat baris gelombang, dan kalau bercampur dengan
        | log aplikasi, satu jam sibuk cukup untuk menenggelamkan seluruh
        | exception yang benar-benar perlu dibaca.
        |
        | Isinya juga yang paling sering ditanyakan: "kenapa order saya lama
        | dapat driver" dan "kenapa saya tidak pernah ditawari" keduanya
        | dijawab dari sini, lengkap dengan radius, jumlah kandidat, dan
        | rincian skornya.
        |
        | Retensi 14 hari: cukup untuk menelusuri keluhan yang masuk seminggu
        | setelah kejadian, dan tidak menumpuk selamanya.
        |
        */
        /*
        |----------------------------------------------------------------------
        | sms — jejak pengiriman OTP dan pemberitahuan
        |----------------------------------------------------------------------
        |
        | Dipisah karena isinya menyangkut nomor HP, dan retensinya harus lebih
        | pendek dari log lain. Nomornya sudah tersamarkan sebelum ditulis, tapi
        | volume dan waktunya sendiri sudah cukup untuk menyimpulkan pola.
        |
        | 7 hari: cukup untuk menyelidiki keluhan "OTP saya tidak sampai" yang
        | masuk beberapa hari kemudian, dan cukup pendek untuk tidak menjadi
        | arsip nomor HP.
        |
        */
        'sms' => [
            'driver' => 'daily',
            'path' => storage_path('logs/sms.log'),
            'level' => env('LOG_SMS_LEVEL', 'info'),
            'days' => (int) env('LOG_SMS_DAYS', 7),
            'replace_placeholders' => true,
        ],

        /*
        |----------------------------------------------------------------------
        | Demo — setiap pemakaian akun demo
        |----------------------------------------------------------------------
        |
        | Masuk lewat akun demo melewati OTP sepenuhnya. Itu memang gunanya, tapi
        | berarti tidak ada jejak autentikasi yang biasanya tertinggal.
        |
        | Kalau nanti ada yang bertanya "kenapa akun ini membuat order itu",
        | jawabannya harus ada di sini — beserta IP dan perangkatnya. Channel
        | tersendiri supaya barisnya tidak tertimbun log aplikasi biasa.
        |
        | Retensi 90 hari: cukup untuk menelusuri sesi pengujian yang
        | dipertanyakan, dan tidak menumpuk selamanya untuk fitur yang seharusnya
        | mati di produksi.
        |
        */
        'demo' => [
            'driver' => 'daily',
            'path' => storage_path('logs/demo.log'),
            'level' => 'info',
            'days' => 90,
            'replace_placeholders' => true,
        ],

        'matching' => [
            'driver' => 'daily',
            'path' => storage_path('logs/matching.log'),
            'level' => env('LOG_MATCHING_LEVEL', 'info'),
            'days' => (int) env('LOG_MATCHING_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'monthly' => [
            'driver' => 'monthly',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 3,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
