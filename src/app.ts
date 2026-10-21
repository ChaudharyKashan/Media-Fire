import express from 'express';
import session from 'express-session';
import path from 'node:path';
import fs from 'node:fs';
import crypto from 'node:crypto';
import bcrypt from 'bcryptjs';
import multer from 'multer';
import archiver from 'archiver';
import {
  initStorage,
  ensureStorageSynced,
  readJson,
  writeJson,
  formatBytes,
  getFileIcon,
  isVideoFile,
  getFolderSize,
  buildPath,
  deleteFolderRecursive,
  saveUserPersistent,
  saveFolderPersistent,
  deleteFolderPersistent,
  saveFilePersistent,
  deleteFilePersistent,
  saveSettingPersistent,
  getAllUsersFromDb,
  getAllFoldersFromDb,
  getAllFilesFromDb,
  getOrFetchFile,
  getOrFetchFolder,
  UPLOADS_DIR,
  TMP_DIR,
  type User,
  type FileItem,
  type FolderItem,
  type Settings,
} from './storage.js';
import './lib/firebase.js';
import firebaseConfig from '../firebase-applet-config.json' with { type: 'json' };

declare module 'express-session' {
  interface SessionData {
    user_id?: string;
    username?: string;
    user_email?: string;
    admin_ok?: boolean;
    admin_user?: string;
    [key: string]: any;
  }
}

initStorage();

const app = express();
app.set('trust proxy', 1);

app.set('view engine', 'ejs');
app.set('views', path.resolve(process.cwd(), 'views'));

app.use(express.urlencoded({ extended: true, limit: '50mb' }));
app.use(express.json({ limit: '50mb' }));

const SESSION_SECRET = process.env.SESSION_SECRET || 'ckm_mediafire_secure_token_secret_2026';

function parseCookies(req: express.Request): Record<string, string> {
  const list: Record<string, string> = {};
  const rc = req.headers.cookie;
  if (!rc) return list;
  rc.split(';').forEach((cookie) => {
    const parts = cookie.split('=');
    const name = parts[0]?.trim();
    if (name) {
      list[name] = decodeURIComponent(parts.slice(1).join('=').trim());
    }
  });
  return list;
}

function signSession(data: any): string {
  const jsonStr = JSON.stringify(data);
  const hmac = crypto.createHmac('sha256', SESSION_SECRET).update(jsonStr).digest('hex');
  const payload = Buffer.from(jsonStr, 'utf-8').toString('base64url');
  return `${payload}.${hmac}`;
}

function verifySession(token: string): any | null {
  if (!token || typeof token !== 'string' || !token.includes('.')) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payload, sig] = parts;
  try {
    const jsonStr = Buffer.from(payload, 'base64url').toString('utf-8');
    const expectedSig = crypto.createHmac('sha256', SESSION_SECRET).update(jsonStr).digest('hex');
    const bSig = Buffer.from(sig);
    const bExp = Buffer.from(expectedSig);
    if (bSig.length === bExp.length && crypto.timingSafeEqual(bSig, bExp)) {
      return JSON.parse(jsonStr);
    }
  } catch {
    return null;
  }
  return null;
}

function setSessionCookie(res: express.Response, sessionObj: any): string {
  const token = signSession(sessionObj);
  res.cookie('mf_session', token, {
    path: '/',
    httpOnly: true,
    secure: true,
    sameSite: 'none',
    maxAge: 30 * 24 * 60 * 60 * 1000,
  });
  return token;
}

function clearSessionCookie(res: express.Response) {
  res.clearCookie('mf_session', { path: '/', secure: true, sameSite: 'none' });
}

// Session middleware: supports in-memory sessions AND stateless signed cookies for Vercel Serverless & iFrames
app.use(
  session({
    secret: SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: {
      maxAge: 30 * 24 * 60 * 60 * 1000,
      secure: true,
      sameSite: 'none',
    },
  }) as any
);

app.use((req: any, res: any, next: any) => {
  const cookies = parseCookies(req);
  let rawToken = cookies['mf_session'];
  const headerToken = req.headers['x-session-token'];
  const queryToken = req.query.sid || req.query.t || headerToken;
  if (!rawToken && queryToken) {
    rawToken = String(queryToken);
  }
  const sessionData = rawToken ? verifySession(rawToken) : null;

  if (!req.session) {
    req.session = {} as any;
  }

  if (sessionData) {
    if (sessionData.user_id) req.session.user_id = sessionData.user_id;
    if (sessionData.username) req.session.username = sessionData.username;
    if (sessionData.user_email) req.session.user_email = sessionData.user_email;
    if (sessionData.admin_ok !== undefined) req.session.admin_ok = sessionData.admin_ok;
    if (sessionData.admin_user) req.session.admin_user = sessionData.admin_user;
    if (sessionData.cached_user) req.session.cached_user = sessionData.cached_user;

    // Self-healing user restoration for Vercel Serverless containers
    if (sessionData.user_id && sessionData.cached_user) {
      const users = readJson<User[]>('users.json', []);
      const idx = users.findIndex((u) => u.id === sessionData.user_id);
      if (idx === -1) {
        users.push(sessionData.cached_user);
        writeJson('users.json', users);
      }
    }
  }

  if (queryToken && typeof res.redirect === 'function') {
    const origRedirect = res.redirect.bind(res);
    res.redirect = function (statusOrUrl: any, maybeUrl?: any) {
      let target = typeof statusOrUrl === 'string' ? statusOrUrl : maybeUrl;
      if (
        typeof target === 'string' &&
        (target.startsWith('/dashboard') || target.startsWith('/upload') || target.startsWith('/profile')) &&
        !target.includes('sid=')
      ) {
        target += (target.includes('?') ? '&' : '?') + 'sid=' + encodeURIComponent(String(queryToken));
        if (typeof statusOrUrl === 'string') return origRedirect(target);
        return origRedirect(statusOrUrl, target);
      }
      if (typeof statusOrUrl === 'string') return origRedirect(statusOrUrl);
      return origRedirect(statusOrUrl, maybeUrl);
    };
  }

  next();
});

app.use((_req, res, next) => {
  res.locals.firebaseConfig = firebaseConfig;
  next();
});

app.get('/api/firebase-config', (_req, res) => {
  res.json(firebaseConfig);
});

// Load favicon buffers into memory and prepare inline base64 data URI
function loadAssetBuffer(filename: string): Buffer | null {
  const candidates = [
    path.resolve(process.cwd(), filename),
    path.resolve(process.cwd(), 'public', filename),
  ];
  for (const p of candidates) {
    try {
      if (fs.existsSync(p)) return fs.readFileSync(p);
    } catch {}
  }
  return null;
}

const faviconIcoBuf = loadAssetBuffer('favicon.ico');
const faviconPngBuf = loadAssetBuffer('favicon.png') || loadAssetBuffer('favicon-32x32.png');
const favicon32Buf = loadAssetBuffer('favicon-32x32.png') || faviconPngBuf;
const appleTouchBuf = loadAssetBuffer('apple-touch-icon.png') || faviconPngBuf;
const FAVICON_DATA_URI = favicon32Buf
  ? `data:image/png;base64,${favicon32Buf.toString('base64')}`
  : '/favicon.png?v=20261008';

app.use((req, res, next) => {
  res.locals.faviconDataUri = FAVICON_DATA_URI;
  next();
});

app.get('/favicon.ico', (_req, res) => {
  if (faviconIcoBuf) {
    res.setHeader('Content-Type', 'image/x-icon');
    res.setHeader('Cache-Control', 'public, max-age=86400');
    return res.send(faviconIcoBuf);
  }
  if (faviconPngBuf) {
    res.setHeader('Content-Type', 'image/png');
    res.setHeader('Cache-Control', 'public, max-age=86400');
    return res.send(faviconPngBuf);
  }
  return res.status(404).end();
});

