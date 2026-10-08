import { eq, and } from 'drizzle-orm';
import { db } from './index.js';
import {
  users,
  folders,
  files,
  settings,
  admins,
  adminHiddenFiles,
  adminDeletedMembers,
} from './schema.js';
import type { User, FileItem, FolderItem, Settings } from '../storage.js';
import fs from 'node:fs';
import path from 'node:path';

export async function seedInitialData(dataDir: string) {
  try {
    // Check if users exist in DB
    const existingUsers = await db.select().from(users).limit(1);
    if (existingUsers.length === 0) {
      const uPath = path.join(dataDir, 'users.json');
      if (fs.existsSync(uPath)) {
        const uList = JSON.parse(fs.readFileSync(uPath, 'utf-8')) as User[];
        for (const u of uList) {
          await db.insert(users).values({
            id: u.id,
            username: u.username,
            email: u.email,
            password: u.password,
            storageUsed: u.storage_used || 0,
            createdAt: u.created_at || new Date().toISOString(),
            status: u.status || 'active',
          }).onConflictDoNothing();
        }
      }
    }

    // Folders
    const existingFolders = await db.select().from(folders).limit(1);
    if (existingFolders.length === 0) {
      const fPath = path.join(dataDir, 'folders.json');
      if (fs.existsSync(fPath)) {
        const fObj = JSON.parse(fs.readFileSync(fPath, 'utf-8')) as Record<string, FolderItem>;
        for (const f of Object.values(fObj)) {
          await db.insert(folders).values({
            id: f.id,
            name: f.name,
            parentId: f.parent_id || '0',
            userId: f.user_id,
            password: f.password || null,
            createdAt: f.created_at || new Date().toISOString(),
          }).onConflictDoNothing();
        }
      }
    }

    // Files
    const existingFiles = await db.select().from(files).limit(1);
    if (existingFiles.length === 0) {
      const flPath = path.join(dataDir, 'files.json');
      if (fs.existsSync(flPath)) {
        const flObj = JSON.parse(fs.readFileSync(flPath, 'utf-8')) as Record<string, FileItem>;
        for (const [fid, fl] of Object.entries(flObj)) {
          await db.insert(files).values({
            id: fid,
            userId: fl.user_id,
            username: fl.username,
            email: fl.email,
            uploadedBy: fl.uploaded_by,
            originalName: fl.original_name,
            filename: fl.filename,
            size: fl.size || 0,
            type: fl.type || 'application/octet-stream',
            folderId: fl.folder_id || '0',
            date: fl.date || new Date().toISOString(),
            downloads: fl.downloads || 0,
            password: fl.password || null,
          }).onConflictDoNothing();
        }
      }
    }

    // Settings
    const existingSettings = await db.select().from(settings).limit(1);
    if (existingSettings.length === 0) {
      const sPath = path.join(dataDir, 'settings.json');
      if (fs.existsSync(sPath)) {
        const sObj = JSON.parse(fs.readFileSync(sPath, 'utf-8')) as Settings;
        await db.insert(settings).values({
          key: 'site_name',
          value: sObj.site_name || 'CKM Files',
        }).onConflictDoNothing();
      }
    }

    // Admins
    const existingAdmins = await db.select().from(admins).limit(1);
    if (existingAdmins.length === 0) {
      const aPath = path.join(dataDir, 'admins.json');
      if (fs.existsSync(aPath)) {
        const aObj = JSON.parse(fs.readFileSync(aPath, 'utf-8')) as Record<string, string>;
        for (const [adminUser, hash] of Object.entries(aObj)) {
          await db.insert(admins).values({
            username: adminUser,
            passwordHash: hash,
          }).onConflictDoNothing();
        }
      }
    }
  } catch (error) {
    console.error('seedInitialData failed:', error);
  }
}

// USER REPOSITORY
export async function getAllUsersFromDb(): Promise<User[]> {
  try {
    const list = await db.select().from(users);
    return list.map((u) => ({
      id: u.id,
      username: u.username,
      email: u.email,
      password: u.password,
      storage_used: Number(u.storageUsed || 0),
      created_at: u.createdAt,
      status: (u.status as 'active' | 'blocked') || 'active',
    }));
  } catch (error) {
    console.error('getAllUsersFromDb error:', error);
    throw new Error('Database query failed for users.', { cause: error });
  }
}

export async function upsertUserInDb(u: User): Promise<void> {
  try {
    await db.insert(users).values({
      id: u.id,
      username: u.username,
      email: u.email,
      password: u.password,
      storageUsed: u.storage_used || 0,
      createdAt: u.created_at || new Date().toISOString(),
      status: u.status || 'active',
    }).onConflictDoUpdate({
      target: users.id,
      set: {
        username: u.username,
        email: u.email,
        password: u.password,
        storageUsed: u.storage_used || 0,
        status: u.status || 'active',
      },
    });
  } catch (error) {
    console.error('upsertUserInDb error:', error);
    throw new Error('Database upsert failed for user.', { cause: error });
  }
}

// FOLDER REPOSITORY
export async function getAllFoldersFromDb(): Promise<Record<string, FolderItem>> {
  try {
    const list = await db.select().from(folders);
    const map: Record<string, FolderItem> = {};
    for (const f of list) {
      map[f.id] = {
        id: f.id,
        name: f.name,
        parent_id: f.parentId || '0',
        user_id: f.userId,
        password: f.password || undefined,
        created_at: f.createdAt,
      };
    }
    return map;
  } catch (error) {
    console.error('getAllFoldersFromDb error:', error);
    throw new Error('Database query failed for folders.', { cause: error });
  }
}

