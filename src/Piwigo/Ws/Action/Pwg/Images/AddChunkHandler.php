<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.addChunk` — stash a base64-encoded chunk into the upload buffer. */
final readonly class AddChunkHandler implements WsAction
{
    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $logger    = LoggerRegistry::current();
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!Filesystem::mkgetdir($uploadDir, Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        $input        = AddChunkParams::fromArray($params);
        $filename     = sprintf('%s-file-%05u.block', $input->originalSum, $input->position);
        $logger->debug('[addChunk] data length : ' . strlen($input->data));
        $bytesWritten = file_put_contents($uploadDir . '/' . $filename, base64_decode($input->data));
        if ($bytesWritten === false) {
            return new PwgError(500, 'an error has occured while writting chunk ' . $input->position);
        }
        return null;
    }
}
