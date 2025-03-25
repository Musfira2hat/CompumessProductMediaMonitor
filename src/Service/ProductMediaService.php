<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\Service;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
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

    /**
     * Assign cover images to products that have no media (no images)
     */
    public function assignCoverImagesToNoMediaProducts(OutputInterface $output): void
    {
        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::USE_INDEXING_QUEUE);

        $output->writeln('Starting cover image assignment for products with no media...');

        // Get no-picture media IDs
        $noPictureIds = $this->getNoPictureMediaIds($context);
        if (!$noPictureIds) {
            $output->writeln('<error>No "no-picture" images found in media repository.</error>');
            return;
        }

        // Set up criteria for products with no media
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('parentId', null)); // Only main products
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
                $mediaCollection = $product->getMedia();
                
                // Check if product has no valid media
                $hasValidMedia = false;
                if ($mediaCollection && $mediaCollection->count() > 0) {
                    foreach ($mediaCollection as $productMedia) {
                        $media = $productMedia->getMedia();
                        if ($media && !isset($noPictureIds[$media->getId()])) {
                            $hasValidMedia = true;
                            break;
                        }
                    }
                }
                
                if (!$hasValidMedia) {
                    $output->writeln(sprintf(
                        "\n<comment>Product %s has no valid images.</comment>", 
                        $productNumber
                    ));
                }
                
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $output->writeln("\nCover image assignment for no-media products complete.");
    }

    /**
     * Assign cover images to products that have multiple media (multiple images)
     */
    public function assignCoverImagesToMultiMediaProducts(OutputInterface $output): void
    {
        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::USE_INDEXING_QUEUE);

        $output->writeln('Starting cover image assignment for products with multiple media...');

        // Get no-picture media IDs
        $noPictureIds = $this->getNoPictureMediaIds($context);
        if (!$noPictureIds) {
            $output->writeln('<error>No "no-picture" images found in media repository.</error>');
            return;
        }

        // Set up criteria for products
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
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
                if ($mediaCollection) {
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
                }

                // If no cover or cover is a no-picture image
                if (!$cover || isset($noPictureIds[$cover->getMediaId()])) {
                    // Remove cover
                    $this->productRepository->update([['id' => $productId, 'coverId' => null]], $context);
                    
                    if (count($validMediaIds) === 1) {
                        // Only one valid media, assign it as cover
                        $this->productRepository->update([
                            ['id' => $productId, 'coverId' => $validMediaIds[0]]
                        ], $context);
                        $output->writeln(sprintf(
                            "\n<comment>Product %s has one valid image, assigned as cover.</comment>", 
                            $productNumber
                        ));
                    } elseif (count($validMediaIds) > 1) {
                        $output->writeln(sprintf(
                            "\n<comment>Product %s has multiple valid images (%d), choose one manually.</comment>", 
                            $productNumber,
                            count($validMediaIds)
                        ));
                    }
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $output->writeln("\nCover image assignment for multi-media products complete.");
    }

    /**
     * Original method maintained for backward compatibility
     */
    public function assignCoverImages(OutputInterface $output): void
    {
        $context = Context::createDefaultContext();
        $context->addState(EntityIndexerRegistry::USE_INDEXING_QUEUE);

        $output->writeln('Starting cover image assignment process...');

        // Get no-picture media IDs
        $noPictureIds = $this->getNoPictureMediaIds($context);
        if (!$noPictureIds) {
            $output->writeln('<error>No "no-picture" images found in media repository.</error>');
            return;
        }

        // Set up product iterator
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
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

                // Case: If product has no cover or cover is no-picture.jpg
                if (!$cover || isset($noPictureIds[$cover->getMediaId()])) {
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

    private function getNoPictureMediaIds(Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('path', 'no-picture'));

        $media = $this->mediaRepository->search($criteria, $context)->getIds();
        return $media ?: null;
    }
}