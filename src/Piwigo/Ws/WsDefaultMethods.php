<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Ws\Activity\ActivityMethodRegistrar;
use Piwigo\Ws\Categories\CategoriesMethodRegistrar;
use Piwigo\Ws\Comments\CommentsMethodRegistrar;
use Piwigo\Ws\Core\CoreMethodRegistrar;
use Piwigo\Ws\Event\WsAddMethods;
use Piwigo\Ws\Extensions\ExtensionsMethodRegistrar;
use Piwigo\Ws\Groups\GroupsMethodRegistrar;
use Piwigo\Ws\History\HistoryMethodRegistrar;
use Piwigo\Ws\Images\ImagesMethodRegistrar;
use Piwigo\Ws\Permissions\PermissionsMethodRegistrar;
use Piwigo\Ws\Rates\RatesMethodRegistrar;
use Piwigo\Ws\Session\SessionMethodRegistrar;
use Piwigo\Ws\Tags\TagsMethodRegistrar;
use Piwigo\Ws\Users\UsersMethodRegistrar;

/**
 * Thin coordinator: registers every default WS method by delegating to one
 * registrar per handler-directory domain (P25 Stage 1 -- this used to be a
 * single 1,322-line register() method holding all 94 registrations
 * directly). WsInitializer wires register() itself onto the WsAddMethods
 * event; each domain registrar below is a plain constructor-injected
 * service with no event dependency of its own.
 *
 * Group 19's 9 god-class files (Comments.php/Permissions.php/
 * Extensions.php/Groups.php/Tags.php/Categories.php/Users.php/
 * Core.php/Images.php) are all fully migrated -- every WS method
 * registers via MethodDefinition/handlerClass, resolved from the
 * container at invocation time, not a constructor-injected Pwg*
 * class's own instance method. `pwg.activity.downloadLog` is the
 * sole permanent exception: it stays on the legacy
 * addMethod()/plain-string-callback path forever -- see
 * ActivityMethodRegistrar's own registration for why.
 */
final readonly class WsDefaultMethods
{
    public function __construct(
        private ActivityMethodRegistrar $activityMethodRegistrar,
        private CategoriesMethodRegistrar $categoriesMethodRegistrar,
        private CommentsMethodRegistrar $commentsMethodRegistrar,
        private CoreMethodRegistrar $coreMethodRegistrar,
        private ExtensionsMethodRegistrar $extensionsMethodRegistrar,
        private GroupsMethodRegistrar $groupsMethodRegistrar,
        private HistoryMethodRegistrar $historyMethodRegistrar,
        private ImagesMethodRegistrar $imagesMethodRegistrar,
        private PermissionsMethodRegistrar $permissionsMethodRegistrar,
        private RatesMethodRegistrar $ratesMethodRegistrar,
        private SessionMethodRegistrar $sessionMethodRegistrar,
        private TagsMethodRegistrar $tagsMethodRegistrar,
        private UsersMethodRegistrar $usersMethodRegistrar,
    ) {}

    /**
     * event handler that registers standard methods with the web service
     */
    public function register(WsAddMethods $event): void
    {
        $service = $event->server;

        $this->coreMethodRegistrar->register($service);
        $this->activityMethodRegistrar->register($service);
        $this->categoriesMethodRegistrar->register($service);
        $this->imagesMethodRegistrar->register($service);
        $this->ratesMethodRegistrar->register($service);
        $this->sessionMethodRegistrar->register($service);
        $this->tagsMethodRegistrar->register($service);
        $this->extensionsMethodRegistrar->register($service);
        $this->groupsMethodRegistrar->register($service);
        $this->usersMethodRegistrar->register($service);
        $this->permissionsMethodRegistrar->register($service);
        $this->historyMethodRegistrar->register($service);
        $this->commentsMethodRegistrar->register($service);
    }
}
