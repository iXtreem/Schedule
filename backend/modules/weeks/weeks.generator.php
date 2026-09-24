
<?php
/*
 * Генератор учебных недель (автосоздание недель в одну кнопку).
 *
 * Правила:
 *  - семестр идёт от даты начала до даты окончания;
 *  - НЕДЕЛЯ КАЛЕНДАРНАЯ: начинается с понедельника и заканчивается
 *    воскресеньем (воскресенье входит в end_date каждой недели);
 *  - исключение — первая и последняя неделя семестра: их границы срезаются
 *    по фактическим датам начала/окончания семестра;
 *  - выходные учитываются при нумерации и разбивке: суббота и воскресенье —
 *    выходные по умолчанию, дни из таблицы holiday тоже считаются нерабочими;
 *  - если перед следующим понедельником идёт длинный перерыв (>=2 нерабочих
 *    дня подряд, например праздник в пятницу или праздники Чт+Пт), то
 *    предыдущая неделя «обрывается» на последнем учебном дне, а следующая
 *    начинается со своего понедельника;
 *  - полностью нерабочие недели (каникулы) пропускаются и не нумеруются;
 *  - уже существующие недели (такой же понедельник) не дублируются —
 *    проверку делает контроллер, поэтому кнопку можно жать повторно.
 *
 * Функции здесь чистые (без БД): набор выходных передаётся как
 * ассоциативный массив ['YYYY-MM-DD' => true]. Так их легко тестировать.
 */

if (!function_exists('weekMondayOf')) {

  // Понедельник для произвольной даты (DateTimeImmutable, без смещений поясов).
  function weekMondayOf(DateTimeImmutable $d): DateTimeImmutable {
    // format('N'): 1 = понедельник ... 7 = воскресенье.
    $n = (int)$d->format('N');
    return $d->modify('-' . ($n - 1) . ' days')->setTime(0, 0, 0);
  }

  // Учебный ли день: будни (Пн..Пт) и не объявлен выходным (праздником).
  function weekIsStudyDay(string $dateStr, array $holidaysSet): bool {
    $wday = (int)(new DateTimeImmutable($dateStr))->format('N'); // 1..7
    if ($wday >= 6) return false;                                // сб, вс — выходные
    return !isset($holidaysSet[$dateStr]);                       // праздники — выходные
  }

  /*
   * Построить список календарных недель семестра (Пн..Вс).
   *
   * Вход:  $start / $end — границы семестра ('YYYY-MM-DD'),
   *        $holidaysSet — ['YYYY-MM-DD' => true] всех выходных дней.
   * Выход: массив недель ['name', 'start_date', 'end_date'].
   */
  function buildSemesterWeeks(string $start, string $end, array $holidaysSet): array {
    $semesterStart = (new DateTimeImmutable($start))->setTime(0, 0, 0);
    $semesterEnd   = (new DateTimeImmutable($end))->setTime(0, 0, 0);
    if ($semesterEnd < $semesterStart) return [];

    $weeks   = [];
    $counter = 1; // порядковый номер недели для имени «N неделя»

    // Курсор — всегда понедельник очередной календарной недели.
    // Первая неделя может начинаться раньше даты старта семестра —
    // её границы срежем ниже по $semesterStart.
    $monday = weekMondayOf($semesterStart);
    $guard  = 0;

    while ($monday <= $semesterEnd) {
      if (++$guard > 400) break; // защита от бесконечного цикла (~8 лет)

      $sunday = $monday->modify('+6 days'); // календарный конец недели — воскресенье

      // Есть ли в этой календарной неделе хотя бы один учебный день?
      // Полностью нерабочие недели (каникулы/длинные праздники) пропускаем.
      $hasStudy = false;
      $d = $monday;
      for ($i = 0; $i < 7; $i++) {
        if (weekIsStudyDay($d->format('Y-m-d'), $holidaysSet)) { $hasStudy = true; break; }
        $d = $d->modify('+1 day');
      }
      if (!$hasStudy) {
        $monday = $monday->modify('+7 days');
        continue;
      }

      // Границы недели: понедельник..воскресенье, но не дальше дат семестра
      // (это и есть «исключение» для первой и последней недели).
      $weekStart = $monday;
      $weekEnd   = $sunday;
      if ($weekStart < $semesterStart) $weekStart = $semesterStart;
      if ($weekEnd   > $semesterEnd)   $weekEnd   = $semesterEnd;

      // «Обрыв» недели перед длинным перерывом: найдём последний учебный день
      // (Пн..Пт) внутри недели. Если он не пятница и после него до следующего
      // понедельника >=2 нерабочих дней подряд — неделя заканчивается им.
      $lastStudy = null;
      $d = $weekStart;
      while ($d <= $weekEnd && (int)$d->format('N') <= 5) { // только Пн..Пт
        if (weekIsStudyDay($d->format('Y-m-d'), $holidaysSet)) $lastStudy = $d;
        $d = $d->modify('+1 day');
      }
      if ($lastStudy !== null && (int)$lastStudy->format('N') < 5) {
        $gapLen = 0; // сколько нерабочих дней подряд идёт сразу за $lastStudy
        $g = $lastStudy->modify('+1 day');
        while ($gapLen < 10 && !weekIsStudyDay($g->format('Y-m-d'), $holidaysSet)) {
          $gapLen++;
          $g = $g->modify('+1 day');
        }
        // Перерыв должен «дотягивать» до следующего понедельника включительно,
        // иначе это просто одиночный пропуск (например, праздник в среду).
        if ($gapLen >= 2 && $g > $sunday) {
          $weekEnd = $lastStudy;
        }
      }

      $weeks[] = [
        'name'       => $counter . ' неделя',
        'start_date' => $weekStart->format('Y-m-d'),
        'end_date'   => $weekEnd->format('Y-m-d'),
      ];
      $counter++;

      // Следующая календарная неделя — следующий понедельник.
      $monday = $monday->modify('+7 days');
    }

    return $weeks;
  }
}