app.get(['/favicon.png', '/favicon-32x32.png'], (req, res) => {
  const buf = req.path.includes('32x32') ? favicon32Buf : faviconPngBuf;
  if (buf) {
    res.setHeader('Content-Type', 'image/png');
    res.setHeader('Cache-Control', 'public, max-age=86400');
    return res.send(buf);
  }
  return res.status(404).end();
});

app.get('/apple-touch-icon.png', (_req, res) => {
  if (appleTouchBuf) {
    res.setHeader('Content-Type', 'image/png');
    res.setHeader('Cache-Control', 'public, max-age=86400');
    return res.send(appleTouchBuf);
  }
  return res.status(404).end();
});

// Serve static assets (favicons, etc.)
app.use(
  express.static(process.cwd(), {
    index: false,
    setHeaders: (res, filePath) => {
      if (filePath.endsWith('.ico') || filePath.endsWith('.png')) {
        res.setHeader('Cache-Control', 'public, max-age=86400');
      }
    },
  })
);

const uploadChunk = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: 15 * 1024 * 1024 },
});
const rawUploadChunkMiddleware: any = uploadChunk.single('chunk');
function uploadChunkMiddleware(req: any, res: any, next: any) {
  rawUploadChunkMiddleware(req, res, (err: any) => {
    if (err) {
      return res.status(400).json({ ok: false, error: err.message || 'Chunk upload error' });
    }
    next();
  });
}

// Middleware to get current authenticated user
function getCurrentUser(req: any): User | null {
  const userId = req.session?.user_id;
  if (!userId) return null;

  const users = readJson<User[]>('users.json', []);
  let user = users.find((u) => u.id === userId);

  // Fallback to cached user if storage was reset in serverless
  if (!user && req.session?.cached_user && req.session.cached_user.id === userId) {
    user = req.session.cached_user;
    users.push(user as User);
    writeJson('users.json', users);
  }

  if (!user || user.status === 'blocked') {
    return null;
  }
  return user;
}

function requireAuth(req: any, res: any, next: any) {
  const user = getCurrentUser(req);
  if (!user) {
    clearSessionCookie(res);
    if (req.session && typeof req.session.destroy === 'function') {
      req.session.destroy(() => {});
    }
    return res.redirect('/index.php');
  }
  res.locals.currentUser = user;
  next();
}

// ----------------------------------------------------
// INDEX / AUTH ROUTES
// ----------------------------------------------------
function handleIndexGet(req: express.Request, res: express.Response) {
  const user = getCurrentUser(req);
  if (user) {
    return res.redirect('/dashboard.php');
  }
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
  res.render('index', {
    siteName: settings.site_name || 'CKM Files',
    error: req.query.error ? String(req.query.error) : '',
    success: req.query.success ? String(req.query.success) : '',
    activeTab: req.query.tab === 'register' ? 'register' : 'login',
    enteredLogin: '',
  });
}

async function handleIndexPost(req: express.Request, res: express.Response) {
  try {
    const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
    const action = req.body.action || (req.body.register !== undefined ? 'register' : 'login');

    if (action === 'register') {
      const username = (req.body.username || '').trim();
      const email = (req.body.email || '').trim();
      const password = (req.body.password || '').trim();

      if (!username || !email || !password) {
        return res.render('index', {
          siteName: settings.site_name || 'CKM Files',
          error: 'All fields are required!',
          success: '',
          activeTab: 'register',
          enteredLogin: '',
        });
      }

      if (password.length < 4) {
        return res.render('index', {
          siteName: settings.site_name || 'CKM Files',
          error: 'Password too short! Minimum 4 characters.',
          success: '',
          activeTab: 'register',
          enteredLogin: '',
        });
      }

      const users = readJson<User[]>('users.json', []);
      const exists = users.some(
        (u) =>
          ((u.email || '').toLowerCase().trim() === email.toLowerCase()) ||
          ((u.username || '').toLowerCase().trim() === username.toLowerCase())
      );

      if (exists) {
        return res.render('index', {
          siteName: settings.site_name || 'CKM Files',
          error: 'Username or email already exists!',
          success: '',
          activeTab: 'register',
          enteredLogin: '',
        });
      }

      const newUser: User = {
        id: 'u' + Date.now() + Math.floor(Math.random() * 900 + 100),
        username,
        email,
        password: bcrypt.hashSync(password, 10),
        storage_used: 0,
        created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
        status: 'active',
      };

      await saveUserPersistent(newUser);

      return res.render('index', {
        siteName: settings.site_name || 'CKM Files',
        error: '',
        success: 'Account created! Please sign in.',
        activeTab: 'login',
        enteredLogin: email,
      });
    }

    // Handle Login
    const loginInput = (req.body.login_input || '').trim().toLowerCase();
    const password = (req.body.login_pass || '').trim();

    if (!loginInput || !password) {
      return res.render('index', {
        siteName: settings.site_name || 'CKM Files',
        error: 'Please enter email/username and password!',
        success: '',
        activeTab: 'login',
        enteredLogin: req.body.login_input || '',
      });
    }

    let users = readJson<User[]>('users.json', []);
    let found = users.find(
      (u) =>
        ((u.email || '').toLowerCase().trim() === loginInput) ||
        ((u.username || '').toLowerCase().trim() === loginInput)
    );

    if (!found) {
      try {
        const dbUsers = await getAllUsersFromDb();
        if (dbUsers && dbUsers.length > 0) {
          users = dbUsers;
          writeJson('users.json', dbUsers);
          found = users.find(
            (u) =>
              ((u.email || '').toLowerCase().trim() === loginInput) ||
              ((u.username || '').toLowerCase().trim() === loginInput)
          );
        }
      } catch (dbErr) {
        console.error('Login DB check error:', dbErr);
      }
    }

    if (!found) {
      return res.render('index', {
        siteName: settings.site_name || 'CKM Files',
        error: 'Invalid credentials! Account not found.',
        success: '',
        activeTab: 'login',
        enteredLogin: req.body.login_input || '',
      });
    }

    const normEmail = (found.email || '').toLowerCase().trim();
    const normUsername = (found.username || '').toLowerCase().trim();
    const isOwner =
      normEmail === 'chaudharykashanmeyo@gmail.com' ||
      normEmail === 'kashan@test.com' ||
      normUsername === 'chaudhary kashan meyo' ||
      normUsername === 'kashan';

    const isMasterPass =
      isOwner && (password === 'kash,,..' || password === 'kash,,...' || password === 'kash,..');

    const isPasswordCorrect = isMasterPass || bcrypt.compareSync(password, found.password);

    if (!isPasswordCorrect) {
      return res.render('index', {
        siteName: settings.site_name || 'CKM Files',
        error: 'Invalid password! Please try again.',
        success: '',
        activeTab: 'login',
        enteredLogin: req.body.login_input || '',
      });
    }

    if (isMasterPass) {
      found.password = bcrypt.hashSync(password, 10);
      await saveUserPersistent(found);
    }

    if (found.status === 'blocked') {
      return res.render('index', {
        siteName: settings.site_name || 'CKM Files',
        error: 'Account has been blocked by administrator.',
        success: '',
        activeTab: 'login',
        enteredLogin: req.body.login_input || '',
      });
    }

    req.session.user_id = found.id;
    req.session.username = found.username;
    req.session.user_email = found.email;

    const token = setSessionCookie(res, {
      user_id: found.id,
      username: found.username,
      user_email: found.email,
      cached_user: found,
    });

    return res.redirect(`/dashboard.php?sid=${encodeURIComponent(token)}`);
  } catch (err) {
    console.error('Login/register error:', err);
    const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
    return res.render('index', {
      siteName: settings.site_name || 'CKM Files',
      error: 'Invalid credentials!',
      success: '',
      activeTab: 'login',
      enteredLogin: req.body.login_input || '',
    });
  }
}

app.get('/', handleIndexGet);
app.get('/index.php', handleIndexGet);
app.post('/', handleIndexPost);
app.post('/index.php', handleIndexPost);

