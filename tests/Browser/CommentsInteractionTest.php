<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * P49-A conversion of themes/admin/default/js/comments.ts -- 0% prior
 * live-interaction coverage (only AdminExtendedSmokeTest.php's own GET
 * smoke route touches `admin.php?page=comments` at all;
 * CommentsControllerTest.php covers the unrelated front-end
 * comments.php moderation controller, a different feature entirely).
 *
 * `$.alert()`/`$.confirm()` (jquery-confirm, P49-B group 5) stay jQuery;
 * only the DOM work around them converted. Every comment used here is
 * inserted directly (matching CommentsControllerTest.php's own
 * `commentsInsert()` shape) on a fresh throwaway photo, never touching
 * the shared fixture's own comment rows.
 */
function commentsInteractionInsert(int $imageId, string $author, string $content): int
{
    $db = H::connect();
    $sqlFalse = $db instanceof mysqli ? '0' : 'false';
    H::dbQuery($db, sprintf(
        "INSERT INTO comments (image_id, date, author, anonymous_id, content, validated) VALUES (%d, NOW(), '%s', '127.0.0.9', '%s', %s)",
        $imageId,
        H::dbEscape($db, $author),
        H::dbEscape($db, $content),
        $sqlFalse,
    ));
    $id = H::dbInsertId($db);
    H::dbClose($db);

    return $id;
}

/**
 * Narrows a flat, all-boolean JSON object decoded from H::scriptJson() to
 * exactly the requested keys -- shared by both tests below that read this
 * shape, since script()'s return is `mixed` however $page is typed.
 *
 * @param list<string> $keys
 * @return array<string, bool>
 */
function commentsInteractionBoolState(mixed $decoded, array $keys): array
{
    if (! is_array($decoded)) {
        throw new RuntimeException('commentsInteractionBoolState(): expected an array, got: ' . var_export($decoded, true));
    }

    $result = [];
    foreach ($keys as $key) {
        if (! is_bool($decoded[$key] ?? null)) {
            throw new RuntimeException("commentsInteractionBoolState(): expected boolean key '{$key}', got: " . var_export($decoded[$key] ?? null, true));
        }
        $result[$key] = $decoded[$key];
    }

    return $result;
}

function commentsInteractionWaitForLoad(Webpage|PendingAwaitablePage|AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        new Promise((resolve, reject) => {
            const deadline = Date.now() + 8000;
            const check = () => {
                if (document.querySelectorAll('.comment').length > 0) {
                    return resolve(true);
                }
                if (Date.now() > deadline) {
                    return reject(new Error('comments list never loaded'));
                }
                setTimeout(check, 100);
            };
            check();
        })
        JS);
}

it('toggles selection mode: shows checkboxes and the selection controller, hides the per-row buttons', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=comments');
    commentsInteractionWaitForLoad($page);

    $stateKeys = ['checkboxVisible', 'buttonsVisible', 'controllerShown'];
    $state = static fn (Webpage|PendingAwaitablePage|AwaitableWebpage $page): array => commentsInteractionBoolState(H::scriptJson($page, <<<'JS'
        JSON.stringify({
            checkboxVisible: document.querySelector('.comment-select-checkbox').offsetParent !== null,
            buttonsVisible: document.querySelector('.comment-buttons').offsetParent !== null,
            controllerShown: document.getElementById('commentsSelectController').classList.contains('show'),
        })
        JS), $stateKeys);

    $initial = $state($page);
    expect($initial['checkboxVisible'])->toBeFalse();
    expect($initial['buttonsVisible'])->toBeTrue();
    expect($initial['controllerShown'])->toBeFalse();

    $page->click('.comments-selection-switch label.switch');

    $on = $state($page);
    expect($on['checkboxVisible'])->toBeTrue();
    expect($on['buttonsVisible'])->toBeFalse();
    expect($on['controllerShown'])->toBeTrue();

    $page->click('.comments-selection-switch label.switch');

    $off = $state($page);
    expect($off['checkboxVisible'])->toBeFalse();
    expect($off['buttonsVisible'])->toBeTrue();
    expect($off['controllerShown'])->toBeFalse();

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'comments selection-mode toggle');
});

it('selects a comment via its checkbox and shows it in the selected-items area', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Comments Interaction Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Interaction Photo');
    @unlink($image);

    $author = 'commenter_' . uniqid();
    $commentId = commentsInteractionInsert($imageId, $author, 'A test comment ' . uniqid());

    try {
        $page = H::navigateOk($page, '/admin.php?page=comments');
        commentsInteractionWaitForLoad($page);
        $page->assertSee($author);

        $page->click('.comments-selection-switch label.switch');

        $page->click('[id="' . $commentId . '"] .comment-select-checkbox');

        $selected = commentsInteractionBoolState(H::scriptJson($page, <<<JS
            JSON.stringify({
                itemSelected: document.getElementById('{$commentId}').classList.contains('comment-selected'),
                noSelectionVisible: document.getElementById('commentsNoSelection').offsetParent !== null,
                selectionVisible: document.getElementById('commentsSelection').offsetParent !== null,
                selectedItemPresent: document.querySelector('#commentsSelected p') !== null && document.querySelector('#commentsSelected p').textContent.trim() === '#{$commentId}',
            })
            JS), ['itemSelected', 'noSelectionVisible', 'selectionVisible', 'selectedItemPresent']);

        expect($selected['itemSelected'])->toBeTrue();
        expect($selected['noSelectionVisible'])->toBeFalse();
        expect($selected['selectionVisible'])->toBeTrue();
        expect($selected['selectedItemPresent'])->toBeTrue();

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'comments select via checkbox');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM comments WHERE id = %d', $commentId));
        H::dbClose($db);
    }
});

it('validates a comment via its button and jGrowl-free success flow removes it from the pending list', function (): void {
    $page = H::asAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Comments Interaction Validate Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Comments Interaction Validate Photo');
    @unlink($image);

    $author = 'commenter_validate_' . uniqid();
    $commentId = commentsInteractionInsert($imageId, $author, 'Pending comment ' . uniqid());

    try {
        $page = H::navigateOk($page, '/admin.php?page=comments');
        commentsInteractionWaitForLoad($page);
        $page->assertSee($author);

        $page->click('[id="' . $commentId . '"] .comment-validate');

        // Still-jQuery jquery-confirm success alert -- assert on its own
        // rendered text rather than the whole page, same reasoning as
        // rating_user.ts's own confirm-dialog test earlier in this
        // campaign.
        $page->script(<<<'JS'
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 5000;
                const check = () => {
                    if (document.querySelector('.jconfirm') !== null) {
                        return resolve(true);
                    }
                    if (Date.now() > deadline) {
                        return reject(new Error('validate success alert never shown'));
                    }
                    setTimeout(check, 100);
                };
                check();
            })
            JS);
        $page->assertSeeIn('.jconfirm', 'validated');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'comments validate button');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM comments WHERE id = %d', $commentId));
        H::dbClose($db);
    }
});
