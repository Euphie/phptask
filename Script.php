<?php
/**
 * PHP文件
 *
 * @author    Euphie Chen
 * @copyright 2016-2016 IGG Inc.
 */

use Task\TestTask;

pcntl_signal(SIGTERM, function() {
    echo "i am killed".PHP_EOL;
    exit;
});

$options = array();
$options['daemon'] = 0;
$daemon = new TaskExecutor($options, new TestTask(2, 1000));

$daemon->start(2);