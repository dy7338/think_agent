<?php
declare (strict_types = 1);

namespace dy7338\think_agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use dy7338\think_agent\lib\Process;
use think\facade\Log;
use think\facade\App;
use think\facade\Config;

class Agent extends Command
{
    private $serv;

    public function initialize (Input $input, Output $output) {
        parent::initialize ($input, $output);

        Process::init ();//载入任务处理表+日志
    }

    protected function configure () {
        // 指令配置
        $this->setName ('agent')
             ->addArgument ('action', Argument::OPTIONAL, "start|stop|restart|reload", 'start')
             ->setDescription ('cron agent for thinkphp');
    }


    protected function execute (Input $input, Output $output) {

        $this->checkEnvironment ();

        $action = $input->getArgument ('action');

        if (in_array ($action, ['start', 'stop', 'reload', 'restart'])) {
            $this->app->invokeMethod ([$this, $action], [], true);
        } else {
            $output->writeln ("<error>Invalid argument action:{$action}, Expected start|stop .</error>");
        }

    }

    /**
     * 启动server
     *
     * @return void
     */
    protected function start () {

        if ($this->isRunning ()) {
            $this->output->highlight ('服务正在运行中。。。');

            return;
        }

        $this->serv = new \swoole_server('0.0.0.0', Config::get ('agent.agent_port'));
        $this->serv->set (Config::get ('agent.agent_config'));
        $this->serv->on ('ManagerStart', [$this, 'onManagerStart']);
        $this->serv->on ('WorkerStart', [$this, 'onWorkerStart']);
        $this->serv->on ('WorkerError', [$this, 'onWorkerError']);
        $this->serv->on ('WorkerStop', [$this, 'onWorkerStop']);
        $this->serv->on ('ManagerStop', [$this, 'onManagerStop']);
        $this->serv->on ('Receive', [$this, 'onReceive']);
        $this->serv->on ('Task', [$this, 'onTask']);
        $this->serv->on ('Finish', [$this, 'onFinish']);

        $this->serv->start ();

    }

    /**
     * 停止服务
     */
    protected function stop () {

        if (!$this->isRunning ()) {
            $this->output->highlight ('服务未启动。。。');

            return;
        }

        if (\swoole_process::kill ($this->getPid (), 15)) {
            $this->output->question ('服务已停止。。。');
        } else {
            $this->output->highlight ('服务停止失败。。。');
        }

    }


    /**
     * 当管理进程启动时调用它,manager进程中不能添加定时器,manager进程中可以调用task功能
     *
     * @param $server
     */
    public function onManagerStart ($server) {
        $this->output->question ('服务启动成功。。。');
    }

    /**
     * 此事件在worker进程/task进程启动时发生。这里创建的对象可以在进程生命周期内使用
     *
     * @param  \Swoole\Server  $server
     * @param                  $worker_id
     */
    public function onWorkerStart (\Swoole\Server $server, $worker_id) {

        //注册信号
        Process::signal ();
        if ($worker_id == (Config::get ('agent.agent_config.worker_num') - 1)) {
            //10秒钟发送一次信号给中心服，证明自己的存在
            $server->tick (10000, function () use ($server) {
                $centerClient = new \swoole_client(SWOOLE_SOCK_TCP);
                try {
                    if ($centerClient->connect (Config::get ('agent.center.host'), Config::get ('agent.center.port'), 0.5)) {
                        $agentIp = "0.0.0.0";
                        foreach (swoole_get_local_ip () as $v) {
                            if (substr ($v, 0, 7) == '192.168' || substr ($v, 0, 5) == '10.10') {
                                $agentIp = $v;
                            }
                        }
                        $centerClient->send (json_encode (["call" => "heart beat", "params" => ['hostname' => gethostname (), 'ip' => $agentIp, 'port' => Config::get ('agent.agent_port')]]) . "\r\n");
                    } else {
                        Log::write ('connect heart beat center Client failed', 'error');
                    }
                } catch (\Exception $e) {
                    Log::write ('connect center falied', 'error');
                }


            });
        }
    }

