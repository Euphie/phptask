<?php

use Logger\SimpleLogger;
use Logger\Logger;
use Scheduler\SimpleScheduler;
use Scheduler\Scheduler;

/**
 * @author Euphie
 */
class TaskExecutor
{
    
    private $isSington = TRUE;
    
    private $pidFile = "";
    
    private $baseDir = "/root";
    
    private $output = "/root/test.log";
    
    private $worksCnt = 0;
    
    private $isGcEnabled = TRUE;
    
    private $user = "root";
    
    private $job;
    
    private $terminate = FALSE;
    
    private $isDaemon = TRUE;
    
    private $isMaster = TRUE;
    
    private $jobs = array();
    
    // 最多运行8个进程
    private $workersMaxNum = 8;
    
    private $scheduleInterval = 2;
    
    private $scheduler = NULL;
    
    private $logger = NULL;
    
    public function __construct($options = array(), AbstractTask $task)
    {
        
        if(!empty($options)) {
            $this->isDaemon = isset($options['daemon']) && $options['daemon'] == 0 ? FALSE : TRUE;
        }
        
        $this->job = $task;
        $this->checkPcntl();
        if($this->isDaemon) {
            $this->daemonize();
        }
    }
    
    //public function addJobs
    
    // 检查环境是否支持pcntl支持
    public function checkPcntl()
    {      
        if (!function_exists('pcntl_signal')) {
            die('PHP does not appear to be compiled with the PCNTL extension.  this is neccesary for daemonization'.PHP_EOL);
        }
        
        if (!function_exists('pcntl_signal_dispatch')) {
            die('function pcntl_signal_dispatch is undefined'.PHP_EOL);
        }
        
        // 信号处理
        pcntl_signal(SIGTERM, array(
            $this,
            "signalHandler"
        ), FALSE);
        pcntl_signal(SIGINT, array(
            $this,
            "signalHandler"
        ), FALSE);
        pcntl_signal(SIGQUIT, array(
            $this,
            "signalHandler"
        ), FALSE);
    
        if (function_exists('gc_enable')) {
            gc_enable();
            $this->isGcEnabled = gc_enabled();
        }
    }
    
    // daemon化程序
    public function daemonize()
    {
        global $stdin, $stdout, $stderr;
        global $argv;
    
        set_time_limit(0);
        ini_set("display_errors", "on");
        error_reporting(E_ALL);
    
        // 只允许在cli下面运行
        if (php_sapi_name() != "cli") {
            die("only run in command line mode".PHP_EOL);
        }

        // 只能单例运行
        if ($this->isSington == TRUE) {
            $this->pidFile = $this->baseDir . "/" . __CLASS__ . "_" . substr(basename($argv[0]), 0, - 4) . ".pid";
            $this->checkPidfile();
        }
        
        // 把文件掩码清0
        umask(0); 
    
        if (pcntl_fork() != 0) { 
            exit();
        }
        
        // 设置新会话组长，脱离终端
        posix_setsid(); 
        
        // 是第一子进程，结束第一子进程
        if (pcntl_fork() != 0) { 
            exit();
        }

        // 改变工作目录
        chdir("/"); 
    
        //$this->setUser($this->user) or die("cannot change owner".PHP_EOL);

        // 关闭打开的文件描述符
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        
        if(!is_file($this->output)) {
            fopen($this->output, 'wb');
        }
        
        $stdin = fopen($this->output, 'r');
        $stdout = fopen($this->output, 'a');
        $stderr = fopen($this->output, 'a');
    
        if ($this->isSington == TRUE) {
            $this->createPidfile();
        }
    }
    
