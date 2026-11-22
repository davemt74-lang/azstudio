"""Exercise the actual v1.20 model_videos CREATE TABLE SQL in portable SQLite.

PHP's database runtime is tested separately on hosts with PDO drivers installed.
"""
from pathlib import Path
import re, sqlite3, unittest

ROOT=Path(__file__).resolve().parents[1]

class ModelVideoSqlSmoke(unittest.TestCase):
    def setUp(self):
        self.db=sqlite3.connect(':memory:')
        self.db.execute('PRAGMA foreign_keys=ON')
        self.db.executescript('''
            CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT,status TEXT);
            CREATE TABLE user_roles (user_id INTEGER,role TEXT);
            CREATE TABLE personal_media (id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,mime_type TEXT,duration_ms INTEGER,size_bytes INTEGER,file_name TEXT,storage_name TEXT,cover_storage_name TEXT,cover_mime_type TEXT,revision INTEGER);
            CREATE TABLE model_profiles (linked_user_id INTEGER,stage_name TEXT,status TEXT);
        ''')
        source=(ROOT/'includes/model-videos.php').read_text()
        match=re.search(r'\$db->exec\("CREATE TABLE IF NOT EXISTS model_videos \((.*?)\)\$suffix"\);', source, re.S)
        self.assertIsNotNone(match,'Must test the actual migration SQL')
        sql='CREATE TABLE IF NOT EXISTS model_videos (' + match.group(1).replace('$owner','INTEGER') + ')'
        self.db.execute(sql)
        self.db.execute('CREATE INDEX IF NOT EXISTS model_video_review ON model_videos (status, submitted_at)')
        self.db.execute('INSERT INTO users VALUES(1,?,?)',('Model One','active'))
        self.db.execute('INSERT INTO user_roles VALUES(?,?)',(1,'model'))
        self.db.execute('INSERT INTO personal_media VALUES(?,?,?,?,?,?,?,?,?,?)',('b'*32,1,'video/mp4',35000,12420,'private.mp4','secured.bin',None,None,1))
        self.db.execute('INSERT INTO model_videos (id,user_id,media_id,title,description,status,review_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',('a'*32,1,'b'*32,'Studio diary','Approved content','draft','','2026-11-21','2026-11-21'))

    def test_owner_media_unique_and_schema(self):
        cols={r[1] for r in self.db.execute('PRAGMA table_info(model_videos)')}
        self.assertTrue({'rights_confirmed','status','published_at','reviewed_by','revision','review_note'}<=cols)
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute('INSERT INTO model_videos (id,user_id,media_id,title,description,review_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',('c'*32,1,'b'*32,'Again','','','2026-11-21','2026-11-21'))
        with self.assertRaises(sqlite3.IntegrityError):
            self.db.execute('INSERT INTO model_videos (id,user_id,media_id,title,description,review_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',('c'*32,1,'d'*32,'Missing','','','2026-11-21','2026-11-21'))

    def public_rows(self):
        return self.db.execute("""SELECT p.id,p.title FROM model_videos p
            JOIN personal_media m ON m.id=p.media_id AND m.user_id=p.user_id
            JOIN users u ON u.id=p.user_id
            WHERE p.status='published' AND p.published_at IS NOT NULL AND p.rights_confirmed=1 AND u.status='active'
              AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=p.user_id AND ur.role='model')""").fetchall()

    def test_draft_requires_submission_consent_approval_and_active_model(self):
        self.assertEqual(self.public_rows(),[])
        self.db.execute("UPDATE model_videos SET status='pending_review',rights_confirmed=1,submitted_at='2026-11-21' WHERE id=?",('a'*32,))
        self.assertEqual(self.public_rows(),[])
        self.db.execute("UPDATE model_videos SET status='published',published_at='2026-11-21' WHERE id=?",('a'*32,))
        self.assertEqual(self.public_rows(),[('a'*32,'Studio diary')])
        self.db.execute("UPDATE users SET status='disabled' WHERE id=1")
        self.assertEqual(self.public_rows(),[])
        self.db.execute("UPDATE users SET status='active' WHERE id=1")
        self.db.execute("DELETE FROM user_roles WHERE user_id=1 AND role='model'")
        self.assertEqual(self.public_rows(),[])

    def test_deleting_gallery_source_removes_post_and_never_publicizes(self):
        self.db.execute('DELETE FROM personal_media WHERE id=?',('b'*32,))
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM model_videos').fetchone()[0],0)

if __name__=='__main__':unittest.main()
