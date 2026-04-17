import { dbCleanupTestEntities, closeDbPool } from "./db.js";

export default async function globalTeardown(): Promise<void> {
    await dbCleanupTestEntities();
    await closeDbPool();
}
