<?php
declare(strict_types=1);

/** Manajemen user & teknisi (Admin). */
final class UsersApi
{
    public static function login(): array
    {
        $in = Http::body();
        return Auth::login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
    }

    public static function logout(): array
    {
        Auth::logout();
        return [];
    }

    public static function me(): array
    {
        return ['user' => Auth::user()];
    }

    public static function changePassword(): array
    {
        $me = Auth::user();
        $in = Http::body();
        $row = Db::query('SELECT password_hash FROM users WHERE id = ?', [$me['id']])[0];
        if (!password_verify((string) ($in['old'] ?? ''), (string) $row['password_hash'])) {
            throw new HttpError('Password lama salah.', 400);
        }
        self::checkPassword((string) ($in['new'] ?? ''));
        Db::transaction([['UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $in['new'], PASSWORD_DEFAULT), $me['id']]]]);
        return [];
    }

    /** Akun sendiri: nama & kontak. Role, unit, dan status hanya bisa diubah Admin. */
    public static function profile(): array
    {
        $me = Auth::user();
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new HttpError('Nama wajib diisi.');
        }
        $v = fn (string $k) => trim((string) ($in[$k] ?? '')) ?: null;
        Db::transaction([['UPDATE users SET name = ?, department = ?, jabatan = ?, whatsapp = ? WHERE id = ?', [$name, $v('department'), $v('jabatan'), $v('whatsapp'), $me['id']]]]);
        return ['user' => Auth::publicUser(Db::query('SELECT * FROM users WHERE id = ?', [$me['id']])[0])];
    }

    /** Keluar dari semua perangkat (hapus semua token milik user ini). */
    public static function logoutAll(): array
    {
        $me = Auth::user();
        Db::transaction([['DELETE FROM auth_tokens WHERE user_id = ?', [$me['id']]]]);
        return [];
    }

    public static function list(): array
    {
        Auth::requireRole('admin', 'spv');
        return ['rows' => Db::query(
            'SELECT u.id, u.username, u.name, u.role, u.unit_id, u.department, u.jabatan, u.whatsapp, u.active, un.name AS unit_name
             FROM users u LEFT JOIN units un ON un.id = u.unit_id ORDER BY u.name'
        )];
    }

    public static function save(): array
    {
        Auth::requireRole('admin');
        $in = Http::body();
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        $name = trim((string) ($in['name'] ?? ''));
        $role = (string) ($in['role'] ?? 'user');
        if (!preg_match('~^[a-z0-9._-]{3,30}$~', $username) || $name === '' || !in_array($role, Auth::ROLES, true)) {
            throw new HttpError('Username (3–30 karakter), nama, dan role wajib valid.');
        }
        $unit = ($u = (int) ($in['unit_id'] ?? 0)) > 0 ? $u : null;
        $vals = [$name, $role, $unit, trim((string) ($in['department'] ?? '')) ?: null,
            trim((string) ($in['jabatan'] ?? '')) ?: null, trim((string) ($in['whatsapp'] ?? '')) ?: null,
            empty($in['active']) && isset($in['active']) ? 0 : 1];
        $password = (string) ($in['password'] ?? '');
        if (($id = (int) ($in['id'] ?? 0)) > 0) {
            $cur = Db::query('SELECT * FROM users WHERE id = ?', [$id])[0] ?? throw new HttpError('User tidak ditemukan.', 404);
            $me = Auth::user();
            if ($vals[6] === 0 && $id === $me['id']) {
                throw new HttpError('Anda tidak bisa menonaktifkan akun sendiri.', 409);
            }
            $losesAdmin = $cur['role'] === 'admin' && (int) $cur['active'] === 1 && ($role !== 'admin' || $vals[6] === 0);
            if ($losesAdmin && (int) Db::query("SELECT COUNT(*) c FROM users WHERE role = 'admin' AND active = 1")[0]['c'] <= 1) {
                throw new HttpError('Harus ada minimal satu Admin aktif.', 409);
            }
            $st = [['UPDATE users SET name=?, role=?, unit_id=?, department=?, jabatan=?, whatsapp=?, active=? WHERE id=?', [...$vals, $id]]];
            if ($password !== '') {
                self::checkPassword($password);
                $st[] = ['UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]];
            }
            if ($vals[6] === 0 || $password !== '') {
                $st[] = ['DELETE FROM auth_tokens WHERE user_id = ?', [$id]]; // paksa login ulang
            }
            if ($vals[6] === 0) {
                $st[] = ['UPDATE technicians SET active = 0 WHERE user_id = ?', [$id]];
            }
            Db::transaction($st);
        } else {
            self::checkPassword($password);
            if (Db::query('SELECT 1 FROM users WHERE username = ?', [$username])) {
                throw new HttpError('Username sudah dipakai.', 409);
            }
            Db::transaction([['INSERT INTO users (username, password_hash, name, role, unit_id, department, jabatan, whatsapp, active)
                              VALUES (?,?,?,?,?,?,?,?,?)', [$username, password_hash($password, PASSWORD_DEFAULT), ...$vals]]]);
            $id = (int) Db::query('SELECT id FROM users WHERE username = ?', [$username])[0]['id'];
        }
        // Role Maintenance otomatis punya profil teknisi
        if ($role === 'maintenance' && !Db::query('SELECT 1 FROM technicians WHERE user_id = ?', [$id])) {
            Db::transaction([['INSERT INTO technicians (user_id, name, phone) VALUES (?,?,?)', [$id, $name, $vals[5]]]]);
        }
        return ['id' => $id];
    }

    public static function technicians(): array
    {
        Auth::requireRole('admin', 'spv');
        return ['rows' => Db::query(
            "SELECT t.*, u.username, u.name AS user_name, u.active AS user_active,
                    (SELECT COUNT(*) FROM wo_techs w WHERE w.tech_id = t.id) AS wo_count,
                    (SELECT COUNT(*) FROM wo_techs w JOIN work_orders o ON o.id = w.wo_id WHERE w.tech_id = t.id AND o.status = 'Closed') AS closed,
                    (SELECT COUNT(*) FROM wo_techs w JOIN work_orders o ON o.id = w.wo_id WHERE w.tech_id = t.id AND o.status = 'In Progress') AS in_progress,
                    (SELECT COALESCE(SUM(o.work_seconds), 0) FROM wo_techs w JOIN work_orders o ON o.id = w.wo_id WHERE w.tech_id = t.id) AS seconds
             FROM technicians t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.active DESC, t.name"
        )];
    }

    public static function saveTechnician(): array
    {
        Auth::requireRole('admin', 'spv');
        $in = Http::body();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new HttpError('Nama teknisi wajib diisi.');
        }
        $id = (int) ($in['id'] ?? 0);
        $uid = ($u = (int) ($in['user_id'] ?? 0)) > 0 ? $u : null;
        if ($uid !== null) {
            if (!Db::query('SELECT 1 FROM users WHERE id = ?', [$uid])) {
                throw new HttpError('User yang dipilih tidak ditemukan.');
            }
            if (Db::query('SELECT 1 FROM technicians WHERE user_id = ? AND id != ?', [$uid, $id])) {
                throw new HttpError('User ini sudah terhubung ke teknisi lain.', 409);
            }
        }
        $vals = [$name, $uid, trim((string) ($in['spec'] ?? '')) ?: null, trim((string) ($in['shift'] ?? '')) ?: null,
            trim((string) ($in['phone'] ?? '')) ?: null, isset($in['active']) && !$in['active'] ? 0 : 1];
        if ($id > 0) {
            Db::transaction([['UPDATE technicians SET name=?, user_id=?, spec=?, shift=?, phone=?, active=? WHERE id=?', [...$vals, $id]]]);
        } else {
            Db::transaction([['INSERT INTO technicians (name, user_id, spec, shift, phone, active) VALUES (?,?,?,?,?,?)', $vals]]);
            $id = (int) Db::query('SELECT MAX(id) m FROM technicians')[0]['m'];
        }
        return ['id' => $id];
    }

    public static function deleteTechnician(): array
    {
        Auth::requireRole('admin');
        $id = (int) (Http::body()['id'] ?? 0);
        if ((int) Db::query('SELECT (SELECT COUNT(*) FROM wo_techs WHERE tech_id = ?) c', [$id])[0]['c'] > 0) {
            throw new HttpError('Teknisi sudah tercatat di Work Order — nonaktifkan saja.', 409);
        }
        Db::transaction([['DELETE FROM technicians WHERE id = ?', [$id]]]);
        return [];
    }
    private static function checkPassword(string $p): void
    {
        if (strlen($p) < 6) {
            throw new HttpError('Password minimal 6 karakter.');
        }
    }
}
