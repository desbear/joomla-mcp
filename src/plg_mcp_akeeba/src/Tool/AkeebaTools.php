<?php

/**
 * @package     MCP Server for Joomla
 * @subpackage  plg_mcp_akeeba
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\Mcp\Akeeba\Tool;

use Gss\Component\Mcp\Administrator\Helper\Resolver;
use Gss\Component\Mcp\Administrator\Mcp\Tool;
use Gss\Component\Mcp\Administrator\Mcp\ToolContext;
use Gss\Component\Mcp\Administrator\Mcp\ToolException;
use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Akeeba Backup tools: check backup health, list backups, read logs, and take a backup.
 *
 * A backup runs in steps. start_backup and continue_backup each run as many steps as fit in a
 * short time budget, so every MCP request stays well below proxy and client timeouts; the model
 * calls continue_backup until the backup is complete, just like Akeeba's own backup page does.
 *
 * @since  0.3.0
 */
final class AkeebaTools
{
    /** Backups started through MCP are recorded with this origin (shown as "JSON API" by Akeeba). */
    private const TAG = 'json';

    /** A running backup without progress for this long is considered abandoned. */
    private const STALLED_AFTER = 7200;

    /** Largest backup log this tool will open; debug logs can grow far beyond what PHP can read. */
    private const MAX_LOG_BYTES = 67108864;

    /** Shortest time between two backups started through MCP. */
    private const BACKUP_COOLDOWN_MINUTES = 15;

    private static int $budget = 20;

    /** PHP's execution time is raised once per request, not once per tool call in a batch. */
    private static bool $timeLimitRaised = false;

