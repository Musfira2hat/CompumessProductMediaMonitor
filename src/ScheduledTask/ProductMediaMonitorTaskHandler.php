<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\ScheduledTask;

use Compumess\ProductMediaMonitor\Service\MailServiceHelper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;

#[AsMessageHandler(handles: ProductMediaMonitorTask::class)]
class ProductMediaMonitorTaskHandler extends ScheduledTaskHandler
{
    private EntityRepository $productRepository;
    private EntityRepository $productMediaRepository;
    private EntityRepository $mediaRepository;
    private LoggerInterface $logger;
    private MailServiceHelper $mailServiceHelper;
    private string $noPictureFilename = 'no-picture';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        EntityRepository $productRepository,
        EntityRepository $productMediaRepository,
        EntityRepository $mediaRepository,
        LoggerInterface $logger,
        MailServiceHelper $mailServiceHelper
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->productRepository = $productRepository;
        $this->productMediaRepository = $productMediaRepository;
        $this->mediaRepository = $mediaRepository;
        $this->logger = $logger;
        $this->mailServiceHelper = $mailServiceHelper;
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();

        $noPictureIds = $this->getNoPictureMediaId($context);

        if (!$noPictureIds) {
            $this->logger->error('No "no-picture.jpg" found in media repository.');
            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('media');
        $criteria->setLimit(500);

        $iterator = new RepositoryIterator($this->productRepository, $context, $criteria);
        $multipleMedia = [];
        $noMedia = [];

        while (($result = $iterator->fetch()) !== null) {
            $products = $result->getEntities();

            foreach ($products as $product) {
                /** @var ProductEntity $product */
                $productId = $product->getId();
                $productNumber = $product->getProductNumber();
                $customFields = $product->getCustomFields();
                $productSeries = $customFields && isset($customFields['migration_Compumess57Live_product_series']) ? $customFields['migration_Compumess57Live_product_series'] : '';
    
                $cover = $product->getCover();
                $mediaCollection = $product->getMedia();

                $validMediaIds = [];
                foreach ($mediaCollection as $productMedia) {
                    $media = $productMedia->getMedia();
                
                    if ($media) {
                        $mediaId = $media->getId();
                
                        if (!isset($noPictureIds[$mediaId])) {
                            // Media is valid
                            $validMediaIds[] = $productMedia->getId();
                        } else {
                            // Media is "no picture", delete it
                            $this->productMediaRepository->delete([['id' => $productMedia->getId()]], $context);
                        }
                    }
                }
        
                if (!$cover || isset($noPictureIds[$cover->getMediaId()])) {
                    $this->productRepository->update([['id' => $productId, 'coverId' => null]], $context);

                    if (count($validMediaIds) === 1) {
                        $this->productRepository->update([
                            ['id' => $productId, 'coverId' => $validMediaIds[0]]
                        ], $context);
                    } elseif (count($validMediaIds) > 1) {
                        // Store product number and series as array
                        $multipleMedia[] = [
                            'productNumber' => $productNumber,
                            'productSeries' => $productSeries
                        ];
                    } elseif (count($validMediaIds) === 0 && $product->getParentId() === null) {
                        // Store product number and series as array
                        $noMedia[] = [
                            'productNumber' => $productNumber,
                            'productSeries' => $productSeries
                        ];
                    }
                }
            }
        }

        if (!empty($multipleMedia) || !empty($noMedia)) {
            $this->sendReport($multipleMedia, $noMedia, $context);
        }
    }

    private function getNoPictureMediaId(Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('path', 'no-picture'));

        $media = $this->mediaRepository->search($criteria, $context)->getIds();

        return $media ?: null;
    }

    private function sendReport(array $multipleMedia, array $noMedia, Context $context): void
    {
        // No need to implode here, pass the complete arrays to the mail service
        $this->mailServiceHelper->sendReportEmail($multipleMedia, $noMedia, $context);
    }
}