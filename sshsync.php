#!/usr/bin/env php
<?php

declare(ticks = 1);

class SSHSync
{
    public const FILE_UPDATED = 'updated';
    public const FILE_DELETED = 'deleted';

    private $local_dir;
    private $ssh_key_path;
    private $rsync_args = '';
    private $exclude_pattern = [];
    private $rsync_exclude_pattern = '';
    private $remote_address;
    private $remote_dir;
    private $remote_path;
    private $timeout = 5;

    private $masterConnection = null;

    private function parseArguments($argv, $argc)
    {
        // Parse the command-line arguments
        $options = getopt("i:v:r:e:t:");
        if (isset($options['i'])) {
            $this->ssh_key_path = $options['i'];
        }
        if (isset($options['r'])) {
            $this->rsync_args = $options['r'];
        }
        if (isset($options['t'])) {
            $this->timeout = (int)$options['t'];
        }
        if (isset($options['e'])) {
            $this->exclude_pattern = explode('|', $options['e']);
            $this->rsync_exclude_pattern = implode(' ', $this->generateRsyncExcludes($this->exclude_pattern));
        }

        $this->local_dir = $argv[$argc - 3] ?? '';
        $this->remote_address = $argv[$argc - 2] ?? '';
        $this->remote_dir = $argv[$argc - 1] ?? '';

        if (empty($argv[$argc - 3]) || empty($argv[$argc - 1])) {
            $this->echoLog("Bad params");
            die();
        }

        $this->local_dir = rtrim($this->local_dir, '/');
        $this->remote_dir = rtrim($this->remote_dir, '/');

        $this->remote_path = $this->remote_address . ':' . $this->remote_dir . '/';
    }

    function generateRsyncExcludes($patterns) {
        $excludes = array();
        foreach ($patterns as $p) {
            $excludes[] = "--exclude=" . escapeshellarg($p);
        }
    
        return $excludes;
    }

    private function sshCmd()
    {
        $identityFile = ($this->ssh_key_path ? ' -i ' . $this->ssh_key_path : '');
        return 'ssh -o ControlMaster=auto -o ControlPath="/tmp/sshsync-%L-%r@%h:%p" -o ConnectTimeout=' . $this->timeout . ' -o ConnectionAttempts=1 ' . $identityFile;
    }

    private function openMasterConnection()
    {
        // closing any existing master-connection
        $cmd = $this->sshCmd() . ' -O exit'.' ' . $this->remote_address ." 2>&1 ";
        exec($cmd, $output, $ret_val);

        $this->masterConnection = null;

        $descriptors = array(
            0 => array('pipe', 'r'), // stdin
            1 => array('pipe', 'w'), // stdout
            2 => array('pipe', 'w'), // stderr
        );

        // starting a new master-connection
        $masterCmd = $this->sshCmd() . ' -M -t -o ServerAliveInterval=' . $this->timeout . ' -o ServerAliveCountMax=1 -o ControlPersist=1s ' . $this->remote_address . ' '.escapeshellarg('echo done && sleep infinity');

        $this->echoLog("Opening master connection ... ", false);

        $this->masterConnection = proc_open($masterCmd, $descriptors, $pipes);
        $masterResult = rtrim(fgets($pipes[1]), "\n");
        if (empty($masterResult)) {
            $masterResult = 'failed';
        }
        $this->echoLog($masterResult, true, false);
        
        $status = proc_get_status($this->masterConnection);
        if (empty($status['running'])) {
            proc_close($this->masterConnection);
            $this->masterConnection = null;
            return false;
        }

        return true;
    }

    private function sigint_handler($signo) {
        $this->echoLog("!!! sigint_handler !!!");

        $this->my_shutdown_function();
        exit(0);
    }

    public function my_shutdown_function() {
        static $shuttingDown = false;
        if ($shuttingDown) {
            return;
        }
        $shuttingDown = true;

        $this->echoLog("Script shutting down ...");

        if ($this->masterConnection) {
            $cmd = $this->sshCmd() . ' -O exit'.' ' . $this->remote_address ." 2>&1 ";
            exec($cmd, $output, $ret_val);
        }
    }

    private function echoLog($message, $addLineBreak = true, $printDate = true) {
        print ($printDate ? date('H:i:s') . '  ##  ' : '' ) . $message . ($addLineBreak ? "\n" : "");
    }

    public function __construct($argv, $argc)
    {
        $this->parseArguments($argv, $argc);

        // Register the signal handler for SIGINT
        $res = pcntl_signal(SIGINT, [$this, 'sigint_handler']);
        register_shutdown_function([$this, 'my_shutdown_function']);

        while (true) {
            $this->appLoop();
            $this->echoLog("Restarting ...");
            sleep(3);
        }
    }

