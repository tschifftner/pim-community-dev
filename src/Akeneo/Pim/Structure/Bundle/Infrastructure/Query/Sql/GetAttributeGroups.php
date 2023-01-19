<?php

declare(strict_types=1);

namespace Akeneo\Pim\Structure\Bundle\Infrastructure\Query\Sql;

use Akeneo\Pim\Automation\DataQualityInsights\PublicApi\Query\AttributeGroup\GetAttributeGroupsActivationQueryInterface;
use Akeneo\Pim\Structure\Bundle\Domain\Query\Sql\GetAttributeGroupsInterface;
use Akeneo\Platform\Bundle\FeatureFlagBundle\FeatureFlags;
use Doctrine\DBAL\Connection;

/**
 * @copyright 2023 Akeneo SAS (https://www.akeneo.com)
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class GetAttributeGroups implements GetAttributeGroupsInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FeatureFlags $featureFlags,
        private readonly GetAttributeGroupsActivationQueryInterface $getAttributeGroupsActivationQuery,
    ) {
    }

    public function all(): array
    {
        $sql = <<<SQL
WITH attribute_group_labels AS (
    SELECT foreign_key as attribute_group_id, JSON_OBJECTAGG(locale, label) as labels
    FROM pim_catalog_attribute_group_translation
    GROUP BY foreign_key
)
SELECT code, sort_order, labels FROM pim_catalog_attribute_group
LEFT JOIN attribute_group_labels ON attribute_group_id = id
ORDER BY sort_order;
SQL;

        $attributeGroups = $this->connection->executeQuery($sql)->fetchAllAssociative();
        $dqiFeatureIsEnabled = $this->featureFlags->isEnabled('data_quality_insights');
        $dqiAttributeGroupsActivation = $this->getAttributeGroupsActivationQuery->all();

        return array_map(function (array $attributeGroup) use ($dqiFeatureIsEnabled, $dqiAttributeGroupsActivation) {
            $attributeGroup['sort_order'] = (int) $attributeGroup['sort_order'];
            $attributeGroup['labels'] = null !== $attributeGroup['labels'] ? json_decode($attributeGroup['labels'], true) : [];
            if ($dqiFeatureIsEnabled) {
                $attributeGroup['is_dqi_activated'] = $dqiAttributeGroupsActivation->isActivated($attributeGroup['code']);
            }
            return $attributeGroup;
        }, $attributeGroups);
    }
}
