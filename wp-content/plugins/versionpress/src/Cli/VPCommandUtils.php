<?php

namespace VersionPress\Cli;

use VersionPress\Utils\Process;

class VPCommandUtils {
    public static function runWpCliCommand($command, $subcommand, $args = array(), $cwd = null) {

        $cliCommand = "wp $command";

        if ($subcommand) {
            $cliCommand .= " $subcommand";
        }

        if (defined('WP_CLI') && WP_CLI && \WP_CLI::get_runner()->in_color()) {
            $args['color'] = null;
        }

        foreach ($args as $name => $value) {
            if (is_int($name)) { 
                $cliCommand .= " " . escapeshellarg($value);
            } elseif ($value !== null) {
                $cliCommand .= " --$name=" . escapeshellarg($value);
            } else {
                $cliCommand .= " --$name";
            }
        }

        return self::exec($cliCommand, $cwd);
    }

    public static function exec($command, $cwd = null) {
        
        
        
        if (isset($_SERVER["XDEBUG_CONFIG"])) {
            $env = $_SERVER;
            unset($env["XDEBUG_CONFIG"]);
        } else {
            $env = null;
        }

        $process = new Process($command, $cwd, $env);
        $process->run();
        return $process;
    }

    public static function cliQuestion($question, $values, $assoc_args = array()) {

        if (isset($assoc_args['yes'])) {
            return in_array('y', $values) ? 'y' : $values[0];
        }

        fwrite(STDOUT, $question . " [" . implode('/', $values) . "] ");
        $answer = trim(fgets(STDIN));

        if (!in_array($answer, $values)) {
            $answer = $values[0];
        }

        return $answer;
    }
}
