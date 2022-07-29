<?php

declare(strict_types=1);

namespace AkeneoTest\Pim\Enrichment\EndToEnd\Product\Product\Command;

use Akeneo\Pim\Enrichment\Bundle\Command\CleanRemovedProductsCommand;
use Akeneo\Pim\Enrichment\Bundle\Elasticsearch\Model\ElasticsearchProductProjection;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Product\API\Command\UpsertProductCommand;
use Akeneo\Test\Integration\Configuration;
use Akeneo\Test\Integration\TestCase;
use Akeneo\Test\IntegrationTestsBundle\Launcher\CommandLauncher;
use Akeneo\Test\IntegrationTestsBundle\Messenger\AssertEventCountTrait;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Client;
use Akeneo\Tool\Bundle\ElasticsearchBundle\Refresh;
use AkeneoTest\Pim\Enrichment\EndToEnd\Product\Product\ExternalApi\AbstractProductTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Command\Command;

class CleanRemovedProductsCommandEndToEnd extends TestCase
{
    protected Command $command;
    protected Client $esProductClient;

    /**
     * {@inheritdoc}
     */
    protected function getConfiguration(): Configuration
    {
        return $this->catalog->useTechnicalCatalog();
    }

    public function setUp(): void
    {
        parent::setUp();


        $this->esProductClient = $this->get('akeneo_elasticsearch.client.product_and_product_model');
    }

    public function test_it_removes_product_in_elasticesearch_index_when_product_not_existing_in_mysql(): void
    {
        $this->givenProductExistingInMySQLAndElasticsearch('product_A');
        $this->givenProductOnlyExistingInElasticsearch('product_B');
        $this->whenIExecuteTheCommandToCleanTheProducts();
        $this->thenTheIndexedProductsInElasticsearchAre(['product_A']);

    }

    private function getUserId(string $username): int
    {
        $query = <<<SQL
            SELECT id FROM oro_user WHERE username = :username
        SQL;
        $stmt = $this->get('database_connection')->executeQuery($query, ['username' => $username]);
        $id = $stmt->fetchOne();
        Assert::assertNotNull($id);

        return \intval($id);
    }

    private function givenProductExistingInMySQLAndElasticsearch(string $identifier): void
    {
        $this->get('akeneo_integration_tests.helper.authenticator')->logIn('admin');
        $command = UpsertProductCommand::createFromCollection(
            userId: $this->getUserId('admin'),
            productIdentifier: $identifier,
            userIntents: []
        );
        $this->get('pim_enrich.product.message_bus')->dispatch($command);
    }

    private function givenProductOnlyExistingInElasticsearch(string $identifier): void
    {
        $product = [
            'identifier' => $identifier,
            'document_type' => ProductInterface::class,
            'values'     => [
                'name-text' => [
                    '<all_channels>' => [
                        '<all_locales>' => '2015/01/01',
                    ],
                ],
            ]
        ];
        $this->esProductClient->index($product['identifier'], $product);
        $this->esProductClient->refreshIndex();

    }

    private function whenIExecuteTheCommandToCleanTheProducts(): void
    {

    }

    private function thenTheIndexedProductsInElasticsearchAre(array $identifiers): void
    {
        $params = [
            '_source' => ['identifier'],
            'query' => [
                'constant_score' => [
                    'filter' => [
                        'bool' => [
                            'filter' => [
                                'term' => [
                                    'document_type' => ProductInterface::class
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
        $results = $this->esProductClient->search($params);

        $esIdentifiers = array_map(function ($doc) {
            return $doc['_source']['identifier'];
        }, $results['hits']['hits']);

        Assert::assertEmpty(array_diff($esIdentifiers, $identifiers));
    }
}
