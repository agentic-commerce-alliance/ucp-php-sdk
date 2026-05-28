<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

final class ProductCatalog
{
    /**
     * @var array<string, array{
     *     id: string,
     *     title: string,
     *     price: float,
     *     image_url: string,
     *     stock: int,
     *     category: string
     * }>
     */
    private const PRODUCTS = [
        'tent-4p' => [
            'id' => 'tent-4p',
            'title' => 'Summit 4P Tent',
            'price' => 249.0,
            'image_url' => 'https://images.example.test/products/tent-4p.jpg',
            'stock' => 18,
            'category' => 'camping',
        ],
        'stove-lite' => [
            'id' => 'stove-lite',
            'title' => 'Trail Lite Stove',
            'price' => 79.0,
            'image_url' => 'https://images.example.test/products/stove-lite.jpg',
            'stock' => 42,
            'category' => 'cooking',
        ],
        'pack-28' => [
            'id' => 'pack-28',
            'title' => 'Ridge 28L Pack',
            'price' => 129.0,
            'image_url' => 'https://images.example.test/products/pack-28.jpg',
            'stock' => 24,
            'category' => 'hiking',
        ],
        'lamp-pro' => [
            'id' => 'lamp-pro',
            'title' => 'Camp Lantern Pro',
            'price' => 49.0,
            'image_url' => 'https://images.example.test/products/lamp-pro.jpg',
            'stock' => 65,
            'category' => 'accessories',
        ],
    ];

    /**
     * @return list<array{id: string, title: string, price: float, image_url: string, stock: int, category: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $normalizedQuery = mb_strtolower(trim($query));

        $results = array_filter(
            self::PRODUCTS,
            static fn (array $product): bool => $normalizedQuery === ''
                || str_contains(mb_strtolower($product['title']), $normalizedQuery)
                || str_contains(mb_strtolower($product['category']), $normalizedQuery)
                || str_contains(mb_strtolower($product['id']), $normalizedQuery),
        );

        return array_slice(array_values($results), 0, $limit);
    }

    /**
     * @param list<string> $ids
     * @return list<array{id: string, title: string, price: float, image_url: string, stock: int, category: string}>
     */
    public function findMany(array $ids): array
    {
        $products = [];

        foreach ($ids as $id) {
            $product = $this->find($id);
            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @return array{id: string, title: string, price: float, image_url: string, stock: int, category: string}|null
     */
    public function find(string $id): ?array
    {
        return self::PRODUCTS[$id] ?? null;
    }
}
