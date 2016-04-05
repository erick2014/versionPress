<?php

namespace VersionPress\Utils;

class Process extends \Symfony\Component\Process\Process {

    private $consoleOutput;

    public function __construct($commandline, $cwd = null, array $env = null, $input = null, $timeout = 3600, array $options = array()) {
        parent::__construct($commandline, $cwd, $env, $input, $timeout, $options);
    }

    public function getConsoleOutput() {
        return $this->consoleOutput;
    }

    public function start($callback = null) {
        $this->consoleOutput = '';
        parent::start($callback);
    }

    public function addOutput($line) {
        parent::addOutput($line);
        $this->consoleOutput .= $line;
    }

    public function addErrorOutput($line) {
        parent::addErrorOutput($line);
        $this->consoleOutput .= $line;
    }

}