export async function upsertFolderInDb(f: FolderItem): Promise<void> {
  try {
    await db.insert(folders).values({
      id: f.id,
      name: f.name,
      parentId: f.parent_id || '0',
      userId: f.user_id,
      password: f.password || null,
      createdAt: f.created_at || new Date().toISOString(),
    }).onConflictDoUpdate({
      target: folders.id,
      set: {
        name: f.name,
        parentId: f.parent_id || '0',
        password: f.password || null,
      },
    });
  } catch (error) {
    console.error('upsertFolderInDb error:', error);
    throw new Error('Database upsert failed for folder.', { cause: error });
  }
}

export async function deleteFolderFromDb(id: string): Promise<void> {
  try {
    await db.delete(folders).where(eq(folders.id, id));
  } catch (error) {
    console.error('deleteFolderFromDb error:', error);
    throw new Error('Database delete failed for folder.', { cause: error });
  }
}

// FILE REPOSITORY
export async function getAllFilesFromDb(): Promise<Record<string, FileItem>> {
  try {
    const list = await db.select().from(files);
    const map: Record<string, FileItem> = {};
    for (const fl of list) {
      map[fl.id] = {
        user_id: fl.userId,
        username: fl.username,
        email: fl.email,
        uploaded_by: fl.uploadedBy,
        original_name: fl.originalName,
        filename: fl.filename,
        size: Number(fl.size || 0),
        type: fl.type,
        folder_id: fl.folderId || '0',
        date: fl.date,
        downloads: fl.downloads || 0,
        password: fl.password || undefined,
      };
    }
    return map;
  } catch (error) {
    console.error('getAllFilesFromDb error:', error);
    throw new Error('Database query failed for files.', { cause: error });
  }
}

export async function upsertFileInDb(id: string, fl: FileItem): Promise<void> {
  try {
    await db.insert(files).values({
      id,
      userId: fl.user_id,
      username: fl.username,
      email: fl.email,
      uploadedBy: fl.uploaded_by,
      originalName: fl.original_name,
      filename: fl.filename,
      size: fl.size || 0,
      type: fl.type || 'application/octet-stream',
      folderId: fl.folder_id || '0',
      date: fl.date || new Date().toISOString(),
      downloads: fl.downloads || 0,
      password: fl.password || null,
    }).onConflictDoUpdate({
      target: files.id,
      set: {
        originalName: fl.original_name,
        folderId: fl.folder_id || '0',
        downloads: fl.downloads || 0,
        password: fl.password || null,
      },
    });
  } catch (error) {
    console.error('upsertFileInDb error:', error);
    throw new Error('Database upsert failed for file.', { cause: error });
  }
}

export async function deleteFileFromDb(id: string): Promise<void> {
  try {
    await db.delete(files).where(eq(files.id, id));
  } catch (error) {
    console.error('deleteFileFromDb error:', error);
    throw new Error('Database delete failed for file.', { cause: error });
  }
}

// SETTINGS REPOSITORY
export async function getSettingsFromDb(): Promise<Settings> {
  try {
    const list = await db.select().from(settings);
    const out: Record<string, string> = {};
    for (const s of list) {
      out[s.key] = s.value;
    }
    return {
      site_name: out.site_name || 'CKM Files',
    };
  } catch (error) {
    console.error('getSettingsFromDb error:', error);
    return { site_name: 'CKM Files' };
  }
}

export async function saveSettingInDb(key: string, value: string): Promise<void> {
  try {
    await db.insert(settings).values({ key, value }).onConflictDoUpdate({
      target: settings.key,
      set: { value },
    });
  } catch (error) {
    console.error('saveSettingInDb error:', error);
    throw new Error('Database save failed for setting.', { cause: error });
  }
}

// ADMIN REPOSITORY
export async function getAdminsFromDb(): Promise<Record<string, string>> {
  try {
    const list = await db.select().from(admins);
    const map: Record<string, string> = {};
    for (const a of list) {
      map[a.username] = a.passwordHash;
    }
    return map;
  } catch (error) {
    console.error('getAdminsFromDb error:', error);
    return {};
  }
}

export async function getAdminHiddenFilesFromDb(): Promise<Record<string, Record<string, boolean>>> {
  try {
    const list = await db.select().from(adminHiddenFiles);
    const map: Record<string, Record<string, boolean>> = {};
    for (const item of list) {
      if (!map[item.userId]) map[item.userId] = {};
      map[item.userId][item.fileId] = true;
    }
    return map;
  } catch (error) {
    console.error('getAdminHiddenFilesFromDb error:', error);
    return {};
  }
}

export async function toggleAdminHiddenFileInDb(userId: string, fileId: string, hidden: boolean): Promise<void> {
  try {
    if (hidden) {
      await db.insert(adminHiddenFiles).values({ userId, fileId });
    } else {
      await db.delete(adminHiddenFiles).where(
        and(eq(adminHiddenFiles.userId, userId), eq(adminHiddenFiles.fileId, fileId))
      );
    }
  } catch (error) {
    console.error('toggleAdminHiddenFileInDb error:', error);
  }
}

export async function getAdminDeletedMembersFromDb(): Promise<Record<string, boolean>> {
  try {
    const list = await db.select().from(adminDeletedMembers);
    const map: Record<string, boolean> = {};
    for (const item of list) {
      map[item.userId] = true;
    }
    return map;
  } catch (error) {
    console.error('getAdminDeletedMembersFromDb error:', error);
    return {};
  }
}

export async function addAdminDeletedMemberInDb(userId: string): Promise<void> {
  try {
    await db.insert(adminDeletedMembers).values({ userId }).onConflictDoNothing();
  } catch (error) {
    console.error('addAdminDeletedMemberInDb error:', error);
  }
}
