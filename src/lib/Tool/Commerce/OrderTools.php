<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\McpCohesivo\Tool\Commerce;

use Ibexa\Contracts\CoreSearch\Values\Query\Criterion\FieldValueCriterion;
use Ibexa\Contracts\Mcp\Attribute\McpTool;
use Ibexa\Contracts\Mcp\McpCapabilityInterface;
use Ibexa\Contracts\Mcp\ToolResult;
use Ibexa\Contracts\OrderManagement\OrderServiceInterface;
use Ibexa\Contracts\OrderManagement\Value\Order\OrderInterface;
use Ibexa\Contracts\OrderManagement\Value\Order\OrderListInterface;
use Ibexa\Contracts\OrderManagement\Value\Order\OrderQuery;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\CompanyNameCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\CreatedAtCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\CurrencyCodeCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\CustomerNameCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\IdentifierCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\LogicalAnd;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\SourceCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\Criterion\StatusCriterion;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\SortClause;
use Ibexa\Contracts\OrderManagement\Value\Order\Query\SortClauseInterface;
use Ibexa\Contracts\OrderManagement\Value\OrderItem\OrderItemInterface;
use Ibexa\ProductCatalog\Money\DecimalMoneyFactory;
use Mcp\Schema\ToolAnnotations;
use Money\Money;

