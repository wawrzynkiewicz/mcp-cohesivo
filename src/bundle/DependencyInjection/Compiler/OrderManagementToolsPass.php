<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\McpCohesivo\DependencyInjection\Compiler;

use Ibexa\Contracts\OrderManagement\OrderServiceInterface;
use Ibexa\Mcp\Tool\Commerce\OrderTools;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers the order MCP tools, but only when the ibexa/order-management bundle is available
 * in the container (i.e. OrderServiceInterface is defined).
 */
final readonly class OrderManagementToolsPass implements CompilerPassInterface
{
    private const string CAPABILITY_TAG = 'ibexa.mcp.capability';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(OrderServiceInterface::class)) {
            return;
        }

        $definition = new Definition(OrderTools::class);
        $definition->setAutowired(true);
        $definition->setAutoconfigured(false);
        $definition->addTag(self::CAPABILITY_TAG);

        $container->setDefinition(OrderTools::class, $definition);
    }
}
