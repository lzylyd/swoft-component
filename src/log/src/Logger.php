<?php declare(strict_types=1);


namespace Swoft\Log;

use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Co;

/**
 * Class Logger
 *
 * @since 2.0
 *
 * @Bean("logger")
 */
class Logger extends \Monolog\Logger
{
    /**
     * Add trace level
     */
    public const TRACE = 650;

    /**
     * Application name
     *
     * @var string
     */
    protected $name = 'swoft';

    /**
     * Flush interval
     *
     * @var int
     */
    protected $flushInterval = 1;

    /**
     * Whether to flush log by request
     *
     * @var bool
     */
    protected $flushRequest = false;

    /**
     * Profiles stack
     *
     * @var array
     */
    protected $profiles = [];

    /**
     * Countings stack
     *
     * @var array
     */
    protected $countings = [];

    /**
     * Push logs stack
     *
     * @var array
     */
    protected $pushlogs = [];

    /**
     * Profile stacks
     *
     * @var array
     */
    protected $profileStacks = [];

    /**
     * Log messages
     *
     * @var array
     */
    protected $messages = [];

    /**
     * Log processors
     *
     * @var array
     */
    protected $processors = [];

    /**
     * @var bool
     */
    protected $enable = false;

    /**
     * All levels
     *
     * @var array
     */
    protected static $levels = [
        self::DEBUG     => 'debug',
        self::INFO      => 'info',
        self::NOTICE    => 'notice',
        self::WARNING   => 'warning',
        self::ERROR     => 'error',
        self::CRITICAL  => 'critical',
        self::ALERT     => 'alert',
        self::EMERGENCY => 'emergency',
        self::TRACE     => 'trace'
    ];

    /**
     * Logger constructor.
     */
    public function __construct()
    {
        parent::__construct($this->name);
    }

    /**
     * Add record
     *
     * @param int   $level
     * @param mixed $message
     * @param array $context
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function addRecord($level, $message, array $context = []): bool
    {
        if (!$this->enable) {
            return true;
        }

        $levelName = static::getLevelName($level);

        if (!static::$timezone) {
            static::$timezone = new \DateTimeZone(\date_default_timezone_get() ?: 'UTC');
        }

        // php7.1+ always has microseconds enabled, so we do not need this hack
        if ($this->microsecondTimestamps && PHP_VERSION_ID < 70100) {
            $ts = \DateTime::createFromFormat('U.u', \sprintf('%.6F', \microtime(true)), static::$timezone);
        } else {
            $ts = new \DateTime(null, static::$timezone);
        }

        $ts->setTimezone(static::$timezone);

        $message = $this->formatMessage($message);
        $message = $this->getTrace($message);
        $record  = $this->formatRecord($message, $context, $level, $levelName, $ts, []);

        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }

        $this->messages[] = $record;

        if (\count($this->messages) >= $this->flushInterval) {
            $this->flushLog();
        }

        return true;
    }

    /**
     * Format record
     *
     * @param string    $message
     * @param array     $context
     * @param int       $level
     * @param string    $levelName
     * @param \DateTime $ts
     * @param array     $extra
     *
     * @return array
     */
    public function formatRecord(
        string $message,
        array $context,
        int $level,
        string $levelName,
        \DateTime $ts,
        array $extra
    ): array {
        $record = [
            'logid'      => context()->get('traceid'),
            'spanid'     => context()->get('spanid'),
            'messages'   => $message,
            'context'    => $context,
            'level'      => $level,
            'level_name' => $levelName,
            'channel'    => $this->name,
            'datetime'   => $ts,
            'extra'      => $extra,
        ];

        return $record;
    }

