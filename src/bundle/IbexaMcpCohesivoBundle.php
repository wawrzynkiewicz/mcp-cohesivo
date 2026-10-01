<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\McpCohesivo;

use Ibexa\Bundle\McpCohesivo\DependencyInjection\Compiler\OrderManagementToolsPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class IbexaMcpCohesivoBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Priority 10 makes this pass run before ibexa/mcp's own McpServerPass (priority 0),
        // which collects the `ibexa.mcp.capability`-tagged services into the per-server locator.
        $container->addCompilerPass(new OrderManagementToolsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }
}
