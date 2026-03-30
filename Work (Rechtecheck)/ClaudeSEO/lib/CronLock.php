<?php

class CronLock {
    private string $lockPath;

    public function __construct(string $phaseName) {
        $this->lockPath = __DIR__ . '/../logs/' . $phaseName . '.lock';
    }

    public function acquire(): bool {
        if (file_exists($this->lockPath)) {
            $pid = (int) trim(file_get_contents($this->lockPath));
            if ($pid > 0 && $this->isProcessAlive($pid)) {
                return false;
            }
            // Stale lock — remove it
            @unlink($this->lockPath);
        }

        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $written = @file_put_contents($this->lockPath, (string) getmypid());
        return $written !== false;
    }

    public function release(): void {
        @unlink($this->lockPath);
    }

    public function __destruct() {
        $this->release();
    }

    private function isProcessAlive(int $pid): bool {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            @exec("tasklist /FI \"PID eq {$pid}\" /NH", $output);
            $outputStr = implode("\n", $output);
            return str_contains($outputStr, (string) $pid);
        }

        // Unix/Linux/macOS: send signal 0 to check if process exists
        return posix_kill($pid, 0);
    }
}
