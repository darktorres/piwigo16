<?php

declare(strict_types=1);

namespace Piwigo\Command;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI counterpart to `POST /api/v1/images/actions/sync-metadata`
 * ({@see \Piwigo\Controller\Api\Images\ImageSyncMetadataController}) --
 * re-reads EXIF/IPTC metadata from disk for the given photos. Symfony
 * Console's own `int[]` argument type needs no request-param
 * string-validation loop, so this calls `ImageService::getExistingIds()`/
 * `MetadataService::syncMetadata()` directly, same domain calls that
 * controller makes.
 */
#[AsCommand(name: 'maintenance:sync-metadata', description: 'Re-read EXIF/IPTC metadata from disk for the given photos')]
final class MaintenanceSyncMetadataCommand extends Command
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly MetadataService $metadataService,
        private readonly PermissionService $permissionService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('image-ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'One or more photo ids to synchronize');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $rawIds */
        $rawIds = $input->getArgument('image-ids');

        $imageIds = $this->imageService->getExistingIds($rawIds);
        if ($imageIds === []) {
            $output->writeln('No matching photo found.');

            return Command::FAILURE;
        }

        $this->metadataService->syncMetadata($imageIds, $this->permissionService, $this->entityManager);

        $output->writeln('Synchronized metadata for ' . count($imageIds) . ' photo(s).');

        return Command::SUCCESS;
    }
}
