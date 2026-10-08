import fs from 'node:fs';
import path from 'node:path';
import bcrypt from 'bcryptjs';
import {
  seedInitialData,
  getAllUsersFromDb,
  upsertUserInDb,
  getAllFoldersFromDb,
  upsertFolderInDb,
  deleteFolderFromDb,
  getAllFilesFromDb,
  upsertFileInDb,
  deleteFileFromDb,
  getSettingsFromDb,
  saveSettingInDb,
} from './db/repo.js';

export { getAllUsersFromDb, getAllFoldersFromDb, getAllFilesFromDb };

export async function getOrFetchFile(fileId: string): Promise<FileItem | null> {
  const files = readJson<Record<string, FileItem>>('files.json', {});
  if (files[fileId]) return files[fileId];
  try {
    const dbFiles = await getAllFilesFromDb();
    if (dbFiles && dbFiles[fileId]) {
      files[fileId] = dbFiles[fileId];
      writeJson('files.json', files);
      return dbFiles[fileId];
    }
  } catch (err) {
    console.error('getOrFetchFile DB error:', err);
  }
  return null;
}

export async function getOrFetchFolder(folderId: string): Promise<FolderItem | null> {
  const folders = readJson<Record<string, FolderItem>>('folders.json', {});
  if (folders[folderId]) return folders[folderId];
  try {
    const dbFolders = await getAllFoldersFromDb();
    if (dbFolders && dbFolders[folderId]) {
      folders[folderId] = dbFolders[folderId];
      writeJson('folders.json', folders);
      return dbFolders[folderId];
    }
  } catch (err) {
    console.error('getOrFetchFolder DB error:', err);
  }
  return null;
}

const isVercel = !!process.env.VERCEL;
export const BASE_DIR = isVercel ? '/tmp/mediafire_data' : process.cwd();
export const DATA_DIR = path.resolve(BASE_DIR, 'data');
export const UPLOADS_DIR = path.resolve(BASE_DIR, 'uploads');
export const TMP_DIR = path.resolve(DATA_DIR, 'uploads_tmp');

let initialSyncPromise: Promise<void> | null = null;

export async function ensureStorageSynced(): Promise<void> {
  if (initialSyncPromise) {
    try {
      await initialSyncPromise;
    } catch (err) {
      console.error('ensureStorageSynced error:', err);
    }
  }
}

export function initStorage() {
  try {
    if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
    if (!fs.existsSync(UPLOADS_DIR)) fs.mkdirSync(UPLOADS_DIR, { recursive: true });
    if (!fs.existsSync(TMP_DIR)) fs.mkdirSync(TMP_DIR, { recursive: true });

    const ensureFile = (filename: string, defaultContent: string) => {
      const p = path.join(DATA_DIR, filename);
      if (!fs.existsSync(p)) {
        const bundled = path.join(process.cwd(), 'data', filename);
        if (fs.existsSync(bundled)) {
          try {
            fs.copyFileSync(bundled, p);
            return;
          } catch {}
        }
        fs.writeFileSync(p, defaultContent, 'utf-8');
      }
    };

    ensureFile('users.json', '[]');
    ensureFile('files.json', '{}');
    ensureFile('folders.json', '{}');
    ensureFile('settings.json', JSON.stringify({ site_name: 'CKM Files' }, null, 2));
    ensureFile('admin_hidden_files.json', '{}');
    ensureFile('admin_deleted_members.json', '{}');

    const adminFile = path.join(DATA_DIR, 'admins.json');
    if (!fs.existsSync(adminFile)) {
      const bundledAdmin = path.join(process.cwd(), 'data', 'admins.json');
      if (fs.existsSync(bundledAdmin)) {
        try {
          fs.copyFileSync(bundledAdmin, adminFile);
        } catch {}
      } else {
        const hash = bcrypt.hashSync('kash,,..', 10);
        fs.writeFileSync(adminFile, JSON.stringify({ admin: hash }, null, 2), 'utf-8');
      }
    }

    // Seed and sync with Cloud SQL PostgreSQL immediately and track promise
    initialSyncPromise = (async () => {
      try {
        await seedInitialData(DATA_DIR);
        await syncFromDatabase();
      } catch (err) {
        console.error('Async DB sync init error:', err);
      }
    })();
  } catch (err) {
    console.error('initStorage error:', err);
  }
}