    private function appLoop() {
        if (!$this->openMasterConnection()) {
            return;
        }

        $this->echoLog("Doing initial rsync ...", false);
        $this->rsyncFiles('./', $this->remote_path);

        // Start the inotifywait process
        $inotifywait_cmd = "inotifywait -m -r --format '%e %w%f' " . escapeshellarg($this->local_dir);
        $descriptors = array(
            0 => array('pipe', 'r'), // stdin
            1 => array('pipe', 'w'), // stdout
            2 => array('pipe', 'w'), // stderr
        );
        $process = proc_open($inotifywait_cmd, $descriptors, $pipes);
        $this->echoLog("Started inotify monitor");

        // Set the file descriptor to non-blocking mode
        stream_set_blocking($pipes[1], 0);

        $poolOfChanges = [];

        $ttt = time();
        while (true) {
            if (time() - $ttt > 3) {
                $status = proc_get_status($this->masterConnection);
                
                if (empty($status['running'])) {
                    $this->echoLog("Restarting master connection");
                    break;
                }
                $ttt = time();
            }

            $read = array($pipes[1]);
            $write = null;
            $except = null;
            $timeoutMicroseconds = 0.3 * 1000000;

            // loop while waiting for the data to be available
            if (@stream_select($read, $write, $except, 0, $timeoutMicroseconds) === false) {
                // An error occurred while waiting for data
                break;
            }

            // Read the output from inotifywait
            $notificationEvent = rtrim(fgets($pipes[1]), "\n");
            if (empty($notificationEvent)) {
                if (!count($poolOfChanges)) {
                    continue;
                }

                $this->syncFiles($poolOfChanges);
                $poolOfChanges = [];

                continue;
            }

            list($events, $eventFullPath) = explode(' ', $notificationEvent, 2);
            $events = explode(',', $events);
            if (in_array('ISDIR', $events)) {
                // skip folder-events caused by in-folder file-changes
                continue;
            }

            if (is_dir($eventFullPath) && substr($eventFullPath, -1) !== '/') {
                // folder-paths must always contain an ending "/"
                $eventFullPath .= '/';
            }
            $event_path = str_replace($this->local_dir . '/', '', $eventFullPath);

            //print "CHECK evt: [".implode('+', $events)."] ".$event_path."\n";
            foreach ($this->exclude_pattern as $pattern){
                if (fnmatch($pattern, $event_path)) {
                    //print "SKIP evt: [".implode('+', $events)."] ".$event_path."\n";
                    continue(2);
                }
            }

            $k = md5($event_path);
            $poolOfChanges[$k] = $event_path;
        }

        $this->echoLog("Closing inotify proc ... ", false);

        // Clean up the process
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        
        $this->echoLog("done", true, false);
    }

    private function rsyncFiles($from, $to) {
        if (!is_array($from)) {
            $from = [$from];
        }

        $escapedFrom = array_map(function ($file) {
            return escapeshellarg($file);
        },$from);

        $to = escapeshellarg($to);

        $cmd = 'cd ' . escapeshellarg($this->local_dir.'/') . ' && rsync -azER -e ' . escapeshellarg($this->sshCmd()) . ' ' . $this->rsync_args . ' ' . $this->rsync_exclude_pattern . ' '.implode(' ', $escapedFrom).' ' . $to;
        $this->runCmd($cmd);
    }

    private function syncFiles($files)
    {
        $groups = [
            self::FILE_DELETED => [],
            self::FILE_UPDATED => [],
        ];

        foreach ($files as $k => $file) {
            $groupName = file_exists($this->local_dir . '/' . $file) ? self::FILE_UPDATED : self::FILE_DELETED;
            $groups[$groupName][$k] = $file;

            if ($groupName === self::FILE_UPDATED) {
                $this->echoLog("  * UPL: $file");
            } elseif ($groupName === self::FILE_DELETED) {
                $this->echoLog("  * DEL: $file");
            }
        }

        if ($cnt = count($groups[self::FILE_UPDATED])) {
            $this->echoLog("Uploading " . $cnt . " file(s)", false);
            if ($cnt < 200) {
                $this->rsyncFiles($groups[self::FILE_UPDATED], $this->remote_path);
            }
            else {
                $this->echoLog("Too many files to upload, doing full rsync ...", false);
                $this->rsyncFiles('./', $this->remote_path);
            }
        }

        if (count($groups[self::FILE_DELETED])) {
            $escapedFilesDeleted = array_map(function ($file) {
                return escapeshellarg($file);
            }, $groups[self::FILE_DELETED]);

            $cnt = count($escapedFilesDeleted);
            $this->echoLog("Deleting " . $cnt . " file(s)", false);

            if ($cnt < 200) {
                $cmd = $this->sshCmd() . ' ' . escapeshellarg($this->remote_address) . ' ' . escapeshellcmd('cd ' . escapeshellarg($this->remote_dir) . ' && rm -rf ' . implode(' ', $escapedFilesDeleted));
                $this->runCmd($cmd);
            }
            else {
                $this->echoLog("Too many files to delete, doing full rsync ...", false);
                $this->rsyncFiles('./', $this->remote_path);
            }
        }
    }

    private function runCmd($cmd)
    {
        //print "# Run cmd:\n" . $cmd."\n";
        $t1 = microtime(true);
        //print "\n".$cmd."\n";
        system('sh -c ' . escapeshellarg($cmd), $exitCode);
        $t2 = microtime(true);

        if (!$exitCode) {
            $this->echoLog(" completed in " . sprintf('%.3f', $t2 - $t1) . " s", true, false);
        }
        else {
            $this->echoLog(" failed after " . sprintf('%.3f', $t2 - $t1) . " s", true, false);
        }
    }
}

new SSHSync($argv, $argc);