    // 创建pid
    public function createPidfile()
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir);
        }
        $fp = fopen($this->pidFile, 'w') or die("cannot create pid file".PHP_EOL);
        fwrite($fp, posix_getpid());
        fclose($fp);
        $this->_log("create pid file " . $this->pidFile);
    }
    
    // 检测pid是否已经存在
    public function checkPidfile()
    {
        if (!file_exists($this->pidFile)) {
            return TRUE;
        }
        $pid = file_get_contents($this->pidFile);
        $pid = intval($pid);
        if ($pid > 0 && posix_kill($pid, 0)) {
            $this->_log("the daemon process is already started");
        } else {
            $this->_log("the daemon proces end abnormally, please check pidfile " . $this->pidFile);
        }
        exit(1);
    }
    
    // 设置运行的用户
    public function setUser($name)
    {
        $result = FALSE;
        if (empty($name)) {
            return TRUE;
        }
        
        $user = posix_getpwnam($name);
        if ($user) {
            $uid = $user['uid'];
            $gid = $user['gid'];
            $result = posix_setuid($uid);
            posix_setgid($gid);
        }
        return $result;
    }
    
    // 信号处理函数
    public function signalHandler($signo)
    {
        $func = $this->job->getSignalHandler($signo);
        if(!is_null($func) && !$this->isMaster) {
            return call_user_func($func, array($signo));
        }
        
        switch ($signo) {
            // 用户自定义信号
            case SIGUSR1:
                if ($this->worksCnt < $this->workersMaxNum) {
                    $pid = pcntl_fork();
                    if ($pid > 0) {
                        $this->worksCnt ++;
                    }
                }
                break;
                // 子进程结束信号
            case SIGCHLD:
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    $this->worksCnt--;
                }
                break;
                // 中断进程
            case SIGTERM:               
            case SIGHUP:
            case SIGQUIT:  
                $this->terminate = TRUE;
                break;
            default:
                return FALSE;
        }
    }
    
    // 整个进程退出
    public function stop()
    {
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
            $this->_log("delete pid file " . $this->pidFile);
        }
        $this->_log("daemon process exit now");
        posix_kill(0, SIGKILL);
        exit(0);
    }
    
    /**
     * 开始开启进程
     * $count 准备开启的进程数
     */
    public function start($count = 1)
    {
        $this->_log("daemon process is running now");
        // if worker die, minus children num
        pcntl_signal(SIGCHLD, array(
            $this,
            "signalHandler"
        ), FALSE);
        while (TRUE) {
            pcntl_signal_dispatch();
            if ($this->terminate) {
                //break;
            }
            $pid = - 1;
            if ($this->worksCnt < $count) {  
                $pid = pcntl_fork();
            }
    
            if ($pid > 0) {
                $this->worksCnt ++;
            } elseif ($pid == 0) {
                $this->isMaster = FALSE;
                // 这个符号表示恢复系统对信号的默认处理
                // pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGCHLD, SIG_DFL);
                $this->job->init();
                $execTime = $this->job->getMaxExecNum();
                while (TRUE && $execTime > 0) {
                    pcntl_signal_dispatch();
                    $this->job->execute(); 
                    $execTime--;
                }
                exit();
            } else {
                if(is_null($this->scheduler)) {
                    $this->scheduler = new SimpleScheduler($this);
                }
                $this->scheduler->schedule();
                sleep($this->scheduleInterval);
            }
        }
    
        $this->stop();
    }
    
    public function setSchedulerClass($schedulerClass)
    {
        if (!class_exists($schedulerClass)) {
            return FALSE; // TODO Exception
        }
        
        $this->scheduler = new $schedulerClass($this);
        
        if (!($this->scheduler instanceof Scheduler)) {
            return FALSE; // TODO Exception
        }        
    }
    
    public function setLogger(Logger $logger)
    {
        if (!is_object($logger)) {
            return FALSE; // TODO Exception
        }
        if (!($logger instanceof Logger)) {
            return FALSE; // TODO Exception
        }
        $this->_logger = $logger;
    }
    
    // 日志处理
    private function _log($message)
    {
        if (is_null($this->_logger)) {
			$this->_logger = new SimpleLogger();
		}
		
		$this->_logger->log($message);
    }
}

?>