    /**
     * Push log
     *
     * @param string $key
     * @param mixed  $val
     */
    public function pushLog(string $key, $val): void
    {
        if (!$this->enable || !$key) {
            return;
        }

        $key = \urlencode($key);
        $cid = Co::tid();
        if (\is_array($val)) {
            $this->pushlogs[$cid][] = "$key=" . \json_encode($val);
        } elseif (\is_bool($val)) {
            $this->pushlogs[$cid][] = "$key=" . \var_export($val, true);
        } elseif (\is_string($val) || is_numeric($val)) {
            $this->pushlogs[$cid][] = "$key=" . \urlencode($val);
        } elseif (null === $val) {
            $this->pushlogs[$cid][] = "$key=";
        }
    }

    /**
     * Profile start
     *
     * @param string $name
     */
    public function profileStart(string $name): void
    {
        if (!$this->enable || !$name) {
            return;
        }

        $cid = Co::tid();

        $this->profileStacks[$cid][$name]['start'] = \microtime(true);
    }

    /**
     * Profile end
     *
     * @param string $name
     */
    public function profileEnd(string $name): void
    {
        if (!$this->enable || !$name) {
            return;
        }

        $cid = Co::tid();
        if (!isset($this->profiles[$cid][$name])) {
            $this->profiles[$cid][$name] = [
                'cost'  => 0,
                'total' => 0,
            ];
        }

        $this->profiles[$cid][$name]['cost']  += \microtime(true) - $this->profileStacks[$cid][$name]['start'];
        $this->profiles[$cid][$name]['total'] += 1;
    }

    /**
     * Format profile
     *
     * @return string
     */
    public function getProfilesInfos(): string
    {
        $profileAry = [];
        $cid        = Co::tid();
        $profiles   = $this->profiles[$cid] ?? [];
        foreach ($profiles as $key => $profile) {
            if (!isset($profile['cost'], $profile['total'])) {
                continue;
            }
            $cost         = \sprintf('%.2f', $profile['cost'] * 1000);
            $profileAry[] = "$key=" . $cost . '(ms)/' . $profile['total'];
        }

        return \implode(',', $profileAry);
    }

    /**
     * Counting
     *
     * @param string   $name
     * @param int      $hit
     * @param int|null $total
     */
    public function counting(string $name, int $hit, int $total = null): void
    {
        if (!\is_string($name) || empty($name)) {
            return;
        }

        $cid = Co::tid();
        if (!isset($this->countings[$cid][$name])) {
            $this->countings[$cid][$name] = ['hit' => 0, 'total' => 0];
        }

        $this->countings[$cid][$name]['hit'] += $hit;
        if ($total !== null) {
            $this->countings[$cid][$name]['total'] += (int)$total;
        }
    }

    /**
     * Format array
     *
     * @return string
     */
    public function getCountingInfo(): string
    {
        $cid = Co::tid();
        if (empty($this->countings[$cid])) {
            return '';
        }

        $countAry  = [];
        $countings = $this->countings[$cid];

        foreach ($countings as $name => $counter) {
            if (isset($counter['hit'], $counter['total']) && $counter['total'] !== 0) {
                $countAry[] = "$name=" . $counter['hit'] . '/' . $counter['total'];
            } elseif (isset($counter['hit'])) {
                $countAry[] = "$name=" . $counter['hit'];
            }
        }

        return \implode(',', $countAry);
    }

    /**
     * Format message
     *
     * @param mixed $message
     *
     * @return string
     */
    public function formatMessage($message): string
    {
        return \is_array($message) ? \json_encode($message) : $message;
    }

    /**
     * 计算调用trace
     *
     * @param $message
     *
     * @return string
     */

    /**
     * Get trace stack
     *
     * @param string $message
     *
     * @return string
     */
    public function getTrace(string $message): string
    {
        $traces = \debug_backtrace();
        $count  = \count($traces);
        $ex     = '';
        if ($count >= 7) {
            $info = $traces[6];
            if (isset($info['file'], $info['line'])) {
                $filename = \basename($info['file']);
                $lineNum  = $info['line'];
                $ex       = "$filename:$lineNum";
            }
        }
        if ($count >= 8) {
            $info = $traces[7];
            if (isset($info['class'], $info['type'], $info['function'])) {
                $ex .= ',' . $info['class'] . $info['type'] . $info['function'];
            } elseif (isset($info['function'])) {
                $ex .= ',' . $info['function'];
            }
        }

        if (!empty($ex)) {
            $message = "trace[$ex] " . $message;
        }

        return $message;
    }

