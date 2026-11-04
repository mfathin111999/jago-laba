<?php
final class SchemaManager {
    public static function createDatabase(): void {
        $c = Database::config()['db'];
        $pdo = Database::serverPdo();
        $db = str_replace('`','', $c['name']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        Database::reset();
    }

    public static function ensureSystemSchema(PDO $pdo): void {
        $sql = [
            "CREATE TABLE IF NOT EXISTS system_users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_uuid VARCHAR(80) NULL,
                username VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(190) NULL,
                email VARCHAR(190) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role_id VARCHAR(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN',
                status VARCHAR(32) NOT NULL DEFAULT 'Aktif',
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_users_status(status), INDEX idx_users_role(role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS auth_users_v2 (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role_id VARCHAR(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN',
                status VARCHAR(32) NOT NULL DEFAULT 'Aktif',
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_auth_v2_status(status), INDEX idx_auth_v2_role(role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(190) PRIMARY KEY,
                setting_value LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS system_table_map (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sheet_name VARCHAR(190) NOT NULL UNIQUE,
                api_key VARCHAR(190) NOT NULL UNIQUE,
                table_name VARCHAR(190) NOT NULL UNIQUE,
                headers_json LONGTEXT NULL,
                source_row_count INT NOT NULL DEFAULT 0,
                imported_row_count INT NOT NULL DEFAULT 0,
                checksum VARCHAR(128) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS system_audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id VARCHAR(100) NOT NULL,
                event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id VARCHAR(100) NULL,
                username VARCHAR(120) NULL,
                module VARCHAR(100) NULL,
                action VARCHAR(120) NOT NULL,
                record_id VARCHAR(190) NULL,
                reference_id VARCHAR(190) NULL,
                before_json LONGTEXT NULL,
                after_json LONGTEXT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'SUCCESS',
                notes TEXT NULL,
                INDEX idx_audit_time(event_time), INDEX idx_audit_action(action), INDEX idx_audit_ref(reference_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS backup_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                backup_id VARCHAR(120) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                mode VARCHAR(40) NOT NULL,
                status VARCHAR(32) NOT NULL,
                sql_file LONGTEXT NULL,
                snapshot_file LONGTEXT NULL,
                manifest_file LONGTEXT NULL,
                total_rows INT NOT NULL DEFAULT 0,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                checksum VARCHAR(128) NULL,
                message TEXT NULL,
                INDEX idx_backup_time(created_at), INDEX idx_backup_mode(mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS migration_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration_id VARCHAR(120) NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                source_name VARCHAR(190) NULL,
                target_name VARCHAR(190) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'RUNNING',
                details_json LONGTEXT NULL,
                UNIQUE KEY uq_migration_id(migration_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS document_files (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_id VARCHAR(120) NOT NULL UNIQUE,
                document_type VARCHAR(120) NULL,
                reference_id VARCHAR(190) NULL,
                file_name VARCHAR(255) NOT NULL,
                relative_path LONGTEXT NOT NULL,
                mime_type VARCHAR(120) NULL,
                size_bytes BIGINT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_doc_ref(reference_id), INDEX idx_doc_type(document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS idempotency_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                company_key VARCHAR(120) NULL,
                idempotency_key VARCHAR(255) NOT NULL,
                module VARCHAR(100) NULL,
                reference_id VARCHAR(190) NULL,
                response_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_idempotency_scope(company_key,module,idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        foreach ($sql as $q) $pdo->exec($q);

        // IMPORTANT: CREATE TABLE IF NOT EXISTS does not upgrade an older table.
        // These repair methods make the installer safe over Stage-1/Stage-2 databases.
        self::repairUserSchema($pdo);
        self::repairBackupSchema($pdo);
        self::repairAuditSchema($pdo);
        self::repairSettingsSchema($pdo);
        self::repairIdempotencySchema($pdo);
    }

    private static function columns(PDO $pdo, string $table): array {
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $r) {
            $cols[(string)$r['Field']] = $r;
        }
        return $cols;
    }

    private static function addColumn(PDO $pdo, string $table, array &$cols, string $name, string $definition): void {
        if (!isset($cols[$name])) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
            $cols = self::columns($pdo, $table);
        }
    }

    public static function repairUserSchema(PDO $pdo): void {
        $cols = self::columns($pdo, 'system_users');
        if (!isset($cols['id']) || !isset($cols['username'])) {
            throw new RuntimeException('Schema system_users lama tidak kompatibel: kolom id/username tidak ditemukan.');
        }

        self::addColumn($pdo,'system_users',$cols,'user_uuid','VARCHAR(80) NULL');
        self::addColumn($pdo,'system_users',$cols,'name','VARCHAR(190) NULL');
        self::addColumn($pdo,'system_users',$cols,'email','VARCHAR(190) NULL');
        self::addColumn($pdo,'system_users',$cols,'password_hash','VARCHAR(255) NULL');
        self::addColumn($pdo,'system_users',$cols,'role_id',"VARCHAR(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN'");
        self::addColumn($pdo,'system_users',$cols,'status',"VARCHAR(32) NOT NULL DEFAULT 'Aktif'");
        self::addColumn($pdo,'system_users',$cols,'must_change_password','TINYINT(1) NOT NULL DEFAULT 0');
        self::addColumn($pdo,'system_users',$cols,'last_login_at','DATETIME NULL');
        self::addColumn($pdo,'system_users',$cols,'created_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        self::addColumn($pdo,'system_users',$cols,'updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

        $cols = self::columns($pdo, 'system_users');
        if (isset($cols['nama'])) {
            $pdo->exec("UPDATE system_users SET name = nama WHERE (name IS NULL OR name='') AND nama IS NOT NULL");
        }
        if (isset($cols['password']) && isset($cols['password_hash'])) {
            // Do not copy plaintext into password_hash. Owner/legacy migration will hash it safely.
        }

        // Backfill a deterministic internal UUID for legacy rows without changing their numeric ID.
        $rows = $pdo->query("SELECT id,username,user_uuid FROM system_users")->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("UPDATE system_users SET user_uuid=? WHERE id=?");
        foreach ($rows as $r) {
            if (trim((string)($r['user_uuid'] ?? '')) === '') {
                $uuid = 'USR-' . strtoupper(substr(hash('sha256', (string)$r['id'].'|'.(string)$r['username']), 0, 12));
                $up->execute([$uuid, $r['id']]);
            }
        }
    }

    public static function repairBackupSchema(PDO $pdo): void {
        $cols = self::columns($pdo, 'backup_log');
        self::addColumn($pdo,'backup_log',$cols,'sql_file','LONGTEXT NULL');
        self::addColumn($pdo,'backup_log',$cols,'snapshot_file','LONGTEXT NULL');
        self::addColumn($pdo,'backup_log',$cols,'manifest_file','LONGTEXT NULL');
        self::addColumn($pdo,'backup_log',$cols,'total_rows','INT NOT NULL DEFAULT 0');
        self::addColumn($pdo,'backup_log',$cols,'size_bytes','BIGINT NOT NULL DEFAULT 0');
        self::addColumn($pdo,'backup_log',$cols,'checksum','VARCHAR(128) NULL');
        self::addColumn($pdo,'backup_log',$cols,'message','TEXT NULL');
        $cols = self::columns($pdo, 'backup_log');
        if (isset($cols['file_path'])) {
            $pdo->exec("UPDATE backup_log SET sql_file=file_path WHERE (sql_file IS NULL OR sql_file='') AND file_path IS NOT NULL");
        }
        if (isset($cols['file_size'])) {
            $pdo->exec("UPDATE backup_log SET size_bytes=file_size WHERE size_bytes=0 AND file_size IS NOT NULL");
        }
    }

    public static function repairAuditSchema(PDO $pdo): void {
        $cols = self::columns($pdo, 'system_audit_logs');
        self::addColumn($pdo,'system_audit_logs',$cols,'event_id','VARCHAR(100) NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'event_time','DATETIME NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'user_id','VARCHAR(100) NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'username','VARCHAR(120) NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'record_id','VARCHAR(190) NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'before_json','LONGTEXT NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'after_json','LONGTEXT NULL');
        self::addColumn($pdo,'system_audit_logs',$cols,'status',"VARCHAR(32) NOT NULL DEFAULT 'SUCCESS'");
        self::addColumn($pdo,'system_audit_logs',$cols,'notes','TEXT NULL');
        $cols = self::columns($pdo, 'system_audit_logs');
        if (isset($cols['created_at'])) $pdo->exec("UPDATE system_audit_logs SET event_time=created_at WHERE event_time IS NULL");
        if (isset($cols['actor'])) $pdo->exec("UPDATE system_audit_logs SET username=actor WHERE (username IS NULL OR username='') AND actor IS NOT NULL");
        if (isset($cols['payload_json'])) $pdo->exec("UPDATE system_audit_logs SET after_json=payload_json WHERE after_json IS NULL AND payload_json IS NOT NULL");
        $pdo->exec("UPDATE system_audit_logs SET event_id=CONCAT('LEGACY-AUD-',id) WHERE event_id IS NULL OR event_id=''");
        $pdo->exec("UPDATE system_audit_logs SET event_time=NOW() WHERE event_time IS NULL");
    }

    public static function repairSettingsSchema(PDO $pdo): void {
        $cols = self::columns($pdo, 'system_settings');
        self::addColumn($pdo,'system_settings',$cols,'setting_value','LONGTEXT NULL');
        self::addColumn($pdo,'system_settings',$cols,'updated_at','DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    public static function repairIdempotencySchema(PDO $pdo): void {
        $cols = self::columns($pdo, 'idempotency_keys');
        if (!isset($cols['idempotency_key'])) {
            throw new RuntimeException('Schema idempotency_keys lama tidak kompatibel: kolom idempotency_key tidak ditemukan.');
        }

        self::addColumn($pdo, 'idempotency_keys', $cols, 'company_key', 'VARCHAR(120) NULL');
        self::addColumn($pdo, 'idempotency_keys', $cols, 'module', 'VARCHAR(100) NULL');

        // Existing rows predate company scope. Keep them isolated from new
        // receipts instead of guessing which company owns the old request.
        $pdo->exec("UPDATE idempotency_keys SET company_key='LEGACY-GLOBAL' WHERE company_key IS NULL OR company_key=''");
        $pdo->exec("UPDATE idempotency_keys SET module='legacy' WHERE module IS NULL OR module=''");

        $indexes = [];
        foreach ($pdo->query("SHOW INDEX FROM `idempotency_keys`") as $row) {
            $name = (string)($row['Key_name'] ?? '');
            if ($name === '') continue;
            $indexes[$name]['unique'] = (int)($row['Non_unique'] ?? 1) === 0;
            $indexes[$name]['columns'][(int)($row['Seq_in_index'] ?? 0)] = (string)($row['Column_name'] ?? '');
        }
        foreach ($indexes as $name => $index) {
            ksort($index['columns']);
            if ($index['unique'] && array_values($index['columns']) === ['idempotency_key'] && strtoupper($name) !== 'PRIMARY') {
                $pdo->exec("ALTER TABLE `idempotency_keys` DROP INDEX `" . str_replace('`', '', $name) . "`");
            }
        }

        $indexes = [];
        foreach ($pdo->query("SHOW INDEX FROM `idempotency_keys`") as $row) {
            $name = (string)($row['Key_name'] ?? '');
            if ($name === '') continue;
            $indexes[$name]['unique'] = (int)($row['Non_unique'] ?? 1) === 0;
            $indexes[$name]['columns'][(int)($row['Seq_in_index'] ?? 0)] = (string)($row['Column_name'] ?? '');
        }
        $scoped = false;
        foreach ($indexes as $index) {
            ksort($index['columns']);
            if ($index['unique'] && array_values($index['columns']) === ['company_key', 'module', 'idempotency_key']) {
                $scoped = true;
                break;
            }
        }
        if (!$scoped) {
            $pdo->exec('ALTER TABLE `idempotency_keys` ADD UNIQUE KEY `uq_idempotency_scope` (`company_key`,`module`,`idempotency_key`)');
        }
    }

    private static function normalizeStatus(string $status): string {
        $s = strtolower(trim($status));
        if ($s === '' || in_array($s, ['aktif','active','enabled','enable','1','true'], true)) return 'Aktif';
        if (in_array($s, ['nonaktif','inactive','disabled','disable','0','false'], true)) return 'Nonaktif';
        return 'Aktif';
    }

    private static function passwordHash(string $password): string {
        $password = trim($password);
        if ($password === '') $password = 'ChangeMe123';
        if (preg_match('/^\$2[ayb]\$/', $password) || str_starts_with($password, '$argon2')) return $password;
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Backward-compatible user upsert. It supports the old `nama` column and
     * the new `name`/`user_uuid` columns at the same time.
     */
    public static function upsertUser(PDO $pdo, array $user): int {
        self::repairUserSchema($pdo);
        $cols = self::columns($pdo, 'system_users');
        $username = trim((string)($user['username'] ?? ''));
        if ($username === '') throw new InvalidArgumentException('Username tidak boleh kosong.');
        $name = trim((string)($user['name'] ?? $user['nama'] ?? $username)) ?: $username;
        $email = trim((string)($user['email'] ?? ''));
        $role = trim((string)($user['role_id'] ?? 'ROLE-STAFF')) ?: 'ROLE-STAFF';
        $status = self::normalizeStatus((string)($user['status'] ?? 'Aktif'));
        $mustChange = !empty($user['must_change_password']) ? 1 : 0;
        $userUuid = trim((string)($user['user_uuid'] ?? $user['id_user'] ?? $user['id'] ?? ''));
        if ($userUuid === '' || ctype_digit($userUuid)) {
            $userUuid = 'USR-' . strtoupper(substr(hash('sha256', $username.'|'.$email), 0, 12));
        }
        $hash = isset($user['password_hash']) && trim((string)$user['password_hash']) !== ''
            ? self::passwordHash((string)$user['password_hash'])
            : self::passwordHash((string)($user['password'] ?? 'ChangeMe123'));

        $st = $pdo->prepare('SELECT id FROM system_users WHERE username=? LIMIT 1');
        $st->execute([$username]);
        $id = (int)($st->fetchColumn() ?: 0);

        $values = [
            'user_uuid'=>$userUuid,
            'username'=>$username,
            'name'=>$name,
            'email'=>$email,
            'password_hash'=>$hash,
            'role_id'=>$role,
            'status'=>$status,
            'must_change_password'=>$mustChange,
        ];
        if (isset($cols['nama'])) $values['nama'] = $name;

        if ($id > 0) {
            // Preserve an existing password unless an explicit password/hash was supplied.
            if (!array_key_exists('password', $user) && !array_key_exists('password_hash', $user)) unset($values['password_hash']);
            $sets=[];$params=[];
            foreach($values as $k=>$v){ if(!isset($cols[$k])) continue; $sets[]="`{$k}`=?"; $params[]=$v; }
            if (isset($cols['updated_at'])) $sets[]='updated_at=NOW()';
            $params[]=$id;
            $pdo->prepare('UPDATE system_users SET '.implode(',',$sets).' WHERE id=?')->execute($params);
            return $id;
        }

        $insertCols=[];$marks=[];$params=[];
        foreach($values as $k=>$v){ if(!isset($cols[$k])) continue; $insertCols[]="`{$k}`"; $marks[]='?'; $params[]=$v; }
        $sql='INSERT INTO system_users('.implode(',',$insertCols).') VALUES('.implode(',',$marks).')';
        $pdo->prepare($sql)->execute($params);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Force a canonical local Owner credential across every legacy duplicate row.
     * This is intentionally stronger than a normal upsert because older starter
     * databases may contain duplicate `owner` rows or a stale password hash.
     * No business/transaction data is touched.
     */
    public static function ensureOwner(PDO $pdo, string $username, string $name, string $password): void {
        // Auth V2 must be repairable even when a legacy system_users table is malformed.
        $username = trim($username) ?: 'owner';
        $name = trim($name) ?: 'Owner JAGO LABA';
        $password = $password !== '' ? $password : 'owner123';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) throw new RuntimeException('Gagal membuat password hash Owner.');

        // Canonical local-auth table. This deliberately bypasses legacy system_users
        // constraints/triggers that may differ between older starter builds.
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_users_v2 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role_id VARCHAR(120) NOT NULL DEFAULT 'ROLE-OWNER_SUPER_ADMIN',
            status VARCHAR(32) NOT NULL DEFAULT 'Aktif',
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_auth_v2_status(status), INDEX idx_auth_v2_role(role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $st = $pdo->prepare("INSERT INTO auth_users_v2(username,name,password_hash,role_id,status)
            VALUES(?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role_id=VALUES(role_id),status=VALUES(status),updated_at=NOW()");
        $st->execute([$username,$name,$hash,'ROLE-OWNER_SUPER_ADMIN','Aktif']);

        $verify = $pdo->prepare("SELECT password_hash,status FROM auth_users_v2 WHERE LOWER(TRIM(username))=LOWER(TRIM(?)) LIMIT 1");
        $verify->execute([$username]);
        $row = $verify->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (!in_array($status,['aktif','active'],true) || !password_verify($password,(string)($row['password_hash']??''))) {
            throw new RuntimeException('Self-test auth_users_v2 gagal setelah reset password.');
        }

        // Best-effort mirror into legacy system_users for compatibility only.
        // A legacy schema must never block the canonical local login anymore.
        try {
            $cols = self::columns($pdo, 'system_users');
            $check = $pdo->prepare('SELECT COUNT(*) FROM system_users WHERE LOWER(TRIM(username))=LOWER(TRIM(?))');
            $check->execute([$username]);
            if ((int)$check->fetchColumn() === 0) {
                self::upsertUser($pdo,[
                    'username'=>$username,'name'=>$name,'password'=>$password,
                    'role_id'=>'ROLE-OWNER_SUPER_ADMIN','status'=>'Aktif','must_change_password'=>0,
                ]);
            }
            $cols = self::columns($pdo, 'system_users');
            $sets=[];$params=[];
            if(isset($cols['name'])){$sets[]='`name`=?';$params[]=$name;}
            if(isset($cols['nama'])){$sets[]='`nama`=?';$params[]=$name;}
            if(isset($cols['password_hash'])){$sets[]='`password_hash`=?';$params[]=$hash;}
            if(isset($cols['role_id'])){$sets[]='`role_id`=?';$params[]='ROLE-OWNER_SUPER_ADMIN';}
            if(isset($cols['status'])){$sets[]='`status`=?';$params[]='Aktif';}
            if(isset($cols['must_change_password'])){$sets[]='`must_change_password`=0';}
            if(isset($cols['updated_at'])){$sets[]='`updated_at`=NOW()';}
            if($sets){$params[]=$username;$pdo->prepare('UPDATE system_users SET '.implode(',',$sets).' WHERE LOWER(TRIM(username))=LOWER(TRIM(?))')->execute($params);}
        } catch (Throwable $legacyError) {
            // intentionally ignored: canonical auth_users_v2 is already verified.
        }
    }

    /** Safe installer diagnostic; never returns a password hash. */
    public static function authDiagnostic(PDO $pdo, string $username='owner', ?string $testPassword=null): array {
        $st=$pdo->prepare("SELECT id,username,name,status,role_id,password_hash FROM auth_users_v2 WHERE LOWER(TRIM(username))=LOWER(TRIM(?)) ORDER BY id ASC");
        $st->execute([trim($username)]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        $out=[];
        foreach($rows as $r){
            $hash=(string)($r['password_hash']??'');
            $info=password_get_info($hash);
            $out[]=[
                'id'=>$r['id']??null,'username'=>$r['username']??'','name'=>$r['name']??'',
                'status'=>$r['status']??'','role_id'=>$r['role_id']??'',
                'hash_valid'=>!empty($info['algo']),
                'test_password_match'=>$testPassword===null?null:password_verify($testPassword,$hash),
                'source'=>'auth_users_v2',
            ];
        }
        return ['username'=>$username,'rows'=>count($out),'accounts'=>$out,'source'=>'auth_users_v2'];
    }

}