    /**
     * 当worker/task_worker进程发生异常后会在Manager进程内回调此函数
     *
     * @param  \Swoole\Server  $server
     * @param                  $worker_id  异常进程的编号
     * @param                  $worker_pid 异常进程的ID
     * @param                  $exit_code  退出的状态码，范围是 1 ～255
     */
    public function onWorkerError (\Swoole\Server $server, $worker_id, $worker_pid, $exit_code) {
        Log::write ("worker/task_worker进程发生异常: worker_id:" . $worker_id . " worker_pid,: " . $worker_pid . " exit_code: " . $exit_code, 'error');
    }

    /**
     * 此事件在worker进程终止时发生。在此函数中可以回收worker进程申请的各类资源
     *
     * @param  \Swoole\Server  $server
     * @param                  $worker_id 是一个从0-$worker_num之间的数字，表示这个worker进程的ID,$worker_id和进程PID没有任何关系
     */
    public function onWorkerStop (\Swoole\Server $server, $worker_id) {
        if (is_writable (Config::get ('agent.agent_config.pid_file'))) {
            return unlink (Config::get ('agent.agent_config.pid_file'));
        }
        Log::write ("WorkerStop;worker进程终止: worker_id:" . $worker_id, 'info');
    }

    /**
     * 当管理进程结束时调用它
     *
     * @param  \Swoole\Server  $server
     */
    public function onManagerStop (\Swoole\Server $server) {
        //当管理进程推出前需要删除所有在运行的任务,否则会造成僵尸进程,从Process内存表中读取
        $process = Process::notify ();
        foreach ($process as $k => $v) {
            \swoole_process::kill ($k, SIGTERM);
        }
        unset($k, $v, $process);
        Log::write ("ManagerStop;管理进程结束", 'info');
    }

    /**
     * 详情  http://wiki.swoole.com/wiki/page/50.html
     *
     * @param  \Swoole\Server  $server  swoole_server对象
     * @param                  $fd      TCP客户端连接的文件描述符
     * @param                  $from_id TCP连接所在的Reactor线程ID
     * @param                  $data    收到的数据内容，可能是文本或者二进制内容
     */
    public function onReceive (\Swoole\Server $server, $fd, $from_id, $data) {
        $data = json_decode (trim ($data), true);
        if (!isset($data['call']) || !isset($data['params'])) {
            Log::write ("请求数据的格式不正确", 'info');
            $server->send ($fd, "请求数据的格式不正确\r\n");
        } else {
            //分发调用方法执行任务
            Process::deliver ($data);
        }
    }

    /**
     * @param  \Swoole\Server  $server  swoole_server swoole_server对象
     * @param                  $task_id int 任务id
     * @param                  $from_id int 投递任务的worker_id
     * @param                  $data    string 投递的数据
     *
     * @return mixed
     */
    public function onTask (\Swoole\Server $server, $task_id, $from_id, $data) {

    }

    public function onFinish ($server, $task_id, $data) {

    }

    /**
     * 获取进程pid
     *
     * @return int
     */
    private function getPid () {

        if (is_readable (Config::get ('agent.agent_config.pid_file'))) {
            $content = file_get_contents (Config::get ('agent.agent_config.pid_file'));

            return (int) $content;
        } else {
            return 0;
        }
    }


    /**
     * 是否运行中
     *
     * @return bool
     */
    private function isRunning () {
        $pid = $this->getPid ();

        if (!$pid) {
            return false;
        }

        return $pid && \swoole_process::kill ((int) $pid, 0);
    }

    /**
     * 检查环境
     */
    protected function checkEnvironment () {
        if (!extension_loaded ('swoole')) {
            $this->output->error ('Can\'t detect Swoole extension installed.');

            exit(1);
        }

        if (!version_compare (swoole_version (), '4.4', 'ge')) {
            $this->output->error ('Your Swoole version must be higher than `4.4`.');

            exit(1);
        }
    }
}
