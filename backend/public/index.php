<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/headers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/response.php';
require_once __DIR__ . '/../lib/request.php';

$entity = getQuery('entity', '');
$method = $_SERVER['REQUEST_METHOD'];

function isPublicEntity($entity) {
  static $public = [
    'auth_me',
    'auth_login',
    'auth_logout',
    'auth_register_first',
  ];
  return in_array($entity, $public, true);
}

$conn = null;

try {
  $conn = getDBConnection();

  if (!isPublicEntity($entity) && empty($_SESSION['auth_user'])) {
    errorJson('Требуется авторизация', 401);
  }

  switch ($entity) {
    case 'auth_me':
    case 'auth_login':
    case 'auth_logout':
    case 'auth_register_first':
      require_once __DIR__ . '/../modules/auth/auth.controller.php';
      authController($conn, $method, $entity);
      break;

    case 'groups':
      require_once __DIR__ . '/../modules/groups/groups.controller.php';
      groupsController($conn, $method);
      break;

    case 'weeks':
      require_once __DIR__ . '/../modules/weeks/weeks.controller.php';
      weeksController($conn, $method);
      break;

    case 'holiday':
        require_once __DIR__ . '/../modules/holidays/holidays.controller.php';
        holidayController($conn, $method);
        break;

        case 'holidays_range':
        require_once __DIR__ . '/../modules/holidays/holidays.controller.php';
        holidaysRangeController($conn, $method);
        break;

    case 'subjects':
        require_once __DIR__ . '/../modules/subjects/subjects.controller.php';
        subjectsController($conn, $method);
        break;

    case 'teachers':
        require_once __DIR__ . '/../modules/teachers/teachers.controller.php';
        teachersController($conn, $method);
    break;

    case 'rooms':
        require_once __DIR__ . '/../modules/rooms/rooms.controller.php';
        roomsController($conn, $method);
    break;

    case 'lesson_types':
        require_once __DIR__ . '/../modules/lesson_types/lesson_types.controller.php';
        lessonTypesController($conn, $method);
    break;

    case 'plan_subjects':
    case 'plan_teachers':
    case 'plan_lesson_types':
    case 'plan_teachers_base':
    case 'plan_subjects_by_teacher':
      require_once __DIR__ . '/../modules/plan/plan.controller.php';
      planController($conn, $method, $entity);
    break;


    case 'schedule_for_week':
        require_once __DIR__ . '/../modules/lessons/lessons.controller.php';
        scheduleForWeekController($conn, $method);
    break;

    case 'schedule_lessons':
        require_once __DIR__ . '/../modules/lessons/lessons.controller.php';
        scheduleLessonsController($conn, $method);
    break;

    case 'room_prefs':
    case 'room_prefs_all':
        require_once __DIR__ . '/../modules/room_prefs/room_prefs.controller.php';
        roomPrefsController($conn, $method, $entity);
    break;

    default:
      errorJson('Неизвестная сущность', 404);
  }

} catch (Exception $e) {
  errorJson('Ошибка сервера: ' . $e->getMessage(), 500);
} finally {
  dbClose($conn);
}
