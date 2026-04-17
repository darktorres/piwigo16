export { loginAsAdmin } from "./auth.js";
export { collectConsoleIssues, assertNoErrors, type ConsoleIssue } from "./console.js";
export { uniqueName, ensureTestPhotoExists } from "./fixtures.js";
export {
    dbCount,
    dbFindGroup,
    dbFindUser,
    dbFindTag,
    dbFindAlbum,
    dbFindComment,
    dbFindCommentByContent,
    dbGetPhotoAuthor,
    dbGetFirstPhotoId,
    dbGetFirstPhysicalAlbumId,
    dbGetFirstGroupId,
    dbGetAdminUserId,
    dbGetRating,
    dbDeleteRating,
    dbCreateTestPrivateCategory,
    dbGetUserAccess,
    dbCleanupTestEntities,
    dbSetConfig,
    closeDbPool,
} from "./db.js";
