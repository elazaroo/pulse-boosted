<?php

namespace Elazaroo\PulseBoosted\Recorders;

use Elazaroo\PulseBoosted\Events\SharedBeat;
use Elazaroo\PulseBoosted\Pulse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * @internal
 */
class Servers
{
    use Concerns\Throttling;

    /**
     * Callback to detect CPU usage.
     *
     * @var null|(callable(): int)
     */
    protected static $detectCpuUsing;

    /**
     * Callback to detect memory.
     *
     * @var null|(callable(): array{total: int, used: int})
     */
    protected static $detectMemoryUsing;

    /**
     * The events to listen for.
     *
     * @var class-string
     */
    public string $listen = SharedBeat::class;

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    /**
     * Detect CPU via the given callback.
     *
     * @param  null|(callable(): int)  $callback
     */
    public static function detectCpuUsing(?callable $callback): void
    {
        self::$detectCpuUsing = $callback;
    }

    /**
     * Detect memory via the given callback.
     *
     * @param  null|(callable(): array{total: int, used: int})  $callback
     */
    public static function detectMemoryUsing(?callable $callback): void
    {
        self::$detectMemoryUsing = $callback;
    }

    /**
     * Record the system stats.
     */
    public function record(SharedBeat $event): void
    {
        $this->throttle(15, $event, function ($event) {
            $server = $this->config->get('pulse-boosted.recorders.'.self::class.'.server_name');
            $slug = Str::slug($server);

            ['total' => $memoryTotal, 'used' => $memoryUsed] = $this->memory();
            $cpu = $this->cpu();

            $this->pulse->record('cpu', $slug, $cpu, $event->time)->avg()->onlyBuckets();
            $this->pulse->record('memory', $slug, $memoryUsed, $event->time)->avg()->onlyBuckets();
            $this->pulse->set('system', $slug, json_encode([
                'name' => $server,
                'cpu' => $cpu,
                'memory_used' => $memoryUsed,
                'memory_total' => $memoryTotal,
                'storage' => collect($this->config->get('pulse-boosted.recorders.'.self::class.'.directories')) // @phpstan-ignore argument.templateType, argument.templateType
                    ->filter(fn (string $directory) => ($this->pulse->rescue(fn () => disk_total_space($directory)) ?? false) !== false)
                    ->map(fn (string $directory) => [
                        'directory' => $directory,
                        'total' => $total = intval(round(disk_total_space($directory) / 1024 / 1024)), // MB
                        'used' => intval(round($total - (disk_free_space($directory) / 1024 / 1024))), // MB
                    ])
                    ->all(),
            ], flags: JSON_THROW_ON_ERROR), $event->time);
        });
    }

