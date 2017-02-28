<?php
namespace Logger;

class SimpleLogger implements Logger
{
    public function log($message) {
        printf("%s\t%d\t%d\t%s\n", @date("c"), posix_getpid(), posix_getppid(), $message);
    }    
}

?>