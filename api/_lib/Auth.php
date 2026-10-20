<?php
declare(strict_types=1);

/**
 * Autentikasi token acak (disimpan sebagai hash SHA-256 di auth_tokens).
 * Role: admin · spv (Leader/SPV) · maintenance (teknisi) · user (pemohon WO).
 */
final class Auth
{
    public const ROLES = ['admin', 'spv', 'maintenance', 'user'];
    private const TOKEN_DAYS = 30; // sliding: diperpanjang tiap kali dipakai (maks. sekali per jam)

    private static ?array $user = null;

    public static function login(string $username, string $password): array
    {
        $username = strtolower(trim($username));
        $row = Db::query('SELECT * FROM users WHERE username = ?', [$username])[0] ?? null;
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            usleep(300000);
            throw new HttpError('Username atau password salah.', 401);
        }
        if ((int) $row['active'] !== 1) {
            throw new HttpError('Akun ini dinonaktifkan. Hubungi Admin.', 403);
        }
        $token = bin2hex(random_bytes(32));
        Db::transaction([
            ['DELETE FROM auth_tokens WHERE expires_at < ?', [time()]],
            ['INSERT INTO auth_tokens (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
                [hash('sha256', $token), (int) $row['id'], time() + self::TOKEN_DAYS * 86400]],
        ]);
        return ['token' => $token, 'user' => self::publicUser($row)];
    }

    public static function logout(): void
    {
        $token = self::bearer();
        if ($token !== null) {
            Db::transaction([['DELETE FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)]]]);
        }
    }

    /** User yang sedang login; 401 bila token tidak valid. */
    public static function user(): array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $token = self::bearer();
        if ($token === null) {
            throw new HttpError('Silakan login terlebih dahulu.', 401);
        }
        $row = Db::query(
            'SELECT u.*, t.expires_at AS _exp FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > ? AND u.active = 1',
            [hash('sha256', $token), time()]
        )[0] ?? null;
        if (!$row) {
            throw new HttpError('Sesi berakhir. Silakan login ulang.', 401);
        }
        $full = self::TOKEN_DAYS * 86400;
        if ((int) $row['_exp'] < time() + $full - 3600) {
            try {
                Db::transaction([['UPDATE auth_tokens SET expires_at = ? WHERE token_hash = ?', [time() + $full, hash('sha256', $token)]]]);
            } catch (Throwable) { /* gagal memperpanjang tidak boleh memutus sesi */ }
        }
        unset($row['_exp']);
        return self::$user = self::publicUser($row);
    }

    public static function requireRole(string ...$roles): array
    {
        $user = self::user();
        if (!in_array($user['role'], $roles, true)) {
            throw new HttpError('Anda tidak punya akses untuk tindakan ini.', 403);
        }
        return $user;
    }

    public static function publicUser(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'username'   => $row['username'],
            'name'       => $row['name'],
            'role'       => $row['role'],
            'unit_id'    => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
            'department' => $row['department'] ?? null,
            'jabatan'    => $row['jabatan'] ?? null,
            'whatsapp'   => $row['whatsapp'] ?? null,
            'active'     => (int) $row['active'],
        ];
    }

    /** Admin pertama dibuat dari ADMIN_USERNAME/ADMIN_PASSWORD (.env) bila tabel users masih kosong. */
    public static function seedAdmin(): void
    {
        if ((int) (Db::query('SELECT COUNT(*) c FROM users')[0]['c'] ?? 0) > 0) {
            return;
        }
        $u = Env::get('ADMIN_USERNAME');
        $p = Env::get('ADMIN_PASSWORD');
        if (!$u || !$p) {
            return;
        }
        Db::transaction([['INSERT INTO users (username, name, password_hash, role) VALUES (?, ?, ?, ?)',
            [strtolower($u), Env::get('ADMIN_NAME', 'Administrator'), password_hash($p, PASSWORD_DEFAULT), 'admin']]]);
    }

    private static function bearer(): ?string
    {
        $h = Http::header('Authorization') ?? '';
        if (preg_match('~^Bearer\s+(\S+)$~i', $h, $m)) {
            return $m[1];
        }
        return Http::header('X-Auth-Token');
    }
}
