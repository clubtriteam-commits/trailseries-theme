# План за пускане на новия сайт на trailseries.bg

Статус: чернова, 2026-11-19. Нищо от тук не е изпълнено.

## Какво има на сървъра (проверено 2026-11-19)

| | Стар сайт (лайв) | Нов сайт (staging) |
|---|---|---|
| Адрес | trailseries.bg | stg.trailseries.bg |
| Папка | `~/public_html` | `~/stg.trailseries.bg` |
| WordPress | 5.9.21, тема Optimizer Pro, WP Rocket | 7.1.5, exhibz-child + trailseries-results |
| База | `trailser_wrdp1` (98 MB, префикс `wp_`) | `trailser_BGB` (16 MB, префикс `to7_`) |
| Uploads | 905 MB | 381 MB |
| PHP | пакетът на акаунта, 8.4 | 8.5 (AddHandler в .htaccess) |

**Най-важното ограничение.** `trailseries.bg` е основният домейн на cPanel акаунта, затова папката му
е винаги `~/public_html` и не може да се смени. В нея като подпапки живеят и други сайтове:
runbg.net (18 GB), triteamsofia.com, triseries.bg, skyrunpirin.com, duathlons.trailseries.bg,
tonipetkov.runbg.net, test.runbg.net. Затова:

- `public_html` **не се мести, не се преименува и не се трие**. Подменят се само файловете на
  WordPress в корена ѝ.
- Не пипаме: подпапките на другите сайтове, `cgi-bin`, `google9f1f0ba66f84650b.html`
  (Search Console), `favicon.ico`, `php.ini`, `*.shtml`.
- Коренният `.htaccess` важи и за подпапките без собствен `.htaccess` (duathlons, tonipetkov,
  test.runbg.net, а частично и skyrunpirin.com). В него **не слагаме** ред `AddHandler ...php85`,
  иначе сменяме PHP и на тях. Новият сайт върви на пакетния PHP 8.4.

## Решение на собственика: старият сайт

**Препоръка: статично копие на old.trailseries.bg + пълен архив извън сървъра.**

Старият WordPress (5.9, остарели плъгини, 106 фатални грешки на PHP 8.4) е в същия акаунт като
другите 7 сайта. Ако го държим жив и някой го пробие, достига и до тях. Статичното копие
(само HTML и картинки) изглежда и се разглежда точно като стария сайт, но няма какво да се хакне.

Вариант „жив WordPress на old.trailseries.bg“ също е възможен, ако е нужен админ достъп до
старото съдържание. Тогава задължително: парола (Basic auth), noindex, изключен WP Rocket.

## Стъпки

### Фаза 0 — подготовка (дни преди пускането, без риск)

1. **Архив на стария сайт** (каквото и да решим за old.):
   - `mysqldump trailser_wrdp1 | gzip > ~/backups/old-live-YYYYMMDD.sql.gz`
   - `tar czf ~/backups/old-live-files-YYYYMMDD.tgz` на WordPress файловете в `public_html`
     (wp-admin, wp-includes, wp-content, wp-*.php, index.php, .htaccess), **без** подпапките на
     другите сайтове.
   - Сваляне на двата файла и на компютъра (или Google Drive). Архив само на същия сървър не е архив.
2. **Статично копие** (докато старият сайт още отговаря на trailseries.bg):
   `wget --mirror --convert-links --adjust-extension --page-requisites` от компютъра или сървъра.
   Качваме го в нова папка `~/old.trailseries.bg`, създаваме поддомейн old.trailseries.bg в cPanel
   към нея, пускаме AutoSSL и слагаме `X-Robots-Tag: noindex` в `.htaccess`.
3. **Проверка на адресите (ADR-003).** `migration/crawl_url_inventory.py` срещу staging, за всеки
   адрес от `migration/url-inventory.csv`. Всичко трябва да е 200 или изрично пренасочване от
   `redirect-map.csv`. Проверяваме и дали пренасочванията вече работят на staging.
4. **Старите картинки.** 16 публикации на staging сочат към `trailseries.bg/wp-content/uploads/...`,
   а Google е индексирал и стари картинки. Копираме старите uploads в новите **без презаписване**
   (`rsync -a --ignore-existing` или `cp -rn`), за да работят старите линкове.
5. **Съдържание.** Новините съвпадат (последна: 30.05.2026). Събития: стар 19, нов 11. Да проверим
   дали нещо от старите събития липсва. От тук до пускането **не пишем нищо на стария сайт**:
   всяка нова новина или събитие отива на staging.
6. **SSL** за trailseries.bg вече има (AutoSSL). Проверяваме и за old.

### Фаза 1 — пускане (≈1 час, вечер или в делничен ден без състезание)

1. Пресен dump на двете бази (`trailser_wrdp1`, `trailser_BGB`) в `~/backups/`.
2. **Нова база за лайв** (напр. `trailser_live`) в cPanel, импорт на dump-а от staging.
   Staging остава със своята база, за да не се бият двата сайта.
3. В новата база:
   - `wp search-replace 'https://stg.trailseries.bg' 'https://trailseries.bg' --all-tables --dry-run`,
     после без `--dry-run`. Също вариантът `stg.trailseries.bg` без схема и с екранирани `\/`
     (JSON).
   - `wp option update blog_public 1` (staging е скрит от търсачките, лайв не трябва да е).
4. Режим поддръжка: файл `.maintenance` в `public_html`.
5. **Смяна на файловете** в `public_html`:
   - Местим старите WordPress файлове в `~/old-wp-files/`: само `wp-admin`, `wp-includes`,
     `wp-content`, `wp-*.php`, `index.php`, `xmlrpc.php`, `license.txt`, `readme.html`, `.htaccess`.
     **Не** `mv public_html/*`.
   - Копираме от staging същите елементи: core, `wp-content` (темата, плъгините, uploads).
   - Обединяваме старите uploads (фаза 0, т. 4).
   - `wp-config.php`: новата база, префикс `to7_`, нови ключове (salts), `WP_DEBUG` false.
   - `.htaccess`: блоковете от staging (TSR security, xmlrpc, TSR performance, WordPress), **без**
     реда `AddHandler ...php85`.
6. `wp rewrite flush`, `wp cache flush`, махаме `.maintenance`.

### Фаза 2 — проверка (веднага след пускането)

1. Отново `crawl_url_inventory.py`, вече срещу trailseries.bg: същият резултат като на staging.
2. Ръчно: начало, резултати, профил на бегач, ранкинг, трасета (GPX), календар, събитие, новина.
3. Другите сайтове: runbg.net, triteamsofia.com, triseries.bg, skyrunpirin.com,
   duathlons.trailseries.bg се отварят нормално.
4. Search Console: изпращаме новия sitemap и следим 404 няколко седмици (ADR-003, т. 6).
5. Обновяваме CLAUDE.md: deploy вече е в `~/public_html`, а staging остава за тестове.

### Връщане назад (ако нещо се счупи)

- Местим новите файлове встрани, връщаме `~/old-wp-files/` в `public_html`. Старата база
  `trailser_wrdp1` не е пипана, затова старият сайт тръгва веднага.
- Цел: под 10 минути.

### След пускането

- Седмица–две държим `~/old-wp-files/` и старата база, после ги оставяме само в архива.
- Затягане на сигурността (отделна задача, виж бележката за сигурност в паметта на проекта).
- Записът на KrisoK в Партньори и снимките, качени на staging, идват автоматично, защото
  пренасяме staging базата.
