import mysql, { type RowDataPacket } from "mysql2/promise";

const pool = mysql.createPool({
    host: "127.0.0.1",
    user: "root",
    password: "1234",
    database: "piwigo_fork",
    waitForConnections: true,
    connectionLimit: 3,
});

async function run(sql: string): Promise<string[][]> {
    const [rows] = await pool.query<RowDataPacket[]>(sql);
    if (!Array.isArray(rows)) return [];
    return rows.map((row) => Object.values(row).map((v) => (v === null ? "" : String(v))));
}

function q(s: string): string {
    return s.replace(/'/g, "''");
}

export async function dbCount(table: string, where?: string): Promise<number> {
    const sql = where ? `SELECT COUNT(*) FROM ${table} WHERE ${where}` : `SELECT COUNT(*) FROM ${table}`;
    const rows = await run(sql);
    return rows.length > 0 ? parseInt(rows[0][0], 10) : 0;
}

export async function dbFindGroup(name: string): Promise<{ id: string } | null> {
    const rows = await run(`SELECT id FROM user_groups WHERE name='${q(name)}'`);
    return rows.length > 0 ? { id: rows[0][0] } : null;
}

export async function dbFindUser(username: string): Promise<{ id: string } | null> {
    const rows = await run(`SELECT id FROM users WHERE username='${q(username)}'`);
    return rows.length > 0 ? { id: rows[0][0] } : null;
}

export async function dbFindTag(name: string): Promise<{ id: string } | null> {
    const rows = await run(`SELECT id FROM tags WHERE name='${q(name)}'`);
    return rows.length > 0 ? { id: rows[0][0] } : null;
}

export async function dbFindAlbum(name: string): Promise<{ id: string } | null> {
    const rows = await run(`SELECT id FROM categories WHERE name='${q(name)}' AND dir IS NULL`);
    return rows.length > 0 ? { id: rows[0][0] } : null;
}

export async function dbFindComment(imageId: number, author: string): Promise<{ id: string; validated: string } | null> {
    const rows = await run(`SELECT id, validated FROM comments WHERE image_id=${imageId} AND author='${q(author)}'`);
    return rows.length > 0 ? { id: rows[0][0], validated: rows[0][1] } : null;
}

export async function dbFindCommentByContent(imageId: number, content: string): Promise<{ id: string; validated: string } | null> {
    const rows = await run(`SELECT id, validated FROM comments WHERE image_id=${imageId} AND content='${q(content)}'`);
    return rows.length > 0 ? { id: rows[0][0], validated: rows[0][1] } : null;
}

export async function dbGetPhotoAuthor(id: number): Promise<string | null> {
    const rows = await run(`SELECT author FROM images WHERE id=${id}`);
    return rows.length > 0 ? rows[0][0] : null;
}

export async function dbGetFirstPhotoId(): Promise<number | null> {
    const rows = await run("SELECT id FROM images ORDER BY id LIMIT 1");
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbGetFirstPhysicalAlbumId(): Promise<number | null> {
    const rows = await run("SELECT id FROM categories WHERE dir IS NOT NULL ORDER BY id LIMIT 1");
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbGetAlbumWithPhotosId(): Promise<number | null> {
    const rows = await run(
        "SELECT c.id FROM categories c JOIN image_category ic ON c.id = ic.category_id WHERE c.dir IS NOT NULL GROUP BY c.id HAVING COUNT(ic.image_id) > 0 ORDER BY c.id LIMIT 1",
    );
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbGetFirstGroupId(): Promise<number | null> {
    const rows = await run("SELECT id FROM user_groups ORDER BY id LIMIT 1");
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbGetAdminUserId(): Promise<number | null> {
    const rows = await run(
        "SELECT u.id FROM users u JOIN user_infos ui ON u.id = ui.user_id WHERE ui.status='webmaster' ORDER BY u.id LIMIT 1",
    );
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbGetRating(elementId: number, userId: number): Promise<number | null> {
    const rows = await run(`SELECT rate FROM rate WHERE element_id=${elementId} AND user_id=${userId} LIMIT 1`);
    return rows.length > 0 ? parseInt(rows[0][0], 10) : null;
}

export async function dbDeleteRating(elementId: number, userId: number): Promise<void> {
    await run(`DELETE FROM rate WHERE element_id=${elementId} AND user_id=${userId}`);
}

export async function dbCreateTestPrivateCategory(name: string): Promise<number> {
    await run(`INSERT INTO categories (name, dir, status, visible, commentable) VALUES ('${q(name)}', NULL, 'private', 1, 1)`);
    const rows = await run("SELECT LAST_INSERT_ID()");
    const id = parseInt(rows[0][0], 10);
    await run(`UPDATE categories SET uppercats='${id}', global_rank='${id}' WHERE id=${id}`);
    return id;
}

export async function dbGetUserAccess(userId: number, catId: number): Promise<boolean> {
    const rows = await run(`SELECT 1 FROM user_access WHERE user_id=${userId} AND cat_id=${catId}`);
    return rows.length > 0;
}

export async function dbCleanupTestEntities(): Promise<void> {
    const p = "test\\_%\\_%\\_%";
    const stmts = [
        `DELETE FROM comments WHERE author LIKE '${p}'`,
        `DELETE FROM image_tag WHERE tag_id IN (SELECT id FROM tags WHERE name LIKE '${p}')`,
        `DELETE FROM tags WHERE name LIKE '${p}'`,
        `DELETE FROM user_access WHERE user_id IN (SELECT id FROM users WHERE username LIKE '${p}')`,
        `DELETE FROM user_group WHERE user_id IN (SELECT id FROM users WHERE username LIKE '${p}')`,
        `DELETE FROM users WHERE username LIKE '${p}'`,
        `DELETE FROM user_group WHERE group_id IN (SELECT id FROM user_groups WHERE name LIKE '${p}')`,
        `DELETE FROM user_groups WHERE name LIKE '${p}'`,
        `DELETE FROM user_access WHERE cat_id IN (SELECT id FROM categories WHERE name LIKE '${p}')`,
        `DELETE FROM categories WHERE name LIKE '${p}'`,
    ];
    for (const sql of stmts) {
        await run(sql);
    }
}

export async function dbSetConfig(param: string, value: string): Promise<void> {
    await run(`UPDATE config SET value='${q(value)}' WHERE param='${q(param)}'`);
}

export async function closeDbPool(): Promise<void> {
    await pool.end();
}