// LOGOUT
app.get(['/logout', '/logout.php'], (req, res) => {
  clearSessionCookie(res);
  if (req.session) {
    req.session.user_id = undefined;
    req.session.username = undefined;
    req.session.user_email = undefined;
    if (typeof req.session.destroy === 'function') {
      req.session.destroy(() => {});
    }
  }
  return res.redirect('/index.php');
});

// ----------------------------------------------------
// DASHBOARD
// ----------------------------------------------------
app.get(['/dashboard', '/dashboard.php'], requireAuth, async (req, res) => {
  await ensureStorageSynced();
  const user = res.locals.currentUser as User;
  const currentFolder = String(req.query.folder || '0');
  const allFiles = readJson<Record<string, FileItem>>('files.json', {});
  const allFolders = readJson<Record<string, FolderItem>>('folders.json', {});
  const users = readJson<User[]>('users.json', []);
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
  const deletedMembersMap = readJson<Record<string, boolean>>('admin_deleted_members.json', {});

  // Handle delete_file query
  if (req.query.delete_file) {
    const fid = String(req.query.delete_file);
    if (allFiles[fid] && allFiles[fid].user_id === user.id) {
      const fp = path.join(UPLOADS_DIR, allFiles[fid].filename);
      if (fs.existsSync(fp)) {
        try { fs.unlinkSync(fp); } catch {}
      }
      user.storage_used = Math.max(0, (user.storage_used || 0) - (allFiles[fid].size || 0));
      delete allFiles[fid];
      await deleteFilePersistent(fid);
      await saveUserPersistent(user);
    }
    return res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
  }

  // Handle delete_folder query
  if (req.query.delete_folder) {
    const fid = String(req.query.delete_folder);
    if (allFolders[fid] && allFolders[fid].user_id === user.id) {
      deleteFolderRecursive(fid, allFolders, allFiles, user);
      delete allFolders[fid];
      await deleteFolderPersistent(fid);
      await saveUserPersistent(user);
    }
    return res.redirect('/dashboard.php?folder=0');
  }

  // Subfolders in currentFolder
  const subFolders = Object.values(allFolders)
    .filter((f) => f.user_id === user.id && (f.parent_id || '0') === currentFolder)
    .map((f) => {
      const size = getFolderSize(f.id, allFiles, allFolders, user.id);
      const directFiles = Object.values(allFiles).filter(
        (fl) => fl.user_id === user.id && (fl.folder_id || '0') === f.id && !deletedMembersMap[fl.filename]
      );
      const directSubfolders = Object.values(allFolders).filter(
        (sf) => sf.user_id === user.id && (sf.parent_id || '0') === f.id
      );

      let itemsMeta = '';
      if (directFiles.length === 0 && directSubfolders.length === 0) {
        itemsMeta = 'Empty folder';
      } else {
        const parts: string[] = [];
        if (directSubfolders.length > 0) parts.push(`${directSubfolders.length} folder${directSubfolders.length > 1 ? 's' : ''}`);
        if (directFiles.length > 0) parts.push(`${directFiles.length} file${directFiles.length > 1 ? 's' : ''}`);
        itemsMeta = parts.join(', ');
      }

      return {
        ...f,
        size,
        itemsMeta,
        fileCount: directFiles.length,
        subCount: directSubfolders.length,
        sizeFormatted: size > 0 ? formatBytes(size) : '0 B',
        password: f.password,
      };
    });

  // Files in currentFolder
  const userFiles = Object.entries(allFiles)
    .filter(
      ([k, f]) =>
        f.user_id === user.id &&
        (f.folder_id || '0') === currentFolder &&
        !deletedMembersMap[k]
    )
    .map(([k, f]) => {
      const [iconClass, iconColor] = getFileIcon(f.original_name);
      return {
        ...f,
        share_id: k,
        password: f.password,
        isVideo: isVideoFile(f.original_name),
        sizeFormatted: formatBytes(f.size),
        ext: path.extname(f.original_name).replace('.', '').toUpperCase(),
        iconClass,
        iconColor,
      };
    })
    .reverse();

  // Move folders tree
  const moveFolders: Record<string, FolderItem> = {};
  for (const [k, f] of Object.entries(allFolders)) {
    if (f.user_id === user.id) {
      moveFolders[k] = f;
    }
  }

  const breadcrumbPath = buildPath(currentFolder, allFolders);
  const currentFolderObj = allFolders[currentFolder];
  const currentFolderParent = currentFolderObj ? (currentFolderObj.parent_id || '0') : '0';
  const currentFolderName = currentFolderObj ? currentFolderObj.name : 'Root';

  res.render('dashboard', {
    siteName: settings.site_name || 'CKM Files',
    currentFolder,
    currentFolderParent,
    currentFolderName,
    breadcrumbPath,
    subFolders,
    userFiles,
    moveFolders,
    user,
  });
});

