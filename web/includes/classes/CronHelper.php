<?php
declare(strict_types=1);

/**
 * Cron Job Helper
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * The shared toolkit for all cron jobs. Handles the boring-but-necessary stuff
 * that every background job needs: locking (so two cron runs don't step on each
 * other), timestamped logging (because log files without timestamps are chaos),
 * and execution tracking (so the admin dashboard knows we're still alive).
 *
 * Think of this as the roadie crew that every cron job needs -- essential but
 * rarely in the spotlight. One class to rule them all, one class to find them,
 * one class to bring them all and in the crontab bind them.
 */
class CronHelper
{
    private string $jobName;
    private ?int $executionId = null;
    private float $startTime;
    /** @var resource|false|null */
    private $lockHandle = null;
    private string $lockFile;

    public function __construct(string $jobName)
    {
        $this->jobName = $jobName;
        $this->startTime = microtime(true);
        $this->lockFile = sys_get_temp_dir() . '/tprm-' . $jobName . '.lock';
    }

    // ---------------------------------------------------------------
    // LOCKING
    // Prevents multiple instances from running simultaneously.
    // Without this, overlapping cron runs could double-process things,
    // and nobody wants duplicate reminder emails or API calls.
    // ---------------------------------------------------------------

    /**
     * Acquire an exclusive lock. Non-blocking -- if someone's already
     * running, we just say "nope" and walk away. Uses flock() which is
     * the POSIX way of saying "dibs on this file."
     */
    public function acquireLock(): bool
    {
        $handle = fopen($this->lockFile, 'c');
        if (!$handle) {
            return false;
        }

        // LOCK_NB = non-blocking. If we can't get it, don't wait.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        // Write our PID so someone can figure out who's holding the lock
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        $this->lockHandle = $handle;
        return true;
    }

    /**
     * Release the lock file. Clean up after ourselves like responsible adults.
     */
    public function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    // ---------------------------------------------------------------
    // EXECUTION TRACKING
    // Records job runs in cron_execution_history so the admin dashboard
    // knows when things last ran and whether they succeeded or face-planted.
    // ---------------------------------------------------------------

    /**
     * Record that we're starting. Creates a "running" entry in the DB.
     * If the table doesn't exist yet, no big deal -- not worth dying over.
     */
    public function logExecutionStart(Database $db): void
    {
        try {
            $this->executionId = (int) $db->insert('cron_execution_history', [
                'job_name'   => $this->jobName,
                'started_at' => date('Y-m-d H:i:s'),
                'status'     => 'running',
            ]);
        } catch (\Exception $e) {
            // Table might not exist yet, that's OK
            $this->executionId = null;
        }
    }

    /**
     * Record a successful completion with final stats.
     * This is how the admin dashboard decides whether to show a green
     * checkmark or a red X next to this job.
     */
    public function logExecutionComplete(
        Database $db,
        int $processed,
        int $success,
        int $failed,
        array $errors
    ): void {
        if (!$this->executionId) {
            return;
        }

        try {
            $executionTime = microtime(true) - $this->startTime;
            $db->update('cron_execution_history', [
                'completed_at'           => date('Y-m-d H:i:s'),
                'status'                 => 'completed',
                'processed_count'        => $processed,
                'success_count'          => $success,
                'failed_count'           => $failed,
                'error_messages'         => !empty($errors) ? json_encode($errors) : null,
                'execution_time_seconds' => round($executionTime, 3),
            ], 'id = :id', [':id' => $this->executionId]);
        } catch (\Exception $e) {
            // If we can't log, we can't log. Not gonna crash the job over bookkeeping.
        }
    }

    /**
     * Record a fatal failure. For when things go truly sideways.
     * Marks the execution record as failed so post-mortem investigations
     * have something to work with.
     */
    public function logExecutionFailed(Database $db, string $error): void
    {
        if (!$this->executionId) {
            return;
        }

        try {
            $executionTime = microtime(true) - $this->startTime;
            $db->update('cron_execution_history', [
                'completed_at'           => date('Y-m-d H:i:s'),
                'status'                 => 'failed',
                'error_messages'         => json_encode([$error]),
                'execution_time_seconds' => round($executionTime, 3),
            ], 'id = :id', [':id' => $this->executionId]);
        } catch (\Exception $e) {
            // Yo dawg, I heard you like errors in your error handler
        }
    }

    // ---------------------------------------------------------------
    // LOGGING
    // Timestamped console output because log files without timestamps
    // are like crime scenes without evidence -- useless.
    // ---------------------------------------------------------------

    /** Log info message -- the "everything is fine" level. */
    public static function info(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] [INFO] $message\n";
    }

    /** Log warning -- the "this is fine dot jpg" level. */
    public static function warn(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] [WARN] $message\n";
    }

    /** Log error -- the "oh no" level. Goes to STDERR where errors belong. */
    public static function error(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        fwrite(STDERR, "[$timestamp] [ERROR] $message\n");
    }
}
