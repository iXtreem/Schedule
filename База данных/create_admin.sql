-- ============================================================================
-- Создание/обновление учётной записи администратора (логин: admin, пароль: admin)
-- Таблица user — новая схема uchetlfpstu (бывшая TB_AppUser)
-- ============================================================================
USE uchetlfpstu;

-- Если строка с логином admin уже есть — просто обновляем хеш пароля
UPDATE user
SET password_hash = '$2y$10$ixH6ZWqwd.djOxT4Lhod7.K3Hq6BPv0pUndlsmRtdVyEMs9bz5I/O', -- хеш от "admin"
    is_deleted    = 0,
    updated_at    = NOW()
WHERE login = 'admin';

-- Если такой строки ещё нет — вставляем нового пользователя
INSERT INTO user (login, password_hash, is_deleted, created_at, updated_at)
SELECT 'admin',
       '$2y$10$ixH6ZWqwd.djOxT4Lhod7.K3Hq6BPv0pUndlsmRtdVyEMs9bz5I/O', -- хеш от "admin"
       0, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM user WHERE login = 'admin'
);

-- Проверка: должен вернуться ровно один ряд с login = admin
SELECT id, login, is_deleted FROM user WHERE login = 'admin';