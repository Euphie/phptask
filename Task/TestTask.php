<?php


/**
 * @author Euphie
 *
 */
namespace Task;

class TestTask extends AbstractTask {
       
    public function init() {
        echo "init".PHP_EOL;
    }
    
    public function execute() {
        for ($i = 1; $i <= 3;$i++) {
            sleep(1);
            echo $i . " ";
        }
        echo PHP_EOL;
        sleep(1);
    }
    
    public function getSignalHandler($signo) {
        switch ($signo) {
            case SIGTERM || SIGHUP || SIGINT:
                return function($signo) {
                    echo "我死了".PHP_EOL;
                    exit();
                };
            default:
                return NULL;
        }
    }
} 