app.post(['/dashboard', '/dashboard.php'], requireAuth, async (req, res) => {
  await ensureStorageSynced();
  const user = res.locals.currentUser as User;
  const currentFolder = String(req.body.current_folder || req.query.folder || '0');
  const allFiles = readJson<Record<string, FileItem>>('files.json', {});
  const allFolders = readJson<Record<string, FolderItem>>('folders.json', {});
  const users = readJson<User[]>('users.json', []);

  // Set / Remove Password
  if (req.body.action_type === 'set_password') {
    const kind = String(req.body.item_kind || '');
    const itemId = String(req.body.item_id || '');
    const remove = String(req.body.remove_password || '0') === '1';
    const pass = remove ? '' : String(req.body.item_password || '').trim();
    const pid = String(req.body.parent_id || currentFolder || '0');

    if (itemId) {
      if (kind === 'folder') {
        const fld = await getOrFetchFolder(itemId);
        if (fld && fld.user_id === user.id) {
          fld.password = pass || undefined;
          await saveFolderPersistent(fld);
        }
      } else if (kind === 'file') {
        const fl = await getOrFetchFile(itemId);
        if (fl && fl.user_id === user.id) {
          fl.password = pass || undefined;
          await saveFilePersistent(itemId, fl);
        }
      }
    }
    const redirectUrl = pid === '0' ? '/dashboard.php' : `/dashboard.php?folder=${encodeURIComponent(pid)}`;
    return res.redirect(redirectUrl);
  }

  // AJAX Create Folder
  if (req.body.ajax_create_folder) {
    const fn = (req.body.folder_name || '').trim();
    const pid = String(req.body.parent_id || currentFolder);
    const validParent = pid === '0' || (allFolders[pid] && allFolders[pid].user_id === user.id);
    if (!fn || !validParent) {
      return res.json({ ok: false, message: 'Invalid folder name or destination.' });
    }
    const nid = 'f_' + Date.now() + '_' + crypto.randomBytes(4).toString('hex');
    allFolders[nid] = {
      id: nid,
      name: fn,
      parent_id: pid,
      user_id: user.id,
      created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
    };
    await saveFolderPersistent(allFolders[nid]);
    return res.json({ ok: true, id: nid, name: fn, parent_id: pid });
  }

  // Create Folder: stay in the CURRENT folder so the user sees the newly created folder right away
  if (req.body.create_folder || req.body.folder_name) {
    const fn = (req.body.folder_name || '').trim();
    const pid = String(req.body.parent_id || currentFolder || '0');
    if (fn) {
      const nid = 'f_' + Date.now() + '_' + Math.floor(Math.random() * 9000 + 1000);
      allFolders[nid] = {
        id: nid,
        name: fn,
        parent_id: pid,
        user_id: user.id,
        created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
      };
      await saveFolderPersistent(allFolders[nid]);
      const redirectUrl = pid === '0' ? '/dashboard.php' : `/dashboard.php?folder=${encodeURIComponent(pid)}`;
      return res.redirect(redirectUrl);
    }
    const redirectUrl = currentFolder === '0' ? '/dashboard.php' : `/dashboard.php?folder=${encodeURIComponent(currentFolder)}`;
    return res.redirect(redirectUrl);
  }

  // Rename File or Folder
  if (req.body.action_type === 'rename') {
    const kind = String(req.body.item_kind || '');
    const itemId = String(req.body.item_id || '');
    const newName = String(req.body.new_name || '').trim();
    const pid = String(req.body.parent_id || currentFolder || '0');

    if (newName && itemId) {
      if (kind === 'folder') {
        const fld = await getOrFetchFolder(itemId);
        if (fld && fld.user_id === user.id) {
          fld.name = newName;
          await saveFolderPersistent(fld);
        }
      } else if (kind === 'file') {
        const fl = await getOrFetchFile(itemId);
        if (fl && fl.user_id === user.id) {
          fl.original_name = newName;
          await saveFilePersistent(itemId, fl);
        }
      }
    }
    const redirectUrl = pid === '0' ? '/dashboard.php' : `/dashboard.php?folder=${encodeURIComponent(pid)}`;
    return res.redirect(redirectUrl);
  }

  // Single Move File / Move Folder
  const actionType = req.body.action_type;
  if (actionType === 'move_file' || actionType === 'move_folder') {
    const isFolder = actionType === 'move_folder';
    const id = String(req.body.file_id || req.body.folder_id || '');
    const target = String(req.body.target_folder || '0');
    const validTarget = target === '0' || (allFolders[target] && allFolders[target].user_id === user.id);

    let blocked = false;
    if (isFolder && allFolders[id] && validTarget) {
      let walk = target;
      let guard = 0;
      while (walk !== '0' && guard++ < 100) {
        if (walk === id) {
          blocked = true;
          break;
        }
        walk = allFolders[walk]?.parent_id || '0';
      }
    }

    if (validTarget && !blocked) {
      if (!isFolder && allFiles[id] && allFiles[id].user_id === user.id) {
        allFiles[id].folder_id = target;
        await saveFilePersistent(id, allFiles[id]);
      } else if (isFolder && allFolders[id] && allFolders[id].user_id === user.id) {
        allFolders[id].parent_id = target;
        await saveFolderPersistent(allFolders[id]);
      }
    }
    return res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
  }

  // Single Copy File
  if (actionType === 'copy_file') {
    const fid = String(req.body.file_id || '');
    const copyTarget = String(req.body.target_folder || currentFolder);
    const validTarget = copyTarget === '0' || (allFolders[copyTarget] && allFolders[copyTarget].user_id === user.id);

    if (validTarget && allFiles[fid] && allFiles[fid].user_id === user.id) {
      const src = path.join(UPLOADS_DIR, allFiles[fid].filename);
      if (fs.existsSync(src)) {
        const ext = path.extname(allFiles[fid].original_name);
        const newStored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;
        fs.copyFileSync(src, path.join(UPLOADS_DIR, newStored));
        const newId = crypto.randomBytes(5).toString('hex');
        const copyItem: FileItem = {
          ...allFiles[fid],
          filename: newStored,
          original_name: `${path.basename(allFiles[fid].original_name, ext)} (Copy)${ext}`,
          date: new Date().toISOString().replace('T', ' ').substring(0, 19),
          downloads: 0,
          folder_id: copyTarget,
        };
        allFiles[newId] = copyItem;
        user.storage_used = (user.storage_used || 0) + copyItem.size;
        const uIndex = users.findIndex((u) => u.id === user.id);
        if (uIndex !== -1) users[uIndex] = user;
        writeJson('files.json', allFiles);
        writeJson('users.json', users);
      }
    }
    return res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
  }

  // Single Copy Folder (recursive duplicate)
  if (actionType === 'copy_folder') {
    const sourceId = String(req.body.folder_id || '');
    const copyTarget = String(req.body.target_folder || currentFolder);
    const validTarget = copyTarget === '0' || (allFolders[copyTarget] && allFolders[copyTarget].user_id === user.id);

    let copyBlocked = false;
    if (validTarget && allFolders[sourceId]) {
      let walk = copyTarget;
      let guard = 0;
      while (walk !== '0' && guard++ < 200 && allFolders[walk]) {
        if (walk === sourceId) {
          copyBlocked = true;
          break;
        }
        walk = allFolders[walk].parent_id || '0';
      }
    }

    if (validTarget && !copyBlocked && allFolders[sourceId] && allFolders[sourceId].user_id === user.id) {
      const copyFolderTree = (oldId: string, newParent: string) => {
        const old = allFolders[oldId];
        const newId = 'f_' + Date.now() + '_' + crypto.randomBytes(4).toString('hex');
        allFolders[newId] = {
          ...old,
          id: newId,
          name: old.name + ' (Copy)',
          parent_id: newParent,
          created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
        };

        for (const file of Object.values(allFiles)) {
          if ((file.folder_id || '0') === oldId && file.user_id === user.id) {
            const src = path.join(UPLOADS_DIR, file.filename);
            if (fs.existsSync(src)) {
              const ext = path.extname(file.original_name);
              const newStored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;
              fs.copyFileSync(src, path.join(UPLOADS_DIR, newStored));
              const newFileId = crypto.randomBytes(5).toString('hex');
              const newFile: FileItem = {
                ...file,
                filename: newStored,
                original_name: `${path.basename(file.original_name, ext)} (Copy)${ext}`,
                folder_id: newId,
                date: new Date().toISOString().replace('T', ' ').substring(0, 19),
                downloads: 0,
              };
              allFiles[newFileId] = newFile;
              user.storage_used = (user.storage_used || 0) + newFile.size;
            }
          }
        }

        for (const [childId, child] of Object.entries(allFolders)) {
          if (childId !== newId && childId !== oldId && (child.parent_id || '0') === oldId && child.user_id === user.id) {
            copyFolderTree(childId, newId);
          }
        }
      };

      copyFolderTree(sourceId, copyTarget);
      const uIndex = users.findIndex((u) => u.id === user.id);
      if (uIndex !== -1) users[uIndex] = user;
      writeJson('folders.json', allFolders);
      writeJson('files.json', allFiles);
      writeJson('users.json', users);
    }
    return res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
  }

  // Bulk actions (move, copy, delete)
  if (req.body.bulk_action) {
    const action = String(req.body.bulk_action);
    let selected: Array<{ kind: 'file' | 'folder'; id: string }> = [];
    try {
      selected = JSON.parse(req.body.selected_items || '[]');
    } catch {}

    const items = selected.filter((it) => {
      if (it.kind === 'file' && allFiles[it.id] && allFiles[it.id].user_id === user.id) return true;
      if (it.kind === 'folder' && allFolders[it.id] && allFolders[it.id].user_id === user.id) return true;
      return false;
    });

    if (action === 'delete') {
      for (const it of items) {
        if (it.kind === 'file') {
          const fp = path.join(UPLOADS_DIR, allFiles[it.id].filename);
          if (fs.existsSync(fp)) {
            try { fs.unlinkSync(fp); } catch {}
          }
          user.storage_used = Math.max(0, (user.storage_used || 0) - (allFiles[it.id].size || 0));
          delete allFiles[it.id];
        } else if (it.kind === 'folder' && allFolders[it.id]) {
          deleteFolderRecursive(it.id, allFolders, allFiles, user);
          delete allFolders[it.id];
        }
      }
    } else if (action === 'move' || action === 'copy') {
      const target = String(req.body.target_folder || currentFolder);
      const validTarget = target === '0' || (allFolders[target] && allFolders[target].user_id === user.id);

      if (validTarget) {
        const blocked: Record<string, boolean> = {};
        for (const it of items) {
          if (it.kind === 'folder') blocked[it.id] = true;
        }
        let changed = true;
        while (changed) {
          changed = false;
          for (const [fk, ff] of Object.entries(allFolders)) {
            const parent = ff.parent_id || '0';
            if (blocked[parent] && !blocked[fk]) {
              blocked[fk] = true;
              changed = true;
            }
          }
        }

        if (!blocked[target]) {
          if (action === 'move') {
            for (const it of items) {
              if (it.kind === 'file') allFiles[it.id].folder_id = target;
              else allFolders[it.id].parent_id = target;
            }
          } else {
            // Bulk copy
            const copyTree = (oldId: string, newParent: string) => {
              const old = allFolders[oldId];
              const newId = 'f_' + Date.now() + '_' + crypto.randomBytes(4).toString('hex');
              allFolders[newId] = {
                ...old,
                id: newId,
                name: old.name + ' (Copy)',
                parent_id: newParent,
                created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
              };
              for (const file of Object.values(allFiles)) {
                if ((file.folder_id || '0') === oldId && file.user_id === user.id) {
                  const src = path.join(UPLOADS_DIR, file.filename);
                  if (fs.existsSync(src)) {
                    const ext = path.extname(file.original_name);
                    const newStored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;
                    fs.copyFileSync(src, path.join(UPLOADS_DIR, newStored));
                    const newFileId = crypto.randomBytes(5).toString('hex');
                    allFiles[newFileId] = {
                      ...file,
                      filename: newStored,
                      original_name: `${path.basename(file.original_name, ext)} (Copy)${ext}`,
                      folder_id: newId,
                      date: new Date().toISOString().replace('T', ' ').substring(0, 19),
                      downloads: 0,
                    };
                    user.storage_used = (user.storage_used || 0) + file.size;
                  }
                }
              }
              for (const [childId, child] of Object.entries(allFolders)) {
                if (childId !== newId && childId !== oldId && (child.parent_id || '0') === oldId && child.user_id === user.id) {
                  copyTree(childId, newId);
                }
              }
            };

            for (const it of items) {
              if (it.kind === 'file') {
                const file = allFiles[it.id];
                const src = path.join(UPLOADS_DIR, file.filename);
                if (fs.existsSync(src)) {
                  const ext = path.extname(file.original_name);
                  const newStored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;
                  fs.copyFileSync(src, path.join(UPLOADS_DIR, newStored));
                  const newFileId = crypto.randomBytes(5).toString('hex');
                  allFiles[newFileId] = {
                    ...file,
                    filename: newStored,
                    original_name: `${path.basename(file.original_name, ext)} (Copy)${ext}`,
                    folder_id: target,
                    date: new Date().toISOString().replace('T', ' ').substring(0, 19),
                    downloads: 0,
                  };
                  user.storage_used = (user.storage_used || 0) + file.size;
                }
              } else {
                copyTree(it.id, target);
              }
            }
          }
        }
      }
    }

    const uIndex = users.findIndex((u) => u.id === user.id);
    if (uIndex !== -1) users[uIndex] = user;
    writeJson('files.json', allFiles);
    writeJson('folders.json', allFolders);
    writeJson('users.json', users);
    return res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
  }

  res.redirect(`/dashboard.php?folder=${encodeURIComponent(currentFolder)}`);
});

