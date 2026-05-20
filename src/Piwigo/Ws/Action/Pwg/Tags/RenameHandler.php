<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.tags.rename` — change a tag's display name (recomputes url_name). */
final readonly class RenameHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private EventDispatcherInterface $dispatcher,
        private TagRepository $tagRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        try {
            $input = RenameParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->tagRepository->countById($input->tagId) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'This tag does not exist.');
        }
        $existingNames = $this->tagRepository->findNamesExcluding($input->tagId);
        $update        = [];
        if (in_array($input->newName, $existingNames)) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already token');
        } elseif (!empty($input->newName)) {
            $urlEvent = new RenderTagUrl($input->newName);
            $this->dispatcher->dispatch($urlEvent);
            $update = ['name' => $input->newName, 'url_name' => $urlEvent->tagName];
        }
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $input->tagId, ActivityAction::Edit));
        $this->tagRepository->updateById($input->tagId, $update);
        $entity          = $this->tagRepository->findById($input->tagId);
        $tag             = $entity !== null ? $entity->toRow() : [];
        $rawTagNameStr   = $entity !== null ? $entity->name : '';
        $tag['raw_name'] = $rawTagNameStr;
        $tagRenderEvent  = new RenderTagName($rawTagNameStr, $tag);
        $this->dispatcher->dispatch($tagRenderEvent);
        $tag['name']      = $tagRenderEvent->tagName;
        $altEvent         = new GetTagAltNames([], $rawTagNameStr);
        $this->dispatcher->dispatch($altEvent);
        $tag['alt_names'] = $altEvent->value;
        return $tag;
    }
}
