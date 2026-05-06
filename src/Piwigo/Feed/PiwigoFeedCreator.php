<?php

declare(strict_types=1);

namespace Piwigo\Feed;

class PiwigoFeedCreator extends \UniversalFeedCreator
{
    /** @var string */
    #[\Override]
    public $encoding = 'UTF-8';
}
