<?php

return [
    'center' => [
        'host' => '127.0.0.1',
        'port' => 9502
    ],

    'agent_port'   => 9501,
    'agent_config' => [
        'daemonize'      => true, //进程守护模式
        'worker_num'     => 4,
        'max_request'    => 0,
        'dispatch_mode'  => 3,
        'open_eof_split' => true,
        'package_eof'    => "\r\n",
        'pid_file'       => runtime_path () . './agent.pid',
//        'log_file'       => runtime_path () . './agent.log',
    ]
];