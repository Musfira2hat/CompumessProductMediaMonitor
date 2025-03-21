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
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;

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

        $noPictureId = $this->getNoPictureMediaId($context);

        if (!$noPictureId) {
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
        $noMedia =[];

        while (($result = $iterator->fetch()) !== null) {
            $products = $result->getEntities();

            foreach ($products as $product) {
                /** @var ProductEntity $product */
                $productId = $product->getId();
                $productNumber = $product->getProductNumber();
                $productSeries = $product->getCustomFields()['migration_Compumess57Live_product_series'];
    
                $cover = $product->getCover();
                $mediaCollection = $product->getMedia();

                $validMediaIds = [];
                foreach ($mediaCollection as $productMedia) {
                    $media = $productMedia->getMedia();
                    
                    if ($media && $media->getFileName() !== $this->noPictureFilename) {
                        $validMediaIds[] = $productMedia->getId();
                    } elseif ($media && $media->getFileName() === $this->noPictureFilename) {
                        $this->productMediaRepository->delete([['id' => $productMedia->getId()]], $context);
                    }
                }
         
                if (!$cover || $cover->getMediaId() === $noPictureId) {
                    $this->productRepository->update([['id' => $productId, 'coverId' => null]], $context);

                    if (count($validMediaIds) === 1) {
                        $this->productRepository->update([
                            ['id' => $productId, 'coverId' => $validMediaIds[0]]
                        ], $context);
                    } elseif (count($validMediaIds) > 1) {
                        // Add to report
                        $multipleMedia[] = $productSeries;
                    }elseif (count($validMediaIds) === 0 && $product->getParentId() === null) {
                        // Add to report if no valid media at all
                        $noMedia[] = $productNumber;
                    }
                }
            }
        }

        if (!empty($multipleMedia || $noMedia)) {
            $this->sendReport($multipleMedia, $noMedia, $context);
        }
    }

    private function getNoPictureMediaId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileName', $this->noPictureFilename));
        $criteria->setLimit(1);

        $media = $this->mediaRepository->search($criteria, $context)->first();
        return $media ? $media->getId() : null;
    }

    private function sendReport(array $multipleMedia, array $noMedia, Context $context): void
    {
        $multipleMedia = implode(', ', $multipleMedia);
        $noMedia = implode(', ', $noMedia);
        $this->mailServiceHelper->sendReportEmail($multipleMedia, $noMedia, $context);
    }
}
