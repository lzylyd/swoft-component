<?php

namespace Swoft\Http\Server\Annotation\Parser;

use Swoft\Annotation\Annotation\Mapping\AnnotationParser;
use Swoft\Annotation\Annotation\Parser\Parser;
use Swoft\Annotation\AnnotationException;
use Swoft\Http\Server\Annotation\Mapping\RequestMapping;
use Swoft\Http\Server\Router\RoutesCollector;

/**
 * Class RequestMappingParser
 *
 * @since 2.0
 *
 * @AnnotationParser(RequestMapping::class)
 */
class RequestMappingParser extends Parser
{
    /**
     * @param int            $type
     * @param RequestMapping $annotation
     *
     * @return array
     * @throws AnnotationException
     */
    public function parse(int $type, $annotation): array
    {
        if ($type !== self::TYPE_METHOD) {
            throw new AnnotationException('`@RequestMapping` must be defined on class method!');
        }

        // add route info for controller action
        RoutesCollector::addRoute($this->className, [
            'action'  => $this->methodName,
            // info
            'route'   => $annotation->getRoute(),
            'methods' => $annotation->getMethod(),
            'params'  => $annotation->getParams(),
        ]);

        return [];
    }
}