    /**
     * Flush log to handler
     */
    public function flushLog(): void
    {
        if (empty($this->messages)) {
            return;
        }

        reset($this->handlers);

        while ($handler = current($this->handlers)) {
            $handler->handleBatch($this->messages);
            next($this->handlers);
        }

        // Clear message
        $this->messages = [];
    }

    /**
     * Append notice log
     *
     * @param bool $flush
     *
     * @throws \Exception
     */
    public function appendNoticeLog($flush = false): void
    {
        if (!$this->enable) {
            return;
        }
        $cid = Co::tid();
        $ts  = $this->getLoggerTime();

        // PHP time used
        $timeUsed = \sprintf('%.2f', (\microtime(true) - $this->getRequestTime()) * 1000);

        // PHP memory used
        $memUsed = \sprintf('%.0f', \memory_get_peak_usage() / (1024 * 1024));

        $profileInfo  = $this->getProfilesInfos();
        $countingInfo = $this->getCountingInfo();
        $pushLogs     = $this->pushlogs[$cid] ?? [];

        $messageAry = [
            "[$timeUsed(ms)]",
            "[$memUsed(MB)]",
            "[{$this->getUri()}]",
            '[' . \implode(' ', $pushLogs) . ']',
            'profile[' . $profileInfo . ']',
            'counting[' . $countingInfo . ']'
        ];

        $message = \implode(' ', $messageAry);

        // Unset profile/counting/pushlogs/profileStack
        unset($this->profiles[$cid], $this->countings[$cid], $this->pushlogs[$cid], $this->profileStacks[$cid]);

        $levelName = self::$levels[self::NOTICE];
        $message   = $this->formatRecord($message, [], self::NOTICE, $levelName, $ts, []);

        $this->messages[] = $message;

        // Flush by request by max count or request end
        $isReached = \count($this->messages) >= $this->flushInterval;
        if ($this->flushRequest || $isReached || $flush) {
            $this->flushLog();
        }
    }

    /**
     * Get logger time
     *
     * @return bool|\DateTime
     * @throws \Exception
     */
    private function getLoggerTime(): \DateTime
    {
        if (!static::$timezone) {
            static::$timezone = new \DateTimeZone(\date_default_timezone_get() ?: 'UTC');
        }

        $ts = new \DateTime('now', static::$timezone);
        $ts->setTimezone(static::$timezone);

        return $ts;
    }

    /**
     * Init
     */
    public function initialize(): void
    {
        $this->profiles      = [];
        $this->countings     = [];
        $this->pushlogs      = [];
        $this->profileStacks = [];

        $this->messages = [];
    }

    /**
     * 添加一条trace日志
     *
     * @param string $message 日志信息
     * @param array  $context 附加信息
     *
     * @return bool
     * @throws \Exception
     */

    /**
     * Add trace
     *
     * @param mixed $message
     * @param array $context
     *
     * @return bool
     * @throws \Exception
     */
    public function addTrace($message, array $context = []): bool
    {
        return $this->addRecord(static::TRACE, $message, $context);
    }

    /**
     * @param int $flushInterval
     */
    public function setFlushInterval(int $flushInterval): void
    {
        $this->flushInterval = $flushInterval;
    }

    /**
     * @return bool
     */
    public function isEnable(): bool
    {
        return $this->enable;
    }

    /**
     * Request uri
     *
     * @return string
     */
    private function getUri(): string
    {
        return \context()->get('uri', '');
    }

    /**
     * Request time
     *
     * @return float
     */
    private function getRequestTime(): float
    {
        return \context()->get('requestTime', 0);
    }
}
