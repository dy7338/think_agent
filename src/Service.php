<?php

namespace dy7338\think_agent;

use think\Service as BaseService;

class Service extends BaseService
{
    public function register () {
        $this->commands (['agent' => '\\dy7338\\think_agent\\command\\Agent']);
    }
}
