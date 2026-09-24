USE UchetLFPSTU;

-- Если строка с логином admin уже есть — просто обновляем хеш пароля
UPDATE TB_AppUser
SET PasswordHash = '$2y$10$ixH6ZWqwd.djOxT4Lhod7.K3Hq6BPv0pUndlsmRtdVyEMs9bz5I/O', -- хеш от "admin"
    IsDeleted    = 0,
    UpdatedAt    = NOW()
WHERE LoginName = 'admin';

-- Если такой строки ещё нет — вставляем нового пользователя
INSERT INTO TB_AppUser (LoginName, PasswordHash, IsDeleted, CreatedAt, UpdatedAt)
SELECT 'admin',
       '$2y$10$ixH6ZWqwd.djOxT4Lhod7.K3Hq6BPv0pUndlsmRtdVyEMs9bz5I/O', -- хеш от "admin"
       0, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM TB_AppUser WHERE LoginName = 'admin'
);

-- Проверка: должен вернуться ровно один ряд с LoginName = admin
SELECT idUser, LoginName, IsDeleted FROM TB_AppUser WHERE LoginName = 'admin';