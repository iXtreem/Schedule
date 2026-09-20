import { api } from "./js/LoadFromBD/api.js";

const authForm = document.getElementById("authForm");
const authLogin = document.getElementById("authLogin");
const authPassword = document.getElementById("authPassword");
const authSubmit = document.getElementById("authSubmit");
const authTitle = document.getElementById("authTitle");
const authHint = document.getElementById("authHint");
const authError = document.getElementById("authError");

let registerMode = false;

function goToApp() {
  window.location.replace("./index.html");
}

function showError(message) {
  if (!authError) return;
  if (!message) {
    authError.textContent = "";
    authError.classList.add("hidden");
    return;
  }
  authError.textContent = message;
  authError.classList.remove("hidden");
}

function setFormMode() {
  if (registerMode) {
    if (authTitle) authTitle.textContent = "Создание первого пользователя";
    if (authHint) {
      authHint.textContent =
        "В базе нет пользователей. Создайте первый аккаунт.";
    }
    if (authSubmit) authSubmit.textContent = "Создать и войти";
  } else {
    if (authTitle) authTitle.textContent = "Вход в расписание";
    if (authHint) authHint.textContent = "Введите логин и пароль для продолжения.";
    if (authSubmit) authSubmit.textContent = "Войти";
  }
}

async function bootstrapAuthPage() {
  showError("");

  let me = null;
  try {
    me = await api.authMe();
  } catch (e) {
    console.error(e);
  }

  if (me?.authenticated) {
    goToApp();
    return;
  }

  registerMode = me?.has_users === false;
  setFormMode();
}

async function ensureSessionAfterRegister(login, password) {
  let me = await api.authMe();
  if (me?.authenticated) return true;

  await api.authLogin({ login, password });
  me = await api.authMe();
  return Boolean(me?.authenticated);
}

async function onSubmit(event) {
  event.preventDefault();
  showError("");

  const login = String(authLogin?.value || "").trim();
  const password = String(authPassword?.value || "");

  if (!login || !password) {
    showError("Логин и пароль обязательны.");
    return;
  }

  authSubmit.disabled = true;
  try {
    if (registerMode) {
      try {
        await api.authRegisterFirst({ login, password });
      } catch (e) {
        // Если пользователь уже создан (частично успешная попытка),
        // переключаем форму в режим входа и авторизуемся сразу.
        if (e?.status === 409) {
          registerMode = false;
          setFormMode();
          await api.authLogin({ login, password });
          goToApp();
          return;
        }
        throw e;
      }

      const isAuthorized = await ensureSessionAfterRegister(login, password);
      if (!isAuthorized) {
        throw new Error(
          "Пользователь создан, но вход не выполнен. Попробуйте ещё раз.",
        );
      }
    } else {
      await api.authLogin({ login, password });
    }
    goToApp();
  } catch (e) {
    console.error(e);
    showError(e?.message || "Ошибка авторизации.");
  } finally {
    authSubmit.disabled = false;
    setFormMode();
  }
}

authForm?.addEventListener("submit", onSubmit);
bootstrapAuthPage();