    /**
     * @return Tool[]
     */
    public static function tools(Registry $params): array
    {
        self::$budget = max(5, min(55, (int) $params->get('step_budget', 20)));

        $ref       = ['type' => ['integer', 'string']];
        $canManage = fn (ToolContext $c) => $c->user->authorise('core.manage', 'com_akeebabackup');
        $canBackup = fn (ToolContext $c) => $c->user->authorise('akeeba.backup', 'com_akeebabackup') && self::engineAvailable();

        return [
            new Tool(
                'backup_status',
                'Check the health of the site backups (Akeeba Backup): time since the last good backup, backups that failed, are stuck, '
                . 'or finished suspiciously small or fast, missing archives, the scheduled backup task, and the backup profiles. '
                . 'Read "warnings" first.',
                ['properties' => [], 'additionalProperties' => false],
                [self::class, 'status'],
                'Backup status',
                Tool::SCOPE_READ,
                'akeeba',
                false,
                $canManage
            ),
            new Tool(
                'list_backups',
                'List backup records with status, duration, size and where the archive is stored (on the server and/or remote storage).',
                [
                    'properties' => [
                        'profile'   => $ref + ['description' => 'Backup profile ID or name.'],
                        'status'    => ['type' => 'string', 'enum' => ['complete', 'fail', 'run']],
                        'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, site time zone.'],
                        'date_to'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, inclusive.'],
                        'limit'     => ['type' => 'integer', 'description' => 'Default 20.'],
                        'offset'    => ['type' => 'integer'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'listBackups'],
                'List backups',
                Tool::SCOPE_READ,
                'akeeba',
                false,
                $canManage
            ),
            new Tool(
                'get_backup_log',
                'Read the end of a backup log to find out why a backup failed or produced warnings.',
                [
                    'properties' => [
                        'backup_id'   => ['type' => 'integer', 'description' => 'Backup record ID (see list_backups).'],
                        'lines'       => ['type' => 'integer', 'description' => 'Number of lines from the end (default 80, max 500).'],
                        'errors_only' => ['type' => 'boolean', 'description' => 'Only warnings and errors.'],
                    ],
                    'required'             => ['backup_id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'log'],
                'Get backup log',
                Tool::SCOPE_READ,
                'akeeba',
                false,
                $canManage
            ),
            new Tool(
                'start_backup',
                \sprintf(
                    'Start a backup with Akeeba Backup. Each call works for about %d seconds and returns the progress; '
                    . 'while the status is "running", call continue_backup with the returned backup_id until it is "complete" or "failed". '
                    . 'Ask the user before starting a backup; it uses server resources.',
                    self::$budget
                ),
                [
                    'properties' => [
                        'profile'     => $ref + ['description' => 'Backup profile ID or name (default 1).'],
                        'description' => ['type' => 'string'],
                        'comment'     => ['type' => 'string'],
                        'force'       => ['type' => 'boolean', 'description' => 'Abandon a backup that is stuck in "running" and start a new one. It cannot start a second concurrent backup.'],
                    ],
                    'additionalProperties' => false,
                ],
                [self::class, 'start'],
                'Start backup',
                Tool::SCOPE_WRITE,
                'akeeba',
                false,
                $canBackup
            ),
            new Tool(
                'continue_backup',
                'Continue a running backup started with start_backup. Call it repeatedly until the status is "complete" or "failed"; '
                . 'if calls stop, the backup stays unfinished.',
                [
                    'properties'           => ['backup_id' => ['type' => 'integer']],
                    'required'             => ['backup_id'],
                    'additionalProperties' => false,
                ],
                [self::class, 'continue'],
                'Continue backup',
                Tool::SCOPE_WRITE,
                'akeeba',
                false,
                $canBackup
            ),
        ];
    }

    public static function engineAvailable(): bool
    {
        return trait_exists('Akeeba\\Component\\AkeebaBackup\\Administrator\\Mixin\\AkeebaEngineTrait');
    }

    // ------------------------------------------------------------------ Read

    public static function status(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $profiles = self::profiles($ctx);
        $now      = time();
        $warnings = [];

        $since = Factory::getDate('-90 days')->toSql();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__akeebabackup_backups'))
            ->where('backupstart >= :since')
            ->order('id DESC')
            ->bind(':since', $since);

        $rows = $db->setQuery($query)->loadObjectList();

        // Reference values per profile: medians of the recent good backups
        $reference = [];

        foreach ($rows as $row) {
            if ($row->status === 'complete' && (int) $row->total_size > 0) {
                $reference[(int) $row->profile_id]['size'][]     = (int) $row->total_size;
                $reference[(int) $row->profile_id]['duration'][] = self::duration($row);
            }
        }

        $lastGood    = null;
        $lastByProfile = [];
        $problems    = [];

        foreach ($rows as $row) {
            $profileId = (int) $row->profile_id;
            $issues    = self::issues($row, $reference[$profileId] ?? [], $now);

            $lastByProfile[$profileId] ??= $row;

            if ($row->status === 'complete' && !$issues && !$lastGood) {
                $lastGood = $row;
            }

            if ($issues && \count($problems) < 10) {
                $problems[] = self::row($ctx, $row, $profiles) + ['issues' => $issues];
            }
        }

        // A good backup is only useful while its archive still exists (old ones are rotated away on purpose)
        $lastUsable = null;

        foreach ($rows as $row) {
            if ($row->status === 'complete' && (int) $row->total_size > 0 && ((int) $row->filesexist || !empty($row->remote_filename))) {
                $lastUsable = $row;
                break;
            }
        }

        if ($lastGood && $lastUsable !== $lastGood) {
            $warnings[] = \sprintf(
                'The archive of the last good backup (#%d) is no longer on the server or remote storage.%s',
                $lastGood->id,
                $lastUsable ? \sprintf(' The newest backup that can still be restored is #%d from %s.', $lastUsable->id, Resolver::localDate($ctx, $lastUsable->backupend)) : ' No backup archive is available to restore.'
            );
        }

        if (!$rows) {
            $warnings[] = 'No backups in the last 90 days.';
        } elseif (!$lastGood) {
            $warnings[] = 'No good backup in the last 90 days.';
        } else {
            $age = ($now - strtotime($lastGood->backupend . ' UTC')) / 3600;

            if ($age > 48) {
                $warnings[] = \sprintf('The last good backup is %d hours old.', $age);
            }
        }

        if ($rows && $rows[0] !== $lastGood && ($latestIssues = self::issues($rows[0], $reference[(int) $rows[0]->profile_id] ?? [], $now))) {
            $warnings[] = \sprintf('The most recent backup (#%d) has problems: %s', $rows[0]->id, implode(' ', $latestIssues));
        }

        $tasks = self::scheduledTasks($ctx, $profiles, $warnings);

        return [
            'warnings'       => $warnings ?: null,
            'last_good_backup' => $lastGood ? self::row($ctx, $lastGood, $profiles) + ['hours_ago' => round(($now - strtotime($lastGood->backupend . ' UTC')) / 3600, 1)] : null,
            'last_restorable_backup' => $lastUsable && $lastUsable !== $lastGood ? self::row($ctx, $lastUsable, $profiles) : null,
            'problems'       => $problems ?: null,
            'profiles'       => array_values(array_map(fn ($p) => [
                'id'          => $p['id'],
                'name'        => $p['name'],
                'last_backup' => isset($lastByProfile[$p['id']]) ? self::row($ctx, $lastByProfile[$p['id']], $profiles) : null,
            ], $profiles)),
            'scheduled_tasks' => $tasks ?: null,
            'time_zone'      => $ctx->app->get('offset', 'UTC'),
        ];
    }

    public static function listBackups(array $args, ToolContext $ctx): array
    {
        $db       = $ctx->db;
        $profiles = self::profiles($ctx);
        $query    = $db->getQuery(true)->from($db->quoteName('#__akeebabackup_backups'));

        if (isset($args['profile'])) {
            $profileId = self::profileId($args['profile'], $profiles);
            $query->where('profile_id = ' . $profileId);
        }

        if (!empty($args['status'])) {
            $query->where('status = :status')->bind(':status', $args['status']);
        }

        if (!empty($args['date_from'])) {
            $from = Resolver::sqlDate($ctx, $args['date_from']);
            $query->where('backupstart >= :from')->bind(':from', $from);
        }

        if (!empty($args['date_to'])) {
            $to = Resolver::sqlDate($ctx, $args['date_to'], true);
            $query->where('backupstart <= :to')->bind(':to', $to);
        }

        $total  = (int) $db->setQuery((clone $query)->select('COUNT(*)'))->loadResult();
        $limit  = $ctx->limit($args['limit'] ?? null, 20);
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $query->select('*')->order('id DESC')->setLimit($limit, $offset);

        $items = array_map(fn ($r) => self::row($ctx, $r, $profiles), $db->setQuery($query)->loadObjectList());

        return ['total' => $total, 'offset' => $offset, 'has_more' => $offset + \count($items) < $total, 'backups' => $items];
    }

    public static function log(array $args, ToolContext $ctx): array
    {
        $record = self::record($ctx, (int) $args['backup_id']);

        // Clamp before reading: a debug log can be hundreds of megabytes, so the file is streamed and
        // only the requested tail is ever held in memory
        $count      = max(1, min(500, (int) ($args['lines'] ?? 80)));
        $errorsOnly = !empty($args['errors_only']);
        $log        = self::logFile($record);

        if ($log === null) {
            throw new ToolException(\sprintf('No log file found for backup #%d. Logs may have been removed, or the backup was taken on another server.', $record->id));
        }

        $handle = $log['handle'];
        $lines  = [];
        $total  = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                // Log files start with a PHP guard line
                if (str_starts_with($line, '<?php')) {
                    continue;
                }

                if ($errorsOnly && !preg_match('/^(WARNING|ERROR)/', $line)) {
                    continue;
                }

                $total++;
                $lines[] = $line;

                // Ring buffer: the oldest line drops out once the requested tail is full
                if (\count($lines) > $count) {
                    array_shift($lines);
                }
            }
        } finally {
            fclose($handle);
        }

        return [
            'backup_id'   => (int) $record->id,
            'log_file'    => $log['name'],
            'total_lines' => $total,
            'lines'       => array_values($lines),
        ];
    }

    // ------------------------------------------------------------------ Backup

    public static function start(array $args, ToolContext $ctx): array
    {
        $profiles  = self::profiles($ctx);
        $profileId = isset($args['profile']) ? self::profileId($args['profile'], $profiles) : 1;

        if (!isset($profiles[$profileId])) {
            throw new ToolException(\sprintf('Backup profile %d does not exist.', $profileId));
        }

        self::assertNoConcurrentBackup($ctx, !empty($args['force']));
        self::assertCooldown($ctx);

        $model = AkeebaEngine::backupModel($ctx);
        $user  = $ctx->user;

        $ctx->app->getSession()->set('akeebabackup.profile', $profileId);

        if (!\defined('AKEEBA_PROFILE')) {
            \define('AKEEBA_PROFILE', $profileId);
        }

        // Load the profile before starting, as Akeeba's own CLI does (see its BackupTake command)
        \Akeeba\Engine\Platform::getInstance()->load_configuration($profileId);

        $model->setState('tag', self::TAG);
        $model->setState('backupid', null);
        $model->setState('description', $args['description'] ?? ($model->getDefaultDescription() . ' (MCP, ' . $user->username . ')'));
        $model->setState('comment', $args['comment'] ?? '');

        return self::run($ctx, $model, true);
    }

    public static function continue(array $args, ToolContext $ctx): array
    {
        $record = self::record($ctx, (int) $args['backup_id']);

        if ($record->status !== 'run') {
            return ['status' => $record->status === 'complete' ? 'complete' : 'failed', 'message' => 'This backup is no longer running.']
                + self::row($ctx, $record, self::profiles($ctx));
        }

        // Only continue backups started through MCP, never one run by cron or in someone's browser.
        // Akeeba records command line runs as "cli" (only relevant when these tools run from the CLI).
        if ($record->tag !== (\PHP_SAPI === 'cli' ? 'cli' : self::TAG)) {
            throw new ToolException(\sprintf(
                'Backup #%d was started from "%s", not through MCP, and can only be continued there.',
                $record->id,
                $record->origin
            ));
        }

        $model = AkeebaEngine::backupModel($ctx);
        // The engine keeps its progress under the tag the backup was started with
        $model->setState('tag', self::TAG);
        $model->setState('backupid', $record->backupid);
        $model->setState('profile', (int) $record->profile_id);

        return self::run($ctx, $model, false);
    }

    /**
     * Run backup steps until the time budget is used up, then report the progress.
     */
    private static function run(ToolContext $ctx, object $model, bool $first): array
    {
        // Finish the current step even if the client disconnects, so the engine state stays consistent
        ignore_user_abort(true);

        // Once per request only: resetting it per call would let a batch of calls push
        // max_execution_time back indefinitely
        if (!self::$timeLimitRaised) {
            self::$timeLimitRaised = true;
            @set_time_limit(self::$budget + 60);
        }

        $start    = microtime(true);
        $longest  = 0.0;
        $warnings = [];

        do {
            $stepStart = microtime(true);
            $status    = $first ? $model->startBackup(self::overrides()) : $model->stepBackup();
            $first     = false;
            $longest   = max($longest, microtime(true) - $stepStart);
            $warnings  = array_merge($warnings, (array) ($status['Warnings'] ?? []));

            \Akeeba\Engine\Factory::getTimer()->resetTime();

            $done = (int) ($status['HasRun'] ?? 0) === 1 || !empty($status['Error']);
        } while (!$done && (microtime(true) - $start) + $longest < self::$budget);

        $record = self::findRecord($ctx, (string) ($model->getState('backupid') ?? ''));

        $result = [
            'backup_id' => $record ? (int) $record->id : null,
            'status'    => !empty($status['Error']) ? 'failed' : ((int) ($status['HasRun'] ?? 0) === 1 ? 'complete' : 'running'),
            'progress'  => isset($status['Progress']) ? round((float) $status['Progress'], 1) : null,
            'stage'     => trim(implode(' / ', array_filter([$status['Domain'] ?? '', $status['Step'] ?? '', $status['Substep'] ?? '']))) ?: null,
            'error'     => $status['Error'] ?: null,
            'warnings'  => $warnings ? array_values(array_unique($warnings)) : null,
            'elapsed_seconds' => round(microtime(true) - $start, 1),
        ];

        if ($result['status'] === 'running') {
            $result['next'] = \sprintf('Call continue_backup with backup_id %d.', $result['backup_id']);
        } elseif ($record) {
            $result['backup'] = self::row($ctx, $record, self::profiles($ctx));
        }

        return $result;
    }

    /**
     * Engine settings for backups taken through MCP: no artificial waiting, and steps short enough to
     * fit the time budget (profiles tuned for CLI may allow minutes per step). Stored with the backup
     * state, so they apply to every continue_backup call; the profile itself is not changed.
     */
    private static function overrides(): array
    {
        $profileMax = (int) \Akeeba\Engine\Factory::getConfiguration()->get('akeeba.tuning.max_exec_time', 14);
        $max        = max(3, min($profileMax ?: 14, self::$budget - 5));

        return [
            'akeeba.tuning.min_exec_time' => 0,
            'akeeba.tuning.max_exec_time' => $max,
            'akeeba.tuning.run_time_bias' => 75,
        ];
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * Problems with a backup record, as readable sentences.
     *
     * @param  array  $reference  ['size' => int[], 'duration' => int[]] of good backups of the same profile
     *
     * @return string[]
     */
    private static function issues(object $row, array $reference, int $now): array
    {
        $issues = [];
        $age    = $now - strtotime($row->backupstart . ' UTC');

        if ($row->status === 'fail') {
            return ['The backup failed; see get_backup_log.'];
        }

        if ($row->status === 'run') {
            return $age > self::STALLED_AFTER
                ? [\sprintf('Still marked as running after %d hours: it was probably interrupted.', $age / 3600)]
                : [];
        }

        $size = (int) $row->total_size;

        if ($size === 0) {
            $issues[] = 'The archive is empty (0 bytes).';
        } elseif (\count($reference['size'] ?? []) >= 3 && $size < 0.5 * self::median($reference['size'])) {
            $issues[] = \sprintf('The archive (%s) is less than half the usual size (%s).', self::bytes($size), self::bytes((int) self::median($reference['size'])));
        }

        $duration = self::duration($row);

        if (\count($reference['duration'] ?? []) >= 3 && ($median = self::median($reference['duration'])) >= 20 && $duration < 0.2 * $median) {
            $issues[] = \sprintf('It took %d seconds instead of the usual %d.', $duration, $median);
        }

        return $issues;
    }

    /**
     * Joomla scheduled tasks that run Akeeba backups.
     */
    private static function scheduledTasks(ToolContext $ctx, array $profiles, array &$warnings): array
    {
        $db = $ctx->db;

        try {
            $query = $db->getQuery(true)
                ->select('id, title, type, state, last_execution, next_execution, last_exit_code, times_executed, times_failed, params')
                ->from($db->quoteName('#__scheduler_tasks'))
                ->where('type LIKE ' . $db->quote('akeebabackup.%'))
                ->where('state IN (0, 1)');

            $tasks = $db->setQuery($query)->loadObjectList();
        } catch (\Throwable) {
            return [];
        }

        $result = [];

        foreach ($tasks as $task) {
            $params    = new Registry($task->params);
            $profileId = (int) $params->get('profileid', 1);
            $executed  = (int) $task->times_executed;
            $failed    = (int) $task->times_failed;

            if ((int) $task->state === 1) {
                if ($task->next_execution && strtotime($task->next_execution . ' UTC') < time() - 3600) {
                    $warnings[] = \sprintf('Scheduled task "%s" is overdue since %s: check that Joomla\'s scheduler runs (web cron or CLI cron).', $task->title, Resolver::localDate($ctx, $task->next_execution));
                }

                if ((int) $task->last_exit_code !== 0) {
                    $warnings[] = \sprintf('The last run of scheduled task "%s" failed (exit code %d).', $task->title, $task->last_exit_code);
                }

                if ($executed >= 10 && $failed / $executed > 0.2) {
                    $warnings[] = \sprintf('Scheduled task "%s" failed %d of %d times.', $task->title, $failed, $executed);
                }
            }

            $result[] = [
                'id'             => (int) $task->id,
                'title'          => $task->title,
                'enabled'        => (int) $task->state === 1,
                'profile'        => $profiles[$profileId]['name'] ?? $profileId,
                'last_run'       => Resolver::localDate($ctx, $task->last_execution),
                'last_exit_code' => (int) $task->last_exit_code,
                'next_run'       => Resolver::localDate($ctx, $task->next_execution),
                'runs'           => $executed,
                'failures'       => $failed,
            ];
        }

        return $result;
    }

    private static function row(ToolContext $ctx, object $row, array $profiles): array
    {
        $size = (int) $row->total_size;

        return [
            'id'                => (int) $row->id,
            'description'       => $row->description,
            'comment'           => $row->comment ?: null,
            'profile'           => ['id' => (int) $row->profile_id, 'name' => $profiles[(int) $row->profile_id]['name'] ?? null],
            'status'            => $row->status,
            'origin'            => $row->origin,
            'type'              => $row->type,
            'started'           => Resolver::localDate($ctx, $row->backupstart),
            'finished'          => $row->status === 'run' ? null : Resolver::localDate($ctx, $row->backupend),
            'duration_seconds'  => $row->status === 'run' ? null : self::duration($row),
            'size'              => $size ? self::bytes($size) : null,
            'size_bytes'        => $size,
            'parts'             => (int) $row->multipart ?: 1,
            'archive_on_server' => (bool) $row->filesexist,
            'remote_copy'       => $row->remote_filename ?: null,
            'frozen'            => (bool) $row->frozen,
        ];
    }

    private static function record(ToolContext $ctx, int $id): object
    {
        $db  = $ctx->db;
        $row = $db->setQuery(
            $db->getQuery(true)->select('*')->from($db->quoteName('#__akeebabackup_backups'))->where('id = :id')->bind(':id', $id, ParameterType::INTEGER)
        )->loadObject();

        if (!$row) {
            throw new ToolException(\sprintf('Backup #%d not found. Use list_backups.', $id));
        }

        return $row;
    }

    private static function findRecord(ToolContext $ctx, string $backupId): ?object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__akeebabackup_backups'))->order('id DESC');

        if ($backupId !== '') {
            $query->where('backupid = :backupid')->bind(':backupid', $backupId);
        } else {
            $query->where('tag = ' . $db->quote(self::TAG));
        }

        return $db->setQuery($query, 0, 1)->loadObject() ?: null;
    }

    /**
     * The newest backup still marked as running, however old it is: start() decides whether it is
     * a live backup or a stalled one.
     */
    private static function runningBackup(ToolContext $ctx): ?object
    {
        $db    = $ctx->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__akeebabackup_backups'))
            ->where('status = ' . $db->quote('run'))
            ->order('id DESC');

        return $db->setQuery($query, 0, 1)->loadObject() ?: null;
    }

    /**
     * Never start a second backup while one is really running: two full-site backups at once double
     * the load and can corrupt each other's archives. "force" only clears a stalled backup, which is
     * one that has not progressed for STALLED_AFTER seconds and is therefore not running at all.
     */
    private static function assertNoConcurrentBackup(ToolContext $ctx, bool $force): void
    {
        $running = self::runningBackup($ctx);

        if (!$running) {
            return;
        }

        $age = time() - strtotime($running->backupstart . ' UTC');

        if ($age <= self::STALLED_AFTER) {
            throw new ToolException(\sprintf(
                'Backup #%d (started %s) is still running. Call continue_backup with backup_id %d; a second backup cannot be started '
                . 'while this one runs, not even with force.',
                $running->id,
                Resolver::localDate($ctx, $running->backupstart),
                $running->id
            ));
        }

        if (!$force) {
            throw new ToolException(\sprintf(
                'Backup #%d (started %s) is still marked as running but has not progressed for %d hours, so it was probably interrupted. '
                . 'Pass force: true to abandon it and start a new backup.',
                $running->id,
                Resolver::localDate($ctx, $running->backupstart),
                (int) ($age / 3600)
            ));
        }
    }

    /**
     * Rate limit: a full-site backup is expensive, and without this a client could ask for one
     * after another. The activity log is the record of what MCP itself started.
     */
    private static function assertCooldown(ToolContext $ctx): void
    {
        $db    = $ctx->db;
        $since = Factory::getDate('-' . self::BACKUP_COOLDOWN_MINUTES . ' minutes')->toSql();
        $query = $db->getQuery(true)
            ->select($db->quoteName('created'))
            ->from($db->quoteName('#__mcp_logs'))
            ->where($db->quoteName('tool') . ' = ' . $db->quote('start_backup'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('success'))
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':since', $since)
            ->order($db->quoteName('created') . ' DESC');

        try {
            $last = $db->setQuery($query, 0, 1)->loadResult();
        } catch (\Throwable) {
            // Without a readable activity log there is nothing to rate limit against
            return;
        }

        if (!$last) {
            return;
        }

        $wait = max(1, (int) ceil((strtotime($last . ' UTC') + self::BACKUP_COOLDOWN_MINUTES * 60 - time()) / 60));

        throw new ToolException(\sprintf(
            'A backup was already started through MCP at %s. Backups are limited to one every %d minutes; the next one is allowed in '
            . '%d minute%s. Use continue_backup if that backup is still running.',
            Resolver::localDate($ctx, $last),
            self::BACKUP_COOLDOWN_MINUTES,
            $wait,
            $wait === 1 ? '' : 's'
        ));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private static function profiles(ToolContext $ctx): array
    {
        $db   = $ctx->db;
        $rows = $db->setQuery($db->getQuery(true)->select('id, description')->from($db->quoteName('#__akeebabackup_profiles'))->order('id'))->loadObjectList();

        $profiles = [];

        foreach ($rows as $row) {
            $profiles[(int) $row->id] = ['id' => (int) $row->id, 'name' => $row->description];
        }

        return $profiles;
    }

    private static function profileId(mixed $ref, array $profiles): int
    {
        if (\is_int($ref) || ctype_digit((string) $ref)) {
            return (int) $ref;
        }

        $matches = array_filter($profiles, fn ($p) => mb_strtolower($p['name']) === mb_strtolower(trim((string) $ref)));

        if (\count($matches) !== 1) {
            throw new ToolException(\sprintf(
                'Backup profile "%s" not found. Profiles: %s.',
                (string) $ref,
                implode(', ', array_map(fn ($p) => $p['id'] . ' (' . $p['name'] . ')', $profiles))
            ));
        }

        return (int) array_key_first($matches);
    }

    /**
     * Open the log file of a backup in the directories Akeeba uses.
     *
     * The name is built from database values, so they are validated and the resolved path is required
     * to stay inside the directory it was built from. Opening the file straight away (instead of
     * checking it and reading it later) leaves no window between the check and the read.
     *
     * @return  array{handle: resource, name: string}|null
     */
    private static function logFile(object $record): ?array
    {
        // Defence in depth: both values end up inside a file name
        foreach (['tag' => $record->tag, 'backupid' => $record->backupid] as $key => $value) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', (string) $value)) {
                throw new ToolException(\sprintf(
                    'Backup #%d has an unexpected "%s" value in the database; refusing to look for its log file.',
                    (int) $record->id,
                    $key
                ));
            }
        }

        $dirs = [JPATH_ADMINISTRATOR . '/components/com_akeebabackup/backup'];

        if (!empty($record->absolute_path)) {
            array_unshift($dirs, \dirname($record->absolute_path));
        }

        try {
            if (self::engineAvailable() && class_exists('Akeeba\\Engine\\Factory')) {
                $configured = \Akeeba\Engine\Factory::getConfiguration()->get('akeeba.basic.output_directory', '');
                $dirs[]     = \Akeeba\Engine\Factory::getFilesystemTools()->translateStockDirs($configured);
            }
        } catch (\Throwable) {
            // Engine not loaded; the default locations are enough in most cases
        }

        foreach (array_unique(array_filter($dirs)) as $dir) {
            $base = realpath($dir);

            if ($base === false) {
                continue;
            }

            foreach (['.log.php', '.log'] as $ext) {
                $real = realpath($base . '/akeeba.' . $record->tag . '.' . $record->backupid . $ext);

                // The file must resolve to something inside the directory it was built from
                if ($real === false || !str_starts_with($real, rtrim($base, '/') . '/')) {
                    continue;
                }

                $size = @filesize($real);

                if ($size !== false && $size > self::MAX_LOG_BYTES) {
                    throw new ToolException(\sprintf(
                        'The log file %s is %s, which is more than the %s this tool reads. Open it from the Akeeba Backup log manager instead.',
                        basename($real),
                        self::bytes((int) $size),
                        self::bytes(self::MAX_LOG_BYTES)
                    ));
                }

                $handle = @fopen($real, 'rb');

                if ($handle !== false) {
                    return ['handle' => $handle, 'name' => basename($real)];
                }
            }
        }

        return null;
    }

    private static function duration(object $row): int
    {
        return max(0, strtotime($row->backupend . ' UTC') - strtotime($row->backupstart . ' UTC'));
    }

    private static function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $mid   = intdiv($count, 2);

        return $count % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i ? 1 : 0) . ' ' . $units[$i];
    }
}
