<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\Service;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry;

class ProductMediaService
{
    private EntityRepository $productRepository;
    private EntityRepository $productMediaRepository;
    private EntityRepository $mediaRepository;
    private string $noPictureFilename = 'no-picture';

    public function __construct(
        EntityRepository $productRepository,
        EntityRepository $productMediaRepository,
        EntityRepository $mediaRepository
    ) {
        $this->productRepository = $productRepository;
        $this->productMediaRepository = $productMediaRepository;
        $this->mediaRepository = $mediaRepository;
    }

    public function assignCoverImages(OutputInterface $output): void
    {
        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::USE_INDEXING_QUEUE);

        $output->writeln('Starting cover image assignment process...');

        // Get ID of no-picture media
        $noPictureId = $this->getNoPictureMediaId($context);
        if (!$noPictureId) {
            $output->writeln('<error>No "no-picture.jpg" found in media repository.</error>');
            return;
        }

        // Set up product iterator
        $criteria = new Criteria();
        $criteria->addAssociation('cover.media');
        $criteria->addAssociation('media');
        $criteria->setLimit(500);

        $iterator = new RepositoryIterator($this->productRepository, $context, $criteria);
        $progressBar = new ProgressBar($output, $iterator->getTotal());
        $progressBar->start();

        while (($result = $iterator->fetch()) !== null) {
            $products = $result->getEntities();

            foreach ($products as $product) {
                /** @var ProductEntity $product */
                $productId = $product->getId();
                $productNumber = $product->getProductNumber();
                $cover = $product->getCover();
                $mediaCollection = $product->getMedia();

                $validMediaIds = [];
                foreach ($mediaCollection as $productMedia) {
                    $media = $productMedia->getMedia();
                    if ($media && $media->getFileName() !== $this->noPictureFilename) {
                        $validMediaIds[] =  $productMedia->getId();
                    } elseif ($media && $media->getFileName() === $this->noPictureFilename) {
                        // Remove no-picture.jpg from product media
                        $this->productMediaRepository->delete([['id' => $productMedia->getId()]], $context);
                    }
                }

                // Case 1: If product has no cover or cover is no-picture.jpg
                if (!$cover || $cover->getMediaId() === $noPictureId) {
                    // Remove cover
                    $this->productRepository->update([['id' => $productId, 'coverId' => null]], $context);
                    if (count($validMediaIds) === 1) {
                        $this->productRepository->update([
                            ['id' => $productId, 'coverId' => $validMediaIds[0]]
                        ], $context);
                        $output->writeln(sprintf("\n<comment>Product %s has one image, cover assigned automatically.</comment>", $productNumber));
                    } elseif (count($validMediaIds) > 1) {
                        $output->writeln(sprintf("\n<comment>Product %s has multiple images, no cover assigned automatically.</comment>", $productNumber));
                    }
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $output->writeln("\nCover image assignment complete.");
    }

    private function getNoPictureMediaId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileName', $this->noPictureFilename));
        $criteria->setLimit(1);

        $media = $this->mediaRepository->search($criteria, $context)->first();
        return $media ? $media->getId() : null;
    }
}