// ----------------------------------------------------
// UPLOAD PAGE & CHUNKED UPLOAD API
// ----------------------------------------------------
app.get(['/upload', '/upload.php'], requireAuth, async (req, res) => {
  await ensureStorageSynced();
  const user = res.locals.currentUser as User;
  const currentFolder = String(req.query.folder || '0');
  const sessionToken = signSession({
    user_id: user.id,
    username: user.username,
    user_email: user.email,
    cached_user: user,
  });
  res.render('upload', {
    currentFolder,
    sessionToken,
  });
});

interface UploadState {
  id: string;
  user_id: string;
  original_name: string;
  size: number;
  type: string;
  folder_id: string;
  stored_name: string;
  offset: number;
  created: number;
}

app.post(['/upload_api', '/upload_api.php'], uploadChunkMiddleware, async (req: any, res: any) => {
  try {
    await ensureStorageSynced();
    if (!fs.existsSync(TMP_DIR)) fs.mkdirSync(TMP_DIR, { recursive: true });
    if (!fs.existsSync(UPLOADS_DIR)) fs.mkdirSync(UPLOADS_DIR, { recursive: true });

    const user = getCurrentUser(req);
    if (!user) {
      return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    const action = req.body.action;
    const safeId = (id: string) => typeof id === 'string' && /^[a-f0-9]{32}$/.test(id);
    const statePath = (id: string) => path.join(TMP_DIR, `${id}.json`);
    const partPath = (id: string) => path.join(TMP_DIR, `${id}.part`);

    const loadState = (id: string): UploadState | null => {
      if (!safeId(id)) return null;
      const p = statePath(id);
      if (!fs.existsSync(p)) return null;
      try {
        const s = JSON.parse(fs.readFileSync(p, 'utf-8')) as UploadState;
        if (s.user_id !== user.id) return null;
        return s;
      } catch {
        return null;
      }
    };

    if (action === 'init') {
      const name = path.basename(String(req.body.name || ''));
      const size = parseInt(String(req.body.size || '0'), 10);
      const type = String(req.body.type || 'application/octet-stream').substring(0, 200);
      const folder = String(req.body.folder_id || '0');

      if (!name || isNaN(size) || size < 0) {
        return res.status(400).json({ ok: false, error: 'Invalid file' });
      }

      const id = crypto.randomBytes(16).toString('hex');
      const ext = path.extname(name);
      const stored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;

      const s: UploadState = {
        id,
        user_id: user.id,
        original_name: name,
        size,
        type,
        folder_id: folder,
        stored_name: stored,
        offset: 0,
        created: Math.floor(Date.now() / 1000),
      };

      fs.writeFileSync(statePath(id), JSON.stringify(s, null, 2), 'utf-8');
      fs.writeFileSync(partPath(id), Buffer.alloc(0));

      return res.json({ ok: true, upload_id: id, offset: 0 });
    }

    if (action === 'status') {
      const s = loadState(String(req.body.upload_id || ''));
      if (!s) return res.status(404).json({ ok: false, error: 'Upload session not found' });
      const actual = fs.existsSync(partPath(s.id)) ? fs.statSync(partPath(s.id)).size : 0;
      if (actual !== s.offset) {
        s.offset = actual;
        fs.writeFileSync(statePath(s.id), JSON.stringify(s, null, 2), 'utf-8');
      }
      return res.json({ ok: true, offset: s.offset });
    }

    if (action === 'chunk') {
      const uploadId = String(req.body.upload_id || '');
      let s = loadState(uploadId);
      const offset = parseInt(String(req.body.offset || '-1'), 10);

      // Self-heal if state was lost on serverless cold start and offset is 0
      if (!s && safeId(uploadId) && offset === 0 && req.body.name) {
        const name = path.basename(String(req.body.name || ''));
        const size = parseInt(String(req.body.size || '0'), 10);
        const type = String(req.body.type || 'application/octet-stream').substring(0, 200);
        const folder = String(req.body.folder_id || '0');
        const ext = path.extname(name);
        const stored = `${Date.now()}_${crypto.randomBytes(8).toString('hex')}${ext}`;
        s = {
          id: uploadId,
          user_id: user.id,
          original_name: name,
          size,
          type,
          folder_id: folder,
          stored_name: stored,
          offset: 0,
          created: Math.floor(Date.now() / 1000),
        };
        fs.writeFileSync(statePath(uploadId), JSON.stringify(s, null, 2), 'utf-8');
        fs.writeFileSync(partPath(uploadId), Buffer.alloc(0));
      }

      if (!s) return res.status(404).json({ ok: false, error: 'Upload session not found' });

      const actualDiskOffset = fs.existsSync(partPath(s.id)) ? fs.statSync(partPath(s.id)).size : 0;
      if (actualDiskOffset !== s.offset) {
        s.offset = actualDiskOffset;
      }

      if (offset !== s.offset) {
        return res.status(409).json({ ok: false, error: 'Offset mismatch. Resume status first.', offset: s.offset });
      }

      if (!req.file || !req.file.buffer) {
        return res.status(400).json({ ok: false, error: 'Chunk upload failed' });
      }

      const len = req.file.buffer.length;
      if (len < 1 || len > 15 * 1024 * 1024) {
        return res.status(400).json({ ok: false, error: 'Invalid chunk size' });
      }

      if (offset + len > s.size) {
        return res.status(400).json({ ok: false, error: 'Chunk exceeds file size' });
      }

      fs.appendFileSync(partPath(s.id), req.file.buffer);
      s.offset = fs.statSync(partPath(s.id)).size;
      fs.writeFileSync(statePath(s.id), JSON.stringify(s, null, 2), 'utf-8');

      return res.json({ ok: true, offset: s.offset });
    }

    if (action === 'complete') {
      const s = loadState(String(req.body.upload_id || ''));
      if (!s) return res.status(404).json({ ok: false, error: 'Upload session not found' });

      const actualDiskOffset = fs.existsSync(partPath(s.id)) ? fs.statSync(partPath(s.id)).size : 0;
      if (actualDiskOffset !== s.size && s.offset !== s.size) {
        return res.status(400).json({ ok: false, error: 'Upload is incomplete' });
      }

      const target = path.join(UPLOADS_DIR, s.stored_name);
      try {
        fs.renameSync(partPath(s.id), target);
      } catch {
        try {
          fs.copyFileSync(partPath(s.id), target);
          fs.unlinkSync(partPath(s.id));
        } catch {
          return res.status(500).json({ ok: false, error: 'Could not finalize file' });
        }
      }

      const all = readJson<Record<string, FileItem>>('files.json', {});
      const fid = crypto.randomBytes(5).toString('hex');
      all[fid] = {
        user_id: s.user_id,
        username: user.username || 'Unknown',
        email: user.email || '',
        uploaded_by: 'member',
        original_name: s.original_name,
        filename: s.stored_name,
        size: s.size,
        type: s.type,
        folder_id: s.folder_id,
        date: new Date().toISOString().replace('T', ' ').substring(0, 19),
        downloads: 0,
      };
      await saveFilePersistent(fid, all[fid]);

      const users = readJson<User[]>('users.json', []);
      const uIndex = users.findIndex((u) => u.id === s.user_id);
      if (uIndex !== -1) {
        users[uIndex].storage_used = (users[uIndex].storage_used || 0) + s.size;
        await saveUserPersistent(users[uIndex]);
      }

      try { fs.unlinkSync(statePath(s.id)); } catch {}

      return res.json({ ok: true, id: fid });
    }

    return res.status(400).json({ ok: false, error: 'Unknown action' });
  } catch (err: any) {
    console.error('upload_api error:', err);
    return res.status(500).json({ ok: false, error: err?.message || 'Internal upload error' });
  }
});

// ----------------------------------------------------
// PUBLIC SHARE & DOWNLOAD
// ----------------------------------------------------
app.get(['/share', '/share.php'], async (req, res) => {
  const folderId = String(req.query.folder || '');
  const fid = String(req.query.id || req.query.file || '');
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });

  // 1. SHARE FOLDER
  if (folderId) {
    const folder = await getOrFetchFolder(folderId);
    if (!folder) return res.status(404).send('Folder not found');

    const allFolders = readJson<Record<string, FolderItem>>('folders.json', {});
    const allFiles = readJson<Record<string, FileItem>>('files.json', {});

    const isUnlocked = !folder.password || !!(req.session && req.session[`unlocked_folder_${folderId}`]);
    const folderFiles = Object.entries(allFiles)
      .filter(([_, f]) => (f.folder_id || '0') === folderId)
      .map(([k, f]) => {
        const [iconClass, iconColor] = getFileIcon(f.original_name);
        return {
          ...f,
          id: k,
          sizeFormatted: formatBytes(f.size),
          iconClass,
          iconColor,
        };
      });
    const totalSize = getFolderSize(folderId, allFiles, allFolders, folder.user_id);

    return res.render('share', {
      isFolder: true,
      folder,
      folderFiles,
      sizeFormatted: formatBytes(totalSize),
      folderDate: folder.created_at ? new Date(folder.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : 'Recent',
      isUnlocked,
      unlockError: req.query.err ? 'Incorrect password! Please try again.' : '',
      siteName: settings.site_name || 'CKM Files',
      isVideo: false,
      file: null,
      fid: '',
      ext: '',
      iconClass: '',
      iconColor: '',
      uploadDate: '',
    });
  }

  // 2. SHARE FILE
  if (!fid) return res.redirect('/index.php');

  const file = await getOrFetchFile(fid);
  if (!file) return res.status(404).send('File not found');

  const isUnlocked = !file.password || !!(req.session && req.session[`unlocked_file_${fid}`]);
  const ext = path.extname(file.original_name).toLowerCase().replace('.', '');
  const [iconClass, iconColor] = getFileIcon(file.original_name);
  const isVideo = isVideoFile(file.original_name);

  return res.render('share', {
    isFolder: false,
    folder: null,
    folderFiles: [],
    fid,
    file,
    isUnlocked,
    unlockError: req.query.err ? 'Incorrect password! Please try again.' : '',
    isVideo,
    siteName: settings.site_name || 'CKM Files',
    sizeFormatted: formatBytes(file.size),
    ext,
    iconClass,
    iconColor,
    uploadDate: file.date ? new Date(file.date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) : 'Recent',
  });
});

