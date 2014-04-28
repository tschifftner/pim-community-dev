<?php

namespace Pim\Bundle\CatalogBundle\Doctrine\MongoDBODM;

use Pim\Bundle\CatalogBundle\Doctrine\CompletenessGeneratorInterface;
use Pim\Bundle\CatalogBundle\Entity\Channel;
use Pim\Bundle\CatalogBundle\Entity\Locale;
use Pim\Bundle\CatalogBundle\Entity\Family;
use Pim\Bundle\CatalogBundle\Model\ProductInterface;
use Pim\Bundle\CatalogBundle\Model\AbstractAttribute;
use Pim\Bundle\CatalogBundle\Model\Completeness;
use Pim\Bundle\CatalogBundle\Manager\ChannelManager;
use Pim\Bundle\CatalogBundle\Factory\CompletenessFactory;
use Pim\Bundle\CatalogBundle\Validator\Constraints\ProductValueComplete;
use Pim\Bundle\CatalogBundle\Entity\Repository\FamilyRepository;

use Symfony\Component\Validator\ValidatorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\MongoDB\Query\Builder;
use Doctrine\MongoDB\Query\Expr;

/**
 * Generate the completeness when Product are in MongoDBODM
 * storage. Please note that the generation for several products
 * is done on the MongoDB via a JS generated by the application via HTTP.
 *
 * This generator is only able to generate completeness for one product
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CompletenessGenerator implements CompletenessGeneratorInterface
{
    /**
     * @var DocumentManager;
     */
    protected $documentManager;

    /**
     * @var CompletenessFactory
     */
    protected $completenessFactory;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var string
     */
    protected $productClass;

    /**
     * @var ChannelManager
     */
    protected $channelManager;

    /**
     * @var FamilyRepository
     */
    protected $familyRepository;

    /**
     * Constructor
     *
     * @param DocumentManager     $documentManager
     * @param CompletenessFactory $completenessFactory
     * @param ValidatorInterface  $validator
     * @param string              $productClass
     * @param ChannelManager      $channelManager
     * @param FamilyRepository    $familyRepository
     */
    public function __construct(
        DocumentManager $documentManager,
        CompletenessFactory $completenessFactory,
        ValidatorInterface $validator,
        $productClass,
        ChannelManager $channelManager,
        FamilyRepository $familyRepository
    ) {
        $this->documentManager     = $documentManager;
        $this->completenessFactory = $completenessFactory;
        $this->validator           = $validator;
        $this->productClass        = $productClass;
        $this->channelManager      = $channelManager;
        $this->familyRepository    = $familyRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissingForProduct(ProductInterface $product, $flush = true)
    {
        if (null === $product->getFamily()) {
            return;
        }

        foreach ($product->getCompletenesses() as $completeness) {
            $product->getCompletenesses()->removeElement($completeness);
        }

        $completenesses = $this->buildProductCompletenesses($product);

        foreach ($completenesses as $completeness) {
            $product->getCompletenesses()->add($completeness);
        }

        if ($flush) {
            $this->documentManager->flush($product);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissingForChannel(Channel $channel)
    {
        $this->generate(null, $channel);
    }

    /**
     * Build the completeness for the product
     *
     * @param ProductInterface $product
     *
     * @return array
     */
    public function buildProductCompletenesses(ProductInterface $product)
    {
        $completenesses = array();

        $stats = $this->collectStats($product);

        foreach ($stats as $channelStats) {
            $channel = $channelStats['object'];
            $channelData = $channelStats['data'];
            $channelRequiredCount = $channelData['required_count'];

            foreach ($channelData['locales'] as $localeStats) {
                $completeness = $this->completenessFactory->build(
                    $channel,
                    $localeStats['object'],
                    $localeStats['missing_count'],
                    $channelRequiredCount
                );

                $completenesses[] = $completeness;
            }
        }

        return $completenesses;
    }

    /**
     * Generate statistics on the product completeness
     *
     * @param ProductInterface $product
     *
     * @return array $stats
     */
    protected function collectStats(ProductInterface $product)
    {
        $stats = array();
        $family = $product->getFamily();

        if (null === $family) {
            return $stats;
        }

        $channels = $this->channelManager->getFullChannels();

        foreach ($channels as $channel) {
            $channelCode = $channel->getCode();

            $stats[$channelCode]['object'] = $channel;
            $stats[$channelCode]['data'] = $this->collectChannelStats($channel, $product);
        }

        return $stats;
    }

    /**
     * Generate stats on product completeness for a channel
     *
     * @param Channel          $channel
     * @param ProductInterface $product
     *
     * @return array $stats
     */
    protected function collectChannelStats(Channel $channel, ProductInterface $product)
    {
        $stats = array();
        $locales = $channel->getLocales();
        $completeConstraint = new ProductValueComplete(array('channel' => $channel));
        $stats['required_count'] = 0;
        $stats['locales'] = array();
        $requirements = $product->getFamily()->getAttributeRequirements();

        foreach ($requirements as $req) {
            if (!$req->isRequired() || $req->getChannel() != $channel) {
                continue;
            }
            $stats['required_count']++;

            foreach ($locales as $locale) {
                $localeCode = $locale->getCode();

                if (!isset($stats['locales'][$localeCode])) {
                    $stats['locales'][$localeCode] = array();
                    $stats['locales'][$localeCode]['object'] = $locale;
                    $stats['locales'][$localeCode]['missing_count'] = 0;
                }

                $attribute = $req->getAttribute();
                $value = $product->getValue($attribute->getCode(), $localeCode, $channel->getCode());

                if (!$value || $this->validator->validateValue($value, $completeConstraint)->count() > 0) {
                    $stats['locales'][$localeCode]['missing_count'] ++;
                }
            }
        }

        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissing()
    {
        $this->generate();
    }

    /**
     * Generate missing completenesses for a channel if provided or a product
     * if provided.
     *
     * @param ProductInterface $product
     * @param Channel          $channel
     */
    protected function generate(ProductInterface $product = null, Channel $channel = null)
    {
        $productsQb = $this->documentManager->createQueryBuilder($this->productClass);

        $familyReqs = $this->getFamilyRequirements($product, $channel);

        $this->applyFindMissingQuery($productsQb, $product, $channel);

        $products = $productsQb->select('family', 'normalizedData')
            ->hydrate(false)
            ->getQuery()
            ->execute();

        foreach ($products as $product) {
            $familyId = $product['family'];
            $completenesses = $this->getCompletenesses($product['normalizedData'], $familyReqs[$familyId]);
            $this->saveCompletenesses($product['_id'], $completenesses);
        }
    }

    /**
     * Generate completenesses data from normalized data from product and
     * its family requirements. Only missing completenesses are generated.
     *
     * @param array $normalizedData
     * @parma array $normalizedReqs
     *
     * @return array $completenesses
     */
    protected function getCompletenesses(array $normalizedData, array $normalizedReqs)
    {
        $completenesses = array();
        $missingComps = array_diff(array_keys($normalizedReqs), array_keys($normalizedData['completenesses']));

        $normalizedData = array_filter(
            $normalizedData,
            function ($value) {
                return ('null' != $value);
            }
        );

        $dataFields = array_keys($normalizedData);

        foreach ($missingComps as $missingComp) {
            $reqs = $normalizedReqs[$missingComp]['reqs']['attributes'];
            $requiredCount = count($reqs);

            $missingAttributes = array_diff($reqs, $dataFields);
            $missingCount = count($missingAttributes);
    
            $ratio = round(($requiredCount - $missingCount) / $requiredCount * 100);

            $compObject = array(
                '_id'           => new \MongoId(),
                'missingCount'  => $missingCount,
                'requiredCount' => $requiredCount,
                'ratio'         => $ratio,
                'channel'       => $normalizedReqs[$missingComp]['channel'],
                'locale'        => $normalizedReqs[$missingComp]['locale']
            );

            $completenesses[$missingComp] = array(
                'object' => $compObject,
                'ratio'  => $ratio
            );
        }

        return $completenesses;
    }

    /**
     * Save the completenesses data for the product directly to MongoDB.
     *
     * @param string $productId
     * @param array $completenesses
     */
    protected function saveCompletenesses($productId, array $completenesses)
    {
        $collection = $this->documentManager->getDocumentCollection($this->productClass);

        foreach ($completenesses as $key => $value) {
            $query = array('_id' => $productId);

            $compObject = array('$push' => array('completenesses' => $value['object']));
            $options = array('multiple' => false);

            $collection->update($query, $compObject, $options);

            $normalizedComp = array('$set' => array('normalizedData.completenesses.'.$key => $value['ratio']));
            $collection->update($query, $normalizedComp, $options);
        }
    }

    /**
     * Generate family requirements information to be used to 
     * calculate completenesses.
     *
     * @param ProductInterface $product
     * @param Channel          $channel
     */
    protected function getFamilyRequirements(ProductInterface $product = null, Channel $channel = null)
    {
        $selectFamily = null;

        if (null !== $product) {
            $selectFamily = $product->getFamily();
        }
        $families = $this->familyRepository->getFullFamilies($selectFamily, $channel);
        $familyRequirements = array();

        foreach ($families as $family) {
            $reqsByChannels = array();
            $channels = array();

            foreach ($family->getAttributeRequirements() as $attributeReq) {
                $channel = $attributeReq->getChannel();

                $channels[$channel->getCode()] = $channel;

                if (!isset($reqsByChannels[$channel->getCode()])) {
                    $reqsByChannels[$channel->getCode()] = array();
                }

                $reqsByChannels[$channel->getCode()][] = $attributeReq;
            }

            $familyRequirements[$family->getId()] = $this->getFieldsNames($channels, $reqsByChannels);
        }

        return $familyRequirements;
    }

    /**
     * Generate fields name that should be present and not null for the product
     * to be defined as complete for channels and family
     * Familyreqs must be indexed by channel code
     *
     * @param array $channels
     * @param array $familyReqs
     *
     * @return array
     */
    protected function getFieldsNames(array $channels, array $familyReqs)
    {
        $fields = array();
        foreach ($channels as $channel) {
            foreach ($channel->getLocales() as $locale) {
                $expectedCompleteness = $channel->getCode().'-'.$locale->getCode();
                $fields[$expectedCompleteness] = array();
                $fields[$expectedCompleteness]['channel'] = $channel->getId();
                $fields[$expectedCompleteness]['locale'] = $locale->getId();
                $fields[$expectedCompleteness]['reqs'] = array();
                $fields[$expectedCompleteness]['reqs']['attributes'] = array();
                $fields[$expectedCompleteness]['reqs']['prices'] = array();

                foreach ($familyReqs[$channel->getCode()] as $requirement) {
                    $fieldName = $this->getNormalizedFieldName($requirement->getAttribute(), $channel, $locale);

                    if ('prices' === $requirement->getAttribute()->getBackendType()) {
                        $fields[$expectedCompleteness]['reqs']['prices'][$fieldName] = array();
                        foreach ($channel->getCurrencies() as $currency) {
                            $fields[$expectedCompleteness]['reqs']['prices'][$fieldName][] = $currency->getCode();
                        }
                    } else {
                        $fields[$expectedCompleteness]['reqs']['attributes'][] = $fieldName;
                    }
                }
            }
        }

        return $fields;
    }


    /**
     * Get the name of a normalized data field
     *
     * @param AbstractAttribute $attribute
     * @param Channel           $channel
     * @parma Locale            $locale
     *
     * @return string
     */
    protected function getNormalizedFieldName(AbstractAttribute $attribute, Channel $channel, Locale $locale)
    {
        $suffix = '';

        if ($attribute->isLocalizable()) {
            $suffix = sprintf('-%s', $locale->getCode());
        }
        if ($attribute->isScopable()) {
            $suffix .= sprintf('-%s', $channel->getCode());
        }

        return $attribute->getCode() . $suffix;
    }

    /**
     * Apply the query part to search for product where the completenesses
     * are missing. Apply only to the channel or product if provided.
     *
     * @param Builder $productsQb
     * @param Product $product
     * @param Channel $channel
     */
    protected function applyFindMissingQuery(
        Builder $productsQb,
        ProductInterface $product = null,
        Channel $channel = null
    ) {
        if (null !== $product) {
            $productsQb->field('_id')->equals($product->getId());
        } else {
            $combinations = $this->getChannelLocaleCombinations($channel);

            if (!empty($combinations)) {
                foreach ($combinations as $combination) {
                    $expr = new Expr();
                    $expr->field('normalizedData.completenesses.'.$combination)->exists(false);
                    $productsQb->addOr($expr);
                }
            }
        }

        $productsQb->field('family')->notEqual(null);
    }

    /**
     * Generate a list of potential completeness value from existing channel
     * or from the provided channel
     *
     * @param Channel $channel
     *
     * @return array
     */
    protected function getChannelLocaleCombinations(Channel $channel = null)
    {
        $channels = array();
        $combinations = array();

        if (null !== $channel) {
            $channels = [$channel];
        } else {
            $channels = $this->channelManager->getFullChannels();
        }

        foreach ($channels as $channel) {
            $locales = $channel->getLocales();
            foreach ($locales as $locale) {
                $combinations[] = $channel->getCode().'-'.$locale->getCode();
            }
        }

        return $combinations;
    }

    /**
     * {@inheritdoc}
     */
    public function schedule(ProductInterface $product)
    {
        $product->getCompletenesses()->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleForFamily(Family $family)
    {
        $productQb = $this->documentManager->createQueryBuilder($this->productClass);

        $productQb
            ->update()
            ->multiple(true)
            ->field('family')->equals($family->getId())
            ->field('completenesses')->unsetField()
            ->field('normalizedData.completenesses')->unsetField()
            ->getQuery()
            ->execute();
    }
}
