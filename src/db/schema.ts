import { pgTable, text, integer, serial, bigint } from 'drizzle-orm/pg-core';

export const users = pgTable('users', {
  id: text('id').primaryKey(),
  username: text('username').notNull(),
  email: text('email').notNull().unique(),
  password: text('password').notNull(),
  storageUsed: bigint('storage_used', { mode: 'number' }).default(0).notNull(),
  createdAt: text('created_at').notNull(),
  status: text('status').default('active').notNull(),
});

export const folders = pgTable('folders', {
  id: text('id').primaryKey(),
  name: text('name').notNull(),
  parentId: text('parent_id').default('0').notNull(),
  userId: text('user_id').notNull(),
  password: text('password'),
  createdAt: text('created_at').notNull(),
});

export const files = pgTable('files', {
  id: text('id').primaryKey(),
  userId: text('user_id').notNull(),
  username: text('username').notNull(),
  email: text('email').notNull(),
  uploadedBy: text('uploaded_by').notNull(),
  originalName: text('original_name').notNull(),
  filename: text('filename').notNull(),
  size: bigint('size', { mode: 'number' }).default(0).notNull(),
  type: text('type').notNull(),
  folderId: text('folder_id').default('0').notNull(),
  date: text('date').notNull(),
  downloads: integer('downloads').default(0).notNull(),
  password: text('password'),
});

export const settings = pgTable('settings', {
  key: text('key').primaryKey(),
  value: text('value').notNull(),
});

export const admins = pgTable('admins', {
  username: text('username').primaryKey(),
  passwordHash: text('password_hash').notNull(),
});

export const adminHiddenFiles = pgTable('admin_hidden_files', {
  id: serial('id').primaryKey(),
  userId: text('user_id').notNull(),
  fileId: text('file_id').notNull(),
});

export const adminDeletedMembers = pgTable('admin_deleted_members', {
  userId: text('user_id').primaryKey(),
});
