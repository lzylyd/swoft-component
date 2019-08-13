<?php declare(strict_types=1);

namespace Swoft\Error;

use Swoft\Error\Contract\DefaultErrorHandlerInterface;
use Swoft\Log\Helper\CLog;
use Throwable;
use function get_class;

/**
 * Class DefaultExceptionHandler
 *
 * @since 2.0
 */
class DefaultExceptionHandler implements DefaultErrorHandlerInterface
{
    /**
     * @param Throwable $e
     *
     * @return void
     */
    public function handle(Throwable $e): void
    {
        CLog::error(
            "(DEFAULT HANDLER)Exception(%s): %s\nAt File %s line %d\nTrace:\n%s\n",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return ErrorType::DEF;
    }
}
