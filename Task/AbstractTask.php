<?php

/**
 * @author Euphie
 *
 */
namespace Task;

abstract class AbstractTask implements Task
{   
    
    private $workerNum = 3;
    
    private $maxExecNum = 1000;   
    
    public function __construct($workerNum, $maxExecNum) {
        $this->workerNum = $workerNum;
        $this->maxExecNum = $maxExecNum;
    }
    
    public function getWorkerNum() {
        return $this->workerNum;
    }
    
    public function getMaxExecNum() {
        return $this->maxExecNum;
    }
    
    public function getSignalHandler($signo) {
        
    }   
}