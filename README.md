# ТСЖ «Пушкинская 15» — сайт и личный кабинет

Статический сайт без сборки: HTML, CSS и JavaScript в одном файле на страницу.

| Файл | Что это |
|---|---|
| `index.html` | сайт, личный кабинет (`#app`), вход (`#login`), раскрытие информации (`#disclosure`) |
| `app.html` | мобильное приложение с нижней навигацией |
| `hero-tszh.jpg` | картинка на главной |
| `favicon.ico`, `icon-*.png`, `apple-touch-icon.png`, `site.webmanifest` | иконки сайта |
| `_headers` | заголовки безопасности и кэша для Cloudflare Pages |

Демо-версия: телефоны, имена, банковские реквизиты, тарифы и суммы условные. Данные кабинета хранятся в браузере, платёжный шлюз не подключён.

## Публикация

- **GitHub Pages:** Settings → Pages → Deploy from a branch → `main`, папка `/ (root)`.
- **Cloudflare Pages:** Workers & Pages → Create → Pages → Connect to Git → этот репозиторий. Framework preset — None, Build command — пусто, Build output directory — `/`.

Каждый push в `main` обновляет оба сайта автоматически.
