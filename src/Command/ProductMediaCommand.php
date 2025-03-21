<?php declare(strict_types=1);

namespace Compumess\ProductMediaMonitor\Command;

use Compumess\ProductMediaMonitor\Service\ProductMediaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

#[AsCommand(
    name: 'compumess:assign-cover-images',
    description: 'Assigns cover images to products',
)]
class ProductMediaCommand extends Command
{
    private ProductMediaService $productService;
    
    public function __construct(
        EntityRepository $productRepository,
        EntityRepository $productMediaRepository,
        EntityRepository $mediaRepository
    ) {
        parent::__construct();
        $this->productService = new ProductMediaService(
            $productRepository,
            $productMediaRepository,
            $mediaRepository
        );
    }

    // Provides a description, printed out in bin/console
    protected function configure(): void
    {
        $this->setDescription('Command for assigning cover images to products');
    }

    // Actual code executed in the command
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->productService->assignCoverImages($output);

        return Command::SUCCESS;
    }
}