    /**
     * Whether this PHP may shell out at all.
     *
     * Plenty of shared hosts disable it, and an exception from a monitoring
     * package is a poor trade for a metric.
     */
    protected function canShell(): bool
    {
        static $can = null;

        if ($can !== null) {
            return $can;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return $can = function_exists('shell_exec') && ! in_array('shell_exec', $disabled, true);
    }

    /**
     * Run a command, or return nothing if we cannot.
     */
    protected function run(string $command): string
    {
        if (! $this->canShell()) {
            return '';
        }

        return trim((string) @shell_exec($command));
    }

    /**
     * CPU load on Windows.
     *
     * Windows 11 and Server 2025 ship without wmic, so this prefers CIM
     * through PowerShell and only falls back to wmic where it still exists.
     */
    protected function windowsCpu(): int
    {
        $cim = $this->run('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average" 2>NUL');

        if ($cim !== '') {
            return (int) $cim;
        }

        return (int) $this->run('wmic cpu get loadpercentage 2>NUL | more +1');
    }

    /**
     * Total physical memory on Windows, in megabytes.
     */
    protected function windowsMemoryTotal(): int
    {
        $bytes = $this->run('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory" 2>NUL');

        if ($bytes === '') {
            $bytes = $this->run('wmic ComputerSystem get TotalPhysicalMemory 2>NUL | more +1');
        }

        return intdiv((int) $bytes, 1024 * 1024);
    }

    /**
     * Free physical memory on Windows, in megabytes.
     */
    protected function windowsMemoryFree(): int
    {
        // Both sources report kilobytes.
        $kilobytes = $this->run('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_OperatingSystem).FreePhysicalMemory" 2>NUL');

        if ($kilobytes === '') {
            $kilobytes = $this->run('wmic OS get FreePhysicalMemory 2>NUL | more +1');
        }

        return intdiv((int) $kilobytes, 1024);
    }

    /**
     * A value from /proc/meminfo, in kilobytes.
     *
     * Read rather than shelled out to: it is faster, and it works where
     * shell_exec is disabled, which is most shared hosting.
     */
    protected function procMeminfo(string $key): int
    {
        $contents = @file_get_contents('/proc/meminfo');

        if ($contents === false || ! preg_match('/^'.preg_quote($key, '/').':\s+(\d+)/m', $contents, $matches)) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * CPU usage.
     */
    protected function cpu(): int
    {
        if (self::$detectCpuUsing) {
            return (self::$detectCpuUsing)();
        }

        $cpu = match (PHP_OS_FAMILY) {
            'Darwin' => (int) $this->run("top -l 1 | grep -E \"^CPU\" | tail -1 | awk '{ print $3 + $5 }'"),
            'Linux' => (int) $this->run("top -bn1 | grep -E '^(%Cpu|CPU)' | awk '{ print $2 + $4 }'"),
            'Windows' => $this->windowsCpu(),
            'BSD' => (int) $this->run("top -b -d 2| grep 'CPU: ' | tail -1 | awk '{print$10}' | grep -Eo '[0-9]+\.[0-9]+' | awk '{ print 100 - $1 }'"),
            default => 0,
        };

        // Without a shell there is still load average on Unix, which is close
        // enough to be worth showing rather than a flat zero.
        if ($cpu === 0 && PHP_OS_FAMILY !== 'Windows' && function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();

            if ($load !== false) {
                $cpu = (int) min(100, round($load[0] / max(1, $this->cores()) * 100));
            }
        }

        return max(0, min(100, $cpu));
    }

    /**
     * How many CPUs this machine has, as far as we can tell.
     */
    protected function cores(): int
    {
        $contents = @file_get_contents('/proc/cpuinfo');

        if (is_string($contents)) {
            return max(1, substr_count($contents, 'processor'));
        }

        return 1;
    }

    /**
     * Memory usage.
     *
     * @return array{total: int, used: int}
     */
    protected function memory(): array
    {
        if (self::$detectMemoryUsing) {
            return (self::$detectMemoryUsing)();
        }

        $memoryTotal = match (PHP_OS_FAMILY) {
            'Darwin' => intdiv((int) $this->run("sysctl hw.memsize | grep -Eo '[0-9]+'"), 1024 * 1024),
            'Linux' => intdiv($this->procMeminfo('MemTotal'), 1024),
            'Windows' => $this->windowsMemoryTotal(),
            'BSD' => intdiv((int) $this->run("sysctl hw.physmem | grep -Eo '[0-9]+'"), 1024 * 1024),
            default => 0,
        };

        $memoryUsed = match (PHP_OS_FAMILY) {
            'Darwin' => $memoryTotal - intval(intval($this->run("vm_stat | grep 'Pages free' | grep -Eo '[0-9]+'")) * intval($this->run('pagesize')) / 1024 / 1024), // MB
            'Linux' => $memoryTotal - intdiv($this->procMeminfo('MemAvailable'), 1024), // MB
            'Windows' => $memoryTotal - $this->windowsMemoryFree(), // MB
            'BSD' => intval(intval($this->run("( sysctl vm.stats.vm.v_cache_count | grep -Eo '[0-9]+' ; sysctl vm.stats.vm.v_inactive_count | grep -Eo '[0-9]+' ; sysctl vm.stats.vm.v_active_count | grep -Eo '[0-9]+' ) | awk '{s+=$1} END {print s}'")) * intval($this->run('pagesize')) / 1024 / 1024), // MB
            default => 0,
        };

        return [
            'total' => max(0, $memoryTotal),
            // A total we could not read makes "used" meaningless rather than
            // negative.
            'used' => $memoryTotal > 0 ? max(0, min($memoryTotal, $memoryUsed)) : 0,
        ];
    }
}