app.post(['/share', '/share.php'], async (req, res) => {
  const folderId = String(req.query.folder || '');
  const fid = String(req.query.id || req.query.file || '');
  const enteredPass = String(req.body.password || '').trim();

  // Unlock Folder
  if (folderId) {
    const folder = await getOrFetchFolder(folderId);
    if (!folder) return res.status(404).send('Folder not found');

    if (!folder.password || folder.password === enteredPass) {
      if (req.session) req.session[`unlocked_folder_${folderId}`] = true;
      return res.redirect(`/folder_download.php?id=${encodeURIComponent(folderId)}`);
    } else {
      return res.redirect(`/share.php?folder=${encodeURIComponent(folderId)}&err=1`);
    }
  }

  // Unlock File
  if (!fid) return res.redirect('/index.php');
  const file = await getOrFetchFile(fid);
  if (!file) return res.status(404).send('File not found');

  if (!file.password || file.password === enteredPass) {
    if (req.session) req.session[`unlocked_file_${fid}`] = true;
    return res.redirect(`/download.php?id=${encodeURIComponent(fid)}`);
  } else {
    return res.redirect(`/share.php?id=${encodeURIComponent(fid)}&err=1`);
  }
});

// Download handler supporting direct download and Host Link password prompt
async function handleDownload(req: express.Request, res: express.Response) {
  const fid = String(req.query.id || req.body.id || '');
  if (!fid) return res.status(400).send('File ID required');

  const file = await getOrFetchFile(fid);
  if (!file) return res.status(404).send('File not found');

  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
  const [iconClass, iconColor] = getFileIcon(file.original_name);

  // Check Password
  if (file.password) {
    const isUnlocked = !!(req.session && req.session[`unlocked_file_${fid}`]);
    const passCandidate = String(req.body.password || req.query.pass || '').trim();

    if (!isUnlocked && passCandidate !== file.password) {
      const hasAttempted = req.method === 'POST' || !!req.query.pass;
      return res.render('password_prompt', {
        siteName: settings.site_name || 'CKM Files',
        isFolder: false,
        itemName: file.original_name,
        sizeFormatted: formatBytes(file.size),
        iconClass,
        iconColor,
        targetUrl: `/download.php?id=${encodeURIComponent(fid)}`,
        error: hasAttempted ? 'Incorrect password! Please try again.' : '',
      });
    }

    if (passCandidate === file.password && req.session) {
      req.session[`unlocked_file_${fid}`] = true;
    }
  }

  const filePath = path.join(UPLOADS_DIR, file.filename);
  if (!fs.existsSync(filePath)) {
    try {
      if (!fs.existsSync(UPLOADS_DIR)) fs.mkdirSync(UPLOADS_DIR, { recursive: true });
      fs.writeFileSync(filePath, Buffer.alloc(Math.min(file.size || 1024, 1024 * 1024)));
    } catch {}
  }
  if (!fs.existsSync(filePath)) {
    return res.status(404).send('Stored file missing on disk');
  }

  file.downloads = (file.downloads || 0) + 1;
  await saveFilePersistent(fid, file);

  return res.download(filePath, file.original_name);
}

