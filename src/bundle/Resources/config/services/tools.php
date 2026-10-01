<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ibexa\Contracts\OrderManagement\OrderServiceInterface;
use Ibexa\McpCohesivo\Tool\Commerce\OrderTools;

/*
 * Tool classes are registered as services here; being `Ibexa\Contracts\Mcp\McpCapabilityInterface`
 * implementers they are auto-tagged `ibexa.mcp.capability` by ibexa/mcp.
 *
 * None of them declares `servers:` in its `#[McpTool]` attribute — an installation opts in per
 * server by listing the class under `ibexa.repositories.<alias>.mcp.<server>.tools`.
 *
 * Tools that integrate optional packages are only registered when the package is installed;
 * product catalog tools are registered by ProductCatalogToolsPass, which additionally checks that
 * the product services are wired and honours the Quable gate.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    if (interface_exists(OrderServiceInterface::class)) {
        $services->set(OrderTools::class);
    }
};