export async function syncFromDatabase(): Promise<void> {
  try {
    const dbUsers = await getAllUsersFromDb();
    if (dbUsers && dbUsers.length > 0) {
      writeJson('users.json', dbUsers);
    }
    const dbFolders = await getAllFoldersFromDb();
    if (dbFolders && Object.keys(dbFolders).length > 0) {
      writeJson('folders.json', dbFolders);
    }
    const dbFiles = await getAllFilesFromDb();
    if (dbFiles && Object.keys(dbFiles).length > 0) {
      writeJson('files.json', dbFiles);
    }
    const dbSettings = await getSettingsFromDb();
    if (dbSettings && dbSettings.site_name) {
      writeJson('settings.json', dbSettings);
    }
  } catch (err) {
    console.error('syncFromDatabase error:', err);
  }
}

export function readJson<T>(filename: string, defaultValue: T): T {
  const p = path.join(DATA_DIR, filename);
  try {
    if (!fs.existsSync(p)) return defaultValue;
    const content = fs.readFileSync(p, 'utf-8');
    return JSON.parse(content) as T;
  } catch {
    return defaultValue;
  }
}

export function writeJson<T>(filename: string, data: T): void {
  try {
    if (!fs.existsSync(DATA_DIR)) {
      fs.mkdirSync(DATA_DIR, { recursive: true });
    }
    const p = path.join(DATA_DIR, filename);
    const tmp = `${p}.${Date.now()}.${Math.random().toString(36).substring(2, 6)}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf-8');
    fs.renameSync(tmp, p);
  } catch (err) {
    try {
      const p = path.join(DATA_DIR, filename);
      fs.writeFileSync(p, JSON.stringify(data, null, 2), 'utf-8');
    } catch (fallbackErr) {
      console.error('writeJson error for ' + filename + ':', fallbackErr);
    }
  }
}

// PERSISTENT HELPERS
export async function saveUserPersistent(user: User): Promise<void> {
  const users = readJson<User[]>('users.json', []);
  const idx = users.findIndex((u) => u.id === user.id);
  if (idx !== -1) users[idx] = user;
  else users.push(user);
  writeJson('users.json', users);

  try {
    await upsertUserInDb(user);
  } catch (err) {
    console.error('saveUserPersistent DB error:', err);
  }
}

export async function saveFolderPersistent(folder: FolderItem): Promise<void> {
  const folders = readJson<Record<string, FolderItem>>('folders.json', {});
  folders[folder.id] = folder;
  writeJson('folders.json', folders);

  try {
    await upsertFolderInDb(folder);
  } catch (err) {
    console.error('saveFolderPersistent DB error:', err);
  }
}

export async function deleteFolderPersistent(folderId: string): Promise<void> {
  const folders = readJson<Record<string, FolderItem>>('folders.json', {});
  delete folders[folderId];
  writeJson('folders.json', folders);

  try {
    await deleteFolderFromDb(folderId);
  } catch (err) {
    console.error('deleteFolderPersistent DB error:', err);
  }
}

export async function saveFilePersistent(fileId: string, file: FileItem): Promise<void> {
  const files = readJson<Record<string, FileItem>>('files.json', {});
  files[fileId] = file;
  writeJson('files.json', files);

  try {
    await upsertFileInDb(fileId, file);
  } catch (err) {
    console.error('saveFilePersistent DB error:', err);
  }
}

export async function deleteFilePersistent(fileId: string): Promise<void> {
  const files = readJson<Record<string, FileItem>>('files.json', {});
  delete files[fileId];
  writeJson('files.json', files);

  try {
    await deleteFileFromDb(fileId);
  } catch (err) {
    console.error('deleteFilePersistent DB error:', err);
  }
}

export async function saveSettingPersistent(key: string, value: string): Promise<void> {
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
  if (key === 'site_name') settings.site_name = value;
  writeJson('settings.json', settings);

  try {
    await saveSettingInDb(key, value);
  } catch (err) {
    console.error('saveSettingPersistent DB error:', err);
  }
}

export interface User {
  id: string;
  username: string;
  email: string;
  password: string;
  storage_used: number;
  created_at: string;
  status: 'active' | 'blocked';
}

export interface FileItem {
  user_id: string;
  username: string;
  email: string;
  uploaded_by: string;
  original_name: string;
  filename: string;
  size: number;
  type: string;
  folder_id: string;
  date: string;
  downloads: number;
  password?: string;
}

export interface FolderItem {
  id: string;
  name: string;
  parent_id: string;
  user_id: string;
  password?: string;
  created_at: string;
}

export interface Settings {
  site_name: string;
}

export function formatBytes(bytes: number): string {
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
  return bytes + ' B';
}

export function getFileIcon(filename: string): [string, string] {
  const ext = path.extname(filename).toLowerCase().replace('.', '');
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return ['fa-file-image', '#60a5fa'];
  if (['mp4', 'avi', 'mkv', 'webm', 'mov', '3gp'].includes(ext)) return ['fa-file-video', '#f87171'];
  if (['mp3', 'wav', 'flac', 'm4a', 'aac'].includes(ext)) return ['fa-file-audio', '#fbbf24'];
  if (ext === 'pdf') return ['fa-file-pdf', '#ef4444'];
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return ['fa-file-zipper', '#a78bfa'];
  if (['txt', 'md', 'json', 'js', 'ts', 'html', 'css'].includes(ext)) return ['fa-file-code', '#38bdf8'];
  return ['fa-file', '#94a3b8'];
}

export function isVideoFile(filename: string): boolean {
  const ext = path.extname(filename).toLowerCase().replace('.', '');
  return ['mp4', 'webm', 'mkv', 'avi', 'mov', '3gp'].includes(ext);
}

export function getFolderSize(
  fid: string,
  files: Record<string, FileItem>,
  folders: Record<string, FolderItem>,
  uid: string
): number {
  let size = 0;
  for (const f of Object.values(files)) {
    if ((f.folder_id || '0') === fid && f.user_id === uid) {
      size += f.size || 0;
    }
  }
  for (const [k, f] of Object.entries(folders)) {
    if ((f.parent_id || '0') === fid && f.user_id === uid) {
      size += getFolderSize(k, files, folders, uid);
    }
  }
  return size;
}

export function buildPath(
  fid: string,
  folders: Record<string, FolderItem>
): Array<{ id: string; name: string }> {
  const breadcrumb: Array<{ id: string; name: string }> = [];
  let current = fid;
  let count = 0;
  while (current !== '0' && count < 30) {
    if (folders[current]) {
      breadcrumb.unshift({
        id: folders[current].id,
        name: folders[current].name,
      });
      current = folders[current].parent_id || '0';
    } else {
      break;
    }
    count++;
  }
  return breadcrumb;
}

export function deleteFolderRecursive(
  fid: string,
  folders: Record<string, FolderItem>,
  files: Record<string, FileItem>,
  user: User
): void {
  for (const [k, f] of Object.entries(folders)) {
    if ((f.parent_id || '0') === fid) {
      deleteFolderRecursive(k, folders, files, user);
      delete folders[k];
      deleteFolderPersistent(k).catch(() => {});
    }
  }
  for (const [k, f] of Object.entries(files)) {
    if ((f.folder_id || '0') === fid && f.user_id === user.id) {
      const fp = path.join(UPLOADS_DIR, f.filename);
      if (fs.existsSync(fp)) {
        try { fs.unlinkSync(fp); } catch {}
      }
      user.storage_used = Math.max(0, (user.storage_used || 0) - (f.size || 0));
      delete files[k];
      deleteFilePersistent(k).catch(() => {});
    }
  }
  delete folders[fid];
  deleteFolderPersistent(fid).catch(() => {});
}