final readonly class OrderTools implements McpCapabilityInterface
{
    private const int MAX_LIMIT = 100;

    private const array MONEY_SCHEMA = [
        'type' => 'string',
        'description' => 'Amount in major units as a decimal string (e.g. "19.99"), in the order currency.',
    ];

    private const array ORDER_SUMMARY_PROPERTIES = [
        'id' => ['type' => 'integer', 'description' => 'Numeric order id.'],
        'identifier' => ['type' => 'string', 'description' => 'Order identifier (UUID).'],
        'status' => ['type' => 'string', 'description' => 'Current workflow status (e.g. pending, processing, completed, cancelled).'],
        'source' => ['type' => 'string', 'description' => 'Source the order was placed from (e.g. local).'],
        'customer' => [
            'type' => 'object',
            'description' => 'Customer who placed the order.',
            'properties' => [
                'id' => ['type' => ['integer', 'null'], 'description' => 'User id, or null for anonymous orders.'],
                'name' => ['type' => 'string', 'description' => 'Customer name.'],
                'email' => ['type' => 'string', 'description' => 'Customer email.'],
            ],
            'required' => ['id', 'name', 'email'],
        ],
        'company' => [
            'type' => ['object', 'null'],
            'description' => 'Company the order is associated with, or null.',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Company id.'],
                'name' => ['type' => 'string', 'description' => 'Company name.'],
            ],
            'required' => ['id', 'name'],
        ],
        'currency' => ['type' => 'string', 'description' => 'ISO currency code.'],
        'value' => [
            'type' => 'object',
            'description' => 'Order totals.',
            'properties' => [
                'totalGross' => self::MONEY_SCHEMA,
                'totalNet' => self::MONEY_SCHEMA,
                'vat' => self::MONEY_SCHEMA,
            ],
            'required' => ['totalGross', 'totalNet', 'vat'],
        ],
        'itemCount' => ['type' => 'integer', 'description' => 'Number of order lines.'],
        'createdAt' => ['type' => 'string', 'description' => 'Creation date (ISO-8601).'],
        'modifiedAt' => ['type' => 'string', 'description' => 'Last modification date (ISO-8601).'],
    ];

    private const array ORDER_SUMMARY_REQUIRED = [
        'id', 'identifier', 'status', 'source', 'customer', 'company', 'currency', 'value', 'itemCount', 'createdAt', 'modifiedAt',
    ];

    private const array ORDER_ITEM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'product' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => ['integer', 'null'], 'description' => 'Product id, or null when no longer available.'],
                    'code' => ['type' => 'string', 'description' => 'Product code (SKU).'],
                    'name' => ['type' => 'string', 'description' => 'Product name at the time of ordering.'],
                ],
                'required' => ['id', 'code', 'name'],
            ],
            'quantity' => ['type' => 'integer', 'description' => 'Ordered quantity.'],
            'unitPriceGross' => self::MONEY_SCHEMA,
            'unitPriceNet' => self::MONEY_SCHEMA,
            'vatRate' => ['type' => 'string', 'description' => 'VAT rate in percent as a numeric string.'],
            'subtotalGross' => self::MONEY_SCHEMA,
            'subtotalNet' => self::MONEY_SCHEMA,
        ],
        'required' => ['product', 'quantity', 'unitPriceGross', 'unitPriceNet', 'vatRate', 'subtotalGross', 'subtotalNet'],
    ];

    private const array ORDER_LIST_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'totalCount' => ['type' => 'integer', 'description' => 'Total number of orders matching the query (ignoring limit/offset).'],
            'limit' => ['type' => 'integer'],
            'offset' => ['type' => 'integer'],
            'orders' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => self::ORDER_SUMMARY_PROPERTIES,
                    'required' => self::ORDER_SUMMARY_REQUIRED,
                ],
            ],
        ],
        'required' => ['totalCount', 'limit', 'offset', 'orders'],
    ];

    private const array ORDER_SCHEMA = [
        'type' => 'object',
        'description' => 'A single order including its order lines.',
        'properties' => self::ORDER_SUMMARY_PROPERTIES + [
            'items' => [
                'type' => 'array',
                'description' => 'Order lines.',
                'items' => self::ORDER_ITEM_SCHEMA,
            ],
        ],
        'required' => [...self::ORDER_SUMMARY_REQUIRED, 'items'],
    ];

    public function __construct(
        private OrderServiceInterface $orderService,
        private DecimalMoneyFactory $moneyFactory,
    ) {
    }

    /**
     * @param int $limit Maximum number of orders to return (1-100). Defaults to 25.
     * @param int $offset Number of orders to skip, for pagination. Defaults to 0.
     */
    #[McpTool(
        servers: ['commerce'],
        name: 'list_orders',
        description: 'Lists orders, newest first, with pagination. Returns order summaries without order lines; use load_order for details.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::ORDER_LIST_SCHEMA,
    )]
    public function listOrders(int $limit = OrderQuery::DEFAULT_LIMIT, int $offset = 0): ToolResult
    {
        try {
            $query = new OrderQuery(
                null,
                [new SortClause\Created(OrderQuery::SORT_DESC)],
                $this->normalizeLimit($limit),
                max(0, $offset),
            );

            return ToolResult::success($this->mapOrderList($this->orderService->findOrders($query), $query));
        } catch (\Throwable $e) {
            return ToolResult::failure($e);
        }
    }

    /**
     * @param string[]|null $statuses Filter by one or more statuses, e.g. ["pending", "processing"].
     * @param string|null $identifier Filter by exact order identifier (UUID).
     * @param string|null $customerName Filter by customer name (substring match).
     * @param string|null $companyName Filter by company name (substring match).
     * @param string|null $currencyCode Filter by ISO currency code, e.g. "EUR".
     * @param string|null $source Filter by order source (substring match).
     * @param string|null $createdFrom Only orders created at or after this date (any format accepted by PHP DateTime, e.g. "2026-01-31" or ISO-8601).
     * @param string|null $createdTo Only orders created at or before this date (any format accepted by PHP DateTime).
     * @param string $sortBy Sort field: 'created' | 'updated' | 'status' | 'id'. Defaults to 'created'.
     * @param string $sortDirection Sort direction: 'asc' | 'desc'. Defaults to 'desc'.
     * @param int $limit Maximum number of orders to return (1-100). Defaults to 25.
     * @param int $offset Number of orders to skip, for pagination. Defaults to 0.
     */
    #[McpTool(
        servers: ['commerce'],
        name: 'find_orders',
        description: 'Searches orders by status, identifier, customer, company, currency, source and creation date range. All filters are optional and combined with AND.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::ORDER_LIST_SCHEMA,
    )]
    public function findOrders(
        ?array $statuses = null,
        ?string $identifier = null,
        ?string $customerName = null,
        ?string $companyName = null,
        ?string $currencyCode = null,
        ?string $source = null,
        ?string $createdFrom = null,
        ?string $createdTo = null,
        string $sortBy = 'created',
        string $sortDirection = 'desc',
        int $limit = OrderQuery::DEFAULT_LIMIT,
        int $offset = 0,
    ): ToolResult {
        try {
            $criteria = [];
            if (!empty($statuses)) {
                $criteria[] = new StatusCriterion(array_values($statuses));
            }
            if ($identifier !== null && $identifier !== '') {
                $criteria[] = new IdentifierCriterion($identifier);
            }
            if ($customerName !== null && $customerName !== '') {
                $criteria[] = new CustomerNameCriterion($customerName);
            }
            if ($companyName !== null && $companyName !== '') {
                $criteria[] = new CompanyNameCriterion($companyName);
            }
            if ($currencyCode !== null && $currencyCode !== '') {
                $criteria[] = new CurrencyCodeCriterion(strtoupper($currencyCode));
            }
            if ($source !== null && $source !== '') {
                $criteria[] = new SourceCriterion($source);
            }
            if ($createdFrom !== null && $createdFrom !== '') {
                $criteria[] = new CreatedAtCriterion(new \DateTimeImmutable($createdFrom), FieldValueCriterion::COMPARISON_GTE);
            }
            if ($createdTo !== null && $createdTo !== '') {
                $criteria[] = new CreatedAtCriterion(new \DateTimeImmutable($createdTo), FieldValueCriterion::COMPARISON_LTE);
            }

            $query = new OrderQuery(
                empty($criteria) ? null : new LogicalAnd(...$criteria),
                [$this->createSortClause($sortBy, $sortDirection)],
                $this->normalizeLimit($limit),
                max(0, $offset),
            );

            return ToolResult::success($this->mapOrderList($this->orderService->findOrders($query), $query));
        } catch (\Throwable $e) {
            return ToolResult::failure($e);
        }
    }

    /**
     * @param int|null $id Numeric order id. Provide either id or identifier.
     * @param string|null $identifier Order identifier (UUID). Provide either id or identifier.
     */
    #[McpTool(
        servers: ['commerce'],
        name: 'load_order',
        description: 'Loads a single order by numeric id or identifier (UUID), including customer, company, totals and order lines.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::ORDER_SCHEMA,
    )]
    public function loadOrder(?int $id = null, ?string $identifier = null): ToolResult
    {
        try {
            if ($id !== null) {
                $order = $this->orderService->getOrder($id);
            } elseif ($identifier !== null && $identifier !== '') {
                $order = $this->orderService->getOrderByIdentifier($identifier);
            } else {
                return ToolResult::failure('Either "id" or "identifier" must be provided.');
            }

            return ToolResult::success($this->mapOrder($order) + [
                'items' => array_map($this->mapOrderItem(...), array_values($order->getItems())),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::failure($e);
        }
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private function createSortClause(string $sortBy, string $sortDirection): SortClauseInterface
    {
        $direction = match (strtolower($sortDirection)) {
            'asc', 'ascending' => OrderQuery::SORT_ASC,
            'desc', 'descending' => OrderQuery::SORT_DESC,
            default => throw new \InvalidArgumentException(sprintf('Unsupported sort direction "%s". Use "asc" or "desc".', $sortDirection)),
        };

        return match (strtolower($sortBy)) {
            'created' => new SortClause\Created($direction),
            'updated' => new SortClause\Updated($direction),
            'status' => new SortClause\Status($direction),
            'id' => new SortClause\Id($direction),
            default => throw new \InvalidArgumentException(sprintf('Unsupported sort field "%s". Use one of: created, updated, status, id.', $sortBy)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderList(OrderListInterface $list, OrderQuery $query): array
    {
        return [
            'totalCount' => $list->getTotalCount(),
            'limit' => $query->getLimit(),
            'offset' => $query->getOffset(),
            'orders' => array_map($this->mapOrder(...), $list->getOrders()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrder(OrderInterface $order): array
    {
        $user = $order->getUser();
        $company = $order->getCompany();
        $value = $order->getValue();

        return [
            'id' => $order->getId(),
            'identifier' => $order->getIdentifier(),
            'status' => $order->getStatus(),
            'source' => $order->getSource(),
            'customer' => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ],
            'company' => $company === null ? null : [
                'id' => $company->getId(),
                'name' => $company->getName(),
            ],
            'currency' => $order->getCurrency()->getCode(),
            'value' => [
                'totalGross' => $this->formatMoney($value->getTotalGross()),
                'totalNet' => $this->formatMoney($value->getTotalNet()),
                'vat' => $this->formatMoney($value->getVat()),
            ],
            'itemCount' => count($order->getItems()),
            'createdAt' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'modifiedAt' => $order->getModifiedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderItem(OrderItemInterface $item): array
    {
        $product = $item->getProduct();
        $value = $item->getValue();

        return [
            'product' => [
                'id' => $product->getId(),
                'code' => $product->getCode(),
                'name' => $product->getName(),
            ],
            'quantity' => $item->getQuantity(),
            'unitPriceGross' => $this->formatMoney($value->getUnitPriceGross()),
            'unitPriceNet' => $this->formatMoney($value->getUnitPriceNet()),
            'vatRate' => $value->getVatRate(),
            'subtotalGross' => $this->formatMoney($value->getSubtotalPriceGross()),
            'subtotalNet' => $this->formatMoney($value->getSubtotalPriceNet()),
        ];
    }

    private function formatMoney(Money $money): string
    {
        return $this->moneyFactory->getMoneyFormatter()->format($money);
    }
}