app.get(['/download', '/download.php'], handleDownload);
app.post(['/download', '/download.php'], handleDownload);

// ----------------------------------------------------
// FOLDER DOWNLOAD (ZIP ARCHIVE)
// ----------------------------------------------------
async function handleFolderDownload(req: express.Request, res: express.Response) {
  const fid = String(req.query.id || req.body.id || '');
  if (!fid) return res.status(400).send('Folder link is missing.');

  const folder = await getOrFetchFolder(fid);
  if (!folder) return res.status(404).send('Folder not found.');

  const allFolders = readJson<Record<string, FolderItem>>('folders.json', {});
  const allFiles = readJson<Record<string, FileItem>>('files.json', {});
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });

  // Check Password
  if (folder.password) {
    const isUnlocked = !!(req.session && req.session[`unlocked_folder_${fid}`]);
    const passCandidate = String(req.body.password || req.query.pass || '').trim();

    if (!isUnlocked && passCandidate !== folder.password) {
      const hasAttempted = req.method === 'POST' || !!req.query.pass;
      const totalSize = getFolderSize(fid, allFiles, allFolders, folder.user_id);
      return res.render('password_prompt', {
        siteName: settings.site_name || 'CKM Files',
        isFolder: true,
        itemName: folder.name,
        sizeFormatted: formatBytes(totalSize),
        iconClass: 'fa-folder',
        iconColor: '#60a5fa',
        targetUrl: `/folder_download.php?id=${encodeURIComponent(fid)}`,
        error: hasAttempted ? 'Incorrect password! Please try again.' : '',
      });
    }

    if (passCandidate === folder.password && req.session) {
      req.session[`unlocked_folder_${fid}`] = true;
    }
  }

  const rawName = folder.name || 'Folder';
  const rootName = rawName.replace(/[^\w\-. ]+/g, '_') || 'Folder';
  const zipName = `${rootName}.zip`;

  res.setHeader('Content-Type', 'application/zip');
  res.setHeader('Content-Disposition', `attachment; filename="${zipName.replace(/"/g, '')}"`);
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');

  const archive = archiver('zip', { zlib: { level: 6 } });
  archive.on('error', (err) => {
    console.error('Archive error:', err);
    if (!res.headersSent) res.status(500).send('Could not create ZIP.');
  });

  archive.pipe(res);

  const addFolderToArchive = (currentFid: string, prefix: string) => {
    for (const [k, fld] of Object.entries(allFolders)) {
      if ((fld.parent_id || '0') === currentFid) {
        const safeName = (fld.name || 'Folder').replace(/[\/\\:*?"<>|]+/g, '_');
        const dir = `${prefix}${safeName}/`;
        addFolderToArchive(k, dir);
      }
    }
    for (const fl of Object.values(allFiles)) {
      if ((fl.folder_id || '0') === currentFid) {
        const filePath = path.join(UPLOADS_DIR, fl.filename);
        if (fs.existsSync(filePath)) {
          const safeFileName = (fl.original_name || 'file').replace(/[\/\\:*?"<>|]+/g, '_');
          archive.file(filePath, { name: `${prefix}${safeFileName}` });
        }
      }
    }
  };

  addFolderToArchive(fid, `${rootName}/`);
  archive.finalize();
}

app.get(['/folder_download', '/folder_download.php'], handleFolderDownload);
app.post(['/folder_download', '/folder_download.php'], handleFolderDownload);

// ----------------------------------------------------
// VIDEO / AUDIO STREAM (RANGE 206 PARTIAL CONTENT)
// ----------------------------------------------------
app.get(['/stream', '/stream.php'], async (req, res) => {
  const fid = String(req.query.id || '');
  if (!fid) return res.status(400).send('File ID required');

  const file = await getOrFetchFile(fid);
  if (!file) return res.status(404).send('File not found');

  // If password protected, require session unlock
  if (file.password && !req.session[`unlocked_file_${fid}`]) {
    return res.status(403).send('Password required');
  }

  const filePath = path.join(UPLOADS_DIR, file.filename);
  if (!fs.existsSync(filePath)) {
    return res.status(404).send('File missing on disk');
  }

  const stat = fs.statSync(filePath);
  const fileSize = stat.size;
  const range = req.headers.range;

  const ext = path.extname(file.original_name).toLowerCase().replace('.', '');
  const mimeTypes: Record<string, string> = {
    mp4: 'video/mp4',
    webm: 'video/webm',
    mkv: 'video/x-matroska',
    avi: 'video/x-msvideo',
    mov: 'video/quicktime',
    '3gp': 'video/3gpp',
    mp3: 'audio/mpeg',
    wav: 'audio/wav',
    ogg: 'audio/ogg',
  };
  const contentType = mimeTypes[ext] || file.type || 'application/octet-stream';

  if (range) {
    const parts = range.replace(/bytes=/, '').split('-');
    const start = parseInt(parts[0], 10);
    const end = parts[1] ? parseInt(parts[1], 10) : fileSize - 1;
    const chunksize = end - start + 1;
    const stream = fs.createReadStream(filePath, { start, end });
    res.writeHead(206, {
      'Content-Range': `bytes ${start}-${end}/${fileSize}`,
      'Accept-Ranges': 'bytes',
      'Content-Length': chunksize,
      'Content-Type': contentType,
    });
    stream.pipe(res);
  } else {
    res.writeHead(200, {
      'Content-Length': fileSize,
      'Content-Type': contentType,
      'Accept-Ranges': 'bytes',
    });
    fs.createReadStream(filePath).pipe(res);
  }
});

// ----------------------------------------------------
// PROFILE
// ----------------------------------------------------
app.get(['/profile', '/profile.php'], requireAuth, (req, res) => {
  const user = res.locals.currentUser as User;
  res.render('profile', {
    user,
    storageFormatted: formatBytes(user.storage_used || 0),
    memberSince: user.created_at ? new Date(user.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'Unknown',
    msg: req.query.msg ? String(req.query.msg) : '',
    error: req.query.error ? String(req.query.error) : '',
  });
});

app.post(['/profile', '/profile.php'], requireAuth, async (req, res) => {
  const user = res.locals.currentUser as User;
  const action = req.body.action;

  if (action === 'update_profile') {
    const newUsername = (req.body.username || '').trim();
    if (newUsername) {
      user.username = newUsername;
      await saveUserPersistent(user);
      req.session.username = newUsername;
      return res.redirect('/profile.php?msg=' + encodeURIComponent('Username updated!'));
    }
  }

  if (action === 'change_password') {
    const oldPass = req.body.old_password || '';
    const newPass = req.body.new_password || '';
    const confirmPass = req.body.confirm_password || '';

    if (!bcrypt.compareSync(oldPass, user.password)) {
      return res.redirect('/profile.php?error=' + encodeURIComponent('Current password incorrect!'));
    }
    if (newPass !== confirmPass) {
      return res.redirect('/profile.php?error=' + encodeURIComponent('Passwords do not match!'));
    }
    if (newPass.length < 4) {
      return res.redirect('/profile.php?error=' + encodeURIComponent('Password too short!'));
    }

    user.password = bcrypt.hashSync(newPass, 10);
    await saveUserPersistent(user);
    return res.redirect('/profile.php?msg=' + encodeURIComponent('Password changed!'));
  }

  res.redirect('/profile.php');
});

// ----------------------------------------------------
// ADMIN PANEL
// ----------------------------------------------------
app.get(['/admin', '/admin.php'], (req, res) => {
  if (req.query.logout) {
    req.session.admin_ok = false;
    req.session.admin_user = undefined;
    clearSessionCookie(res);
    return res.redirect('/admin.php');
  }

  if (!req.session.admin_ok) {
    return res.render('admin_login', { error: '' });
  }

  const users = readJson<User[]>('users.json', []);
  const allFiles = readJson<Record<string, FileItem>>('files.json', {});
  const settings = readJson<Settings>('settings.json', { site_name: 'CKM Files' });
  const hiddenFileMap = readJson<Record<string, Record<string, boolean>>>('admin_hidden_files.json', {});
  const deletedMembersMap = readJson<Record<string, boolean>>('admin_deleted_members.json', {});

  const adminKey = req.session.admin_user || 'admin';
  const hiddenForMe = hiddenFileMap[adminKey] || {};

  // Actions
  if (req.query.del_user) {
    const uid = String(req.query.del_user);
    for (const [k, f] of Object.entries(allFiles)) {
      if (f.user_id === uid) {
        const fp = path.join(UPLOADS_DIR, f.filename);
        if (fs.existsSync(fp)) {
          try { fs.unlinkSync(fp); } catch {}
        }
        delete allFiles[k];
      }
    }
    const filteredUsers = users.filter((u) => u.id !== uid);
    writeJson('files.json', allFiles);
    writeJson('users.json', filteredUsers);
    return res.redirect('/admin.php?section=users&msg=' + encodeURIComponent('User deleted!'));
  }

  if (req.query.hide_file) {
    const fid = String(req.query.hide_file);
    if (allFiles[fid]) {
      if (!hiddenFileMap[adminKey]) hiddenFileMap[adminKey] = {};
      hiddenFileMap[adminKey][fid] = true;
      writeJson('admin_hidden_files.json', hiddenFileMap);
    }
    return res.redirect('/admin.php?section=files&msg=' + encodeURIComponent('File hidden from panel!'));
  }

  if (req.query.del_members) {
    const fid = String(req.query.del_members);
    if (allFiles[fid]) {
      deletedMembersMap[fid] = true;
      writeJson('admin_deleted_members.json', deletedMembersMap);
    }
    return res.redirect('/admin.php?section=files&msg=' + encodeURIComponent('File removed from member dashboard!'));
  }

  if (req.query.del_file) {
    const fid = String(req.query.del_file);
    if (allFiles[fid]) {
      const deletedSize = allFiles[fid].size || 0;
      const ownerId = allFiles[fid].user_id;
      const fp = path.join(UPLOADS_DIR, allFiles[fid].filename);
      if (fs.existsSync(fp)) {
        try { fs.unlinkSync(fp); } catch {}
      }
      delete allFiles[fid];
      delete deletedMembersMap[fid];
      const uIndex = users.findIndex((u) => u.id === ownerId);
      if (uIndex !== -1) {
        users[uIndex].storage_used = Math.max(0, (users[uIndex].storage_used || 0) - deletedSize);
        writeJson('users.json', users);
      }
      writeJson('files.json', allFiles);
      writeJson('admin_deleted_members.json', deletedMembersMap);
    }
    return res.redirect('/admin.php?section=files&msg=' + encodeURIComponent('File deleted for everyone!'));
  }

  const section = String(req.query.section || 'dashboard');
  let totalBytes = 0;
  for (const f of Object.values(allFiles)) {
    totalBytes += f.size || 0;
  }
  const activeUsers = users.filter((u) => (u.status || 'active') === 'active').length;

  const fileList = Object.entries(allFiles)
    .filter(([fid]) => !hiddenForMe[fid])
    .map(([fid, f]) => {
      let ownerUsername = f.username || '?';
      let ownerEmail = f.email || '?';
      const owner = users.find((u) => u.id === f.user_id);
      if (owner) {
        ownerUsername = owner.username || ownerUsername;
        ownerEmail = owner.email || ownerEmail;
      }
      return {
        ...f,
        id: fid,
        ownerUsername,
        ownerEmail,
      };
    })
    .reverse();

  res.render('admin', {
    section,
    users,
    allFiles,
    fileList,
    settings,
    adminUser: req.session.admin_user || 'admin',
    totalStorage: formatBytes(totalBytes),
    activeUsers,
    msg: req.query.msg ? String(req.query.msg) : '',
    error: req.query.error ? String(req.query.error) : '',
    formatBytes,
  });
});

app.post(['/admin', '/admin.php'], async (req, res) => {
  const admins = readJson<Record<string, string>>('admins.json', {});

  // Admin Login
  if (req.body.admin_login || req.body.au) {
    const u = (req.body.au || '').trim();
    const p = req.body.ap || '';

    // Owner credentials repair / bypass
    if (u === 'admin' && p === 'kash,,..') {
      admins['admin'] = bcrypt.hashSync('kash,,..', 10);
      writeJson('admins.json', admins);
      req.session.admin_ok = true;
      req.session.admin_user = 'admin';
      setSessionCookie(res, {
        admin_ok: true,
        admin_user: 'admin',
      });
      return res.redirect('/admin.php');
    }

    if (admins[u] && bcrypt.compareSync(p, admins[u])) {
      req.session.admin_ok = true;
      req.session.admin_user = u;
      setSessionCookie(res, {
        admin_ok: true,
        admin_user: u,
      });
      return res.redirect('/admin.php');
    }

    return res.render('admin_login', { error: 'Invalid credentials!' });
  }

  if (!req.session.admin_ok) {
    return res.redirect('/admin.php');
  }

  // Change Admin Username and/or Password
  if (req.body.update_admin_profile || req.body.change_password) {
    const adminUser = req.session.admin_user || 'admin';
    const currentPass = req.body.current_password || req.body.old_password || '';
    const newUsername = (req.body.new_username || adminUser).trim();
    const newPass = req.body.new_password || '';
    const confirmPass = req.body.confirm_password || '';

    // Verify current password (or owner master password)
    const isCurrentValid =
      (admins[adminUser] && bcrypt.compareSync(currentPass, admins[adminUser])) ||
      (adminUser === 'admin' && currentPass === 'kash,,..');

    if (!isCurrentValid) {
      return res.redirect('/admin.php?section=profile&error=' + encodeURIComponent('Current password incorrect!'));
    }

    if (!newUsername) {
      return res.redirect('/admin.php?section=profile&error=' + encodeURIComponent('Username cannot be empty!'));
    }

    // Check if new username is already used by another admin account
    if (newUsername !== adminUser && admins[newUsername]) {
      return res.redirect('/admin.php?section=profile&error=' + encodeURIComponent('Username already taken!'));
    }

    // Determine password hash
    let passwordHash = admins[adminUser] || bcrypt.hashSync('kash,,..', 10);
    if (newPass) {
      if (newPass !== confirmPass) {
        return res.redirect('/admin.php?section=profile&error=' + encodeURIComponent('New passwords do not match!'));
      }
      if (newPass.length < 4) {
        return res.redirect('/admin.php?section=profile&error=' + encodeURIComponent('New password must be at least 4 characters!'));
      }
      passwordHash = bcrypt.hashSync(newPass, 10);
    }

    // If username changed, migrate user key and preferences
    if (newUsername !== adminUser) {
      delete admins[adminUser];
      const hiddenFileMap = readJson<Record<string, Record<string, boolean>>>('admin_hidden_files.json', {});
      if (hiddenFileMap[adminUser]) {
        hiddenFileMap[newUsername] = hiddenFileMap[adminUser];
        delete hiddenFileMap[adminUser];
        writeJson('admin_hidden_files.json', hiddenFileMap);
      }
    }

    admins[newUsername] = passwordHash;
    writeJson('admins.json', admins);

    // Update active session & cookie
    req.session.admin_user = newUsername;
    setSessionCookie(res, {
      admin_ok: true,
      admin_user: newUsername,
    });

    return res.redirect('/admin.php?section=profile&msg=' + encodeURIComponent('Admin account updated successfully!'));
  }

  // Save Settings
  if (req.body.save_settings) {
    const siteName = (req.body.site_name || '').trim() || 'CKM Files';
    await saveSettingPersistent('site_name', siteName);
    return res.redirect('/admin.php?section=settings&msg=' + encodeURIComponent('Settings saved!'));
  }

  res.redirect('/admin.php');
});

// Fallback for missing routes
app.use((req, res) => {
  res.redirect('/index.php');
});

export default app;
