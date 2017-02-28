<?php

namespace Scheduler;

use TaskExecutor;

class SimpleScheduler implements Scheduler
{
    private $taskExecutor;
    
    public function __construct(TaskExecutor $taskExecutor) {
        $this->taskExecutor = $taskExecutor;
    }
    
    public function schedule() {
        
    }
}