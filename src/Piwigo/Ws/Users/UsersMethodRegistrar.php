<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Config\CurrentConfig;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\ParamDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsParamFlag;
use Piwigo\Ws\WsParamType;

/**
 * Registers every `pwg.users*`-ish WS method with the
 * server -- split out of the old WsDefaultMethods::register() god-method
 * (P25 Stage 1), one registrar per handler-directory domain. Called from
 * WsDefaultMethods::register(), the event handler WsInitializer actually
 * wires onto WsAddMethods -- this class has no event dependency of its
 * own, just a plain Server parameter.
 */
final readonly class UsersMethodRegistrar
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    public function register(Server $service): void
    {
        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.getList',
            handlerClass: GetListHandler::class,
            description: 'Retrieves a list of all the users.<br>
    <br>
    <b>display</b> controls which data are returned, possible values are:<br>
    all, basics, none,<br>
    username, email, status, level, groups,<br>
    language, theme, nb_image_page, recent_period, expand, show_nb_comments, show_nb_hits,<br>
    enabled_high, registration_date, registration_date_string, registration_date_since, last_visit, last_visit_string, last_visit_since<br>
    <b>basics</b> stands for "username,email,status,level,groups"<br>
    <b>min_register</b> and <b>max_register</b> filter users by their registration date expecting format "YYYY" or "YYYY-mm" or "YYYY-mm-dd".',
            params: [
                ParamDefinition::optionalFlag('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('username', info: 'Use "%" as wildcard.'),
                ParamDefinition::optionalFlag('status', flags: WsParamFlag::FORCE_ARRAY, info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optional('min_level', 0, WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optionalFlag('group_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxUsersPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', 'id', info: 'id, username, level, email'),
                ParamDefinition::optionalFlag('exclude', WsParamType::ID, WsParamFlag::FORCE_ARRAY, info: 'Expects a user_id as value.'),
                ParamDefinition::optional('display', 'basics', info: 'Comma saparated list (see method description)'),
                ParamDefinition::optionalFlag('filter', info: 'Filter by username, email, group'),
                ParamDefinition::optionalFlag('min_register', info: 'See method description'),
                ParamDefinition::optionalFlag('max_register', info: 'See method description'),
            ],
            requiresAuth: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.add',
            handlerClass: AddHandler::class,
            description: 'Registers a new user.',
            params: [
                ParamDefinition::required('username'),
                ParamDefinition::optional('auto_password', false, WsParamType::BOOL, info: 'if true ignores password and confirm password'),
                ParamDefinition::optional('password'),
                ParamDefinition::optionalFlag('password_confirm'),
                ParamDefinition::optional('email'),
                ParamDefinition::optional('send_password_by_mail', false, WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.delete',
            handlerClass: DeleteHandler::class,
            description: 'Deletes on or more users. Photos owned by this user are not deleted.',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.getAuthKey',
            handlerClass: GetAuthKeyHandler::class,
            description: 'Get a new authentication key for a user. Only works for normal/generic users (not admins)',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.setInfo',
            handlerClass: SetInfoHandler::class,
            description: 'Updates a user. Leave a field blank to keep the current value.
    <br>"username", "password" and "email" are ignored if "user_id" is an array.
    <br>set "group_id" to -1 if you want to dissociate users from all groups',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID, WsParamFlag::FORCE_ARRAY),
                ParamDefinition::optionalFlag('username'),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag('status', info: 'guest,generic,normal,admin,webmaster'),
                ParamDefinition::optionalFlag('level', WsParamType::INT | WsParamType::POSITIVE, maxValue: max(self::availablePermissionLevels($this->currentConfig))),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('group_id', WsParamType::INT, WsParamFlag::FORCE_ARRAY),
                // bellow are parameters removed in a future version
                ParamDefinition::optionalFlag('nb_image_page', WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
                ParamDefinition::optionalFlag('recent_period', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('expand', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_comments', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_hits', WsParamType::BOOL),
                ParamDefinition::optionalFlag('enabled_high', WsParamType::BOOL),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.setMyInfo',
            handlerClass: SetMyInfoHandler::class,
            params: [
                ParamDefinition::optionalFlag('email'),
                ParamDefinition::optionalFlag('nb_image_page', WsParamType::INT | WsParamType::POSITIVE | WsParamType::NOTNULL),
                ParamDefinition::optionalFlag('theme'),
                ParamDefinition::optionalFlag('language'),
                ParamDefinition::optionalFlag('recent_period', WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optionalFlag('expand', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_comments', WsParamType::BOOL),
                ParamDefinition::optionalFlag('show_nb_hits', WsParamType::BOOL),
                ParamDefinition::optionalFlag('password'),
                ParamDefinition::optionalFlag('new_password'),
                ParamDefinition::optionalFlag('conf_new_password'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.preferences.set',
            handlerClass: PreferencesSetHandler::class,
            description: 'Set a user preferences parameter. JSON encode the value (and set is_json to true) if you need a complex data structure.',
            params: [
                ParamDefinition::required('param'),
                ParamDefinition::optionalFlag('value'),
                ParamDefinition::optional('is_json', false, WsParamType::BOOL),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.favorites.add',
            handlerClass: FavoritesAddHandler::class,
            description: 'Adds the indicated image to the current user\'s favorite images.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.favorites.remove',
            handlerClass: FavoritesRemoveHandler::class,
            description: 'Removes the indicated image from the current user\'s favorite images.',
            params: [
                ParamDefinition::required('image_id', WsParamType::ID),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.favorites.getList',
            handlerClass: FavoritesGetListHandler::class,
            description: 'Returns the favorite images of the current user.',
            params: [
                ParamDefinition::optional('per_page', 100, WsParamType::INT | WsParamType::POSITIVE, maxValue: $this->currentConfig->wsMaxImagesPerPage),
                ParamDefinition::optional('page', 0, WsParamType::INT | WsParamType::POSITIVE),
                ParamDefinition::optional('order', info: 'id, file, name, hit, rating_score, date_creation, date_available, random'),
            ],
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.generatePasswordLink',
            handlerClass: GeneratePasswordLinkHandler::class,
            description: 'Return the reset password link <br />
           (Only webmaster can perform this action for another webmaster)',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
                ParamDefinition::optional('send_by_mail', false, WsParamType::BOOL),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.setMainUser',
            handlerClass: SetMainUserHandler::class,
            description: 'Update the main user (owner) <br />
            - To be the main user, the user must have the status "webmaster".<br />
            - Only a webmaster can perform this action',
            params: [
                ParamDefinition::required('user_id', WsParamType::ID),
                ParamDefinition::required('pwg_token'),
            ],
            requiresAuth: true,
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.api_key.create',
            handlerClass: CreateApiKeyHandler::class,
            description: 'Create a new api key for the user in the current session',
            params: [
                ParamDefinition::required('key_name'),
                ParamDefinition::required('duration', WsParamType::INT | WsParamType::POSITIVE, info: 'Number of days'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.api_key.revoke',
            handlerClass: RevokeApiKeyHandler::class,
            description: 'Revoke a api key for the user in the current session',
            params: [
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.api_key.edit',
            handlerClass: EditApiKeyHandler::class,
            description: 'Edit a api key for the user in the current session',
            params: [
                ParamDefinition::required('key_name'),
                ParamDefinition::required('pkid'),
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));

        $service->register(MethodDefinition::forHandler(
            name: 'pwg.users.api_key.get',
            handlerClass: GetApiKeyHandler::class,
            description: 'Get all api key for the user in the current session',
            params: [
                ParamDefinition::required('pwg_token'),
            ],
            postOnly: true,
        ));
    }

    /**
     * Guards against a misconfigured/empty value since max() requires a
     * non-empty array -- moved here from the old WsDefaultMethods::register(),
     * duplicated in ImagesMethodRegistrar/UsersMethodRegistrar (its only 2
     * real consumers) rather than factored into a third shared class, same
     * "small duplication over cross-cutting shared state" precedent as this
     * codebase already uses elsewhere.
     *
     * @return non-empty-list<int>
     */
    private static function availablePermissionLevels(CurrentConfig $currentConfig): array
    {
        $levels = $currentConfig->availablePermissionLevels;

        return $levels !== [] ? $levels : [0, 1, 2, 4, 8];
    }
}
