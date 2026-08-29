<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Accounts\CourseClosureService;
use Dana\Domain\Accounts\StaffAccountService;
use Dana\Domain\Accounts\StudentAccountService;
use Dana\Domain\Auth\AuthService;
use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Auth\TokenService;
use Dana\Domain\Progress\StatsService;
use Dana\Domain\Notifications\FcmSender;
use Dana\Domain\Notifications\NotificationService;
use Dana\Http\Controllers\AuthController;
use Dana\Http\Controllers\ContentAdminController;
use Dana\Http\Controllers\CurriculumController;
use Dana\Http\Controllers\ManagementController;
use Dana\Http\Controllers\NotificationController;
use Dana\Http\Controllers\SectionController;
use Dana\Http\Controllers\StudentController;
use Dana\Http\Controllers\TeacherController;
use Dana\Http\Middleware\AuthMiddleware;
use Dana\Http\Middleware\JsonErrorMiddleware;
use Dana\Support\Config;
use Dana\Support\LoggerFactory;
use DI\Container;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

$basePath = dirname(__DIR__);
$config = Config::load($basePath);

date_default_timezone_set($config->get('APP_TIMEZONE', 'Asia/Ashgabat'));
Bootstrap::boot($config);

$container = new Container();
$container->set(Config::class, $config);
// Default binding so any service taking a LoggerInterface resolves.
// Services that want their own channel are registered explicitly below.
$container->set(LoggerInterface::class, fn () => LoggerFactory::get($config, 'app'));
$container->set(CredentialService::class, fn () => new CredentialService($config->require('APP_CRED_KEY')));
$container->set(TokenService::class, fn (Container $c) => new TokenService($c->get(Config::class)));
// Separate channels so an auth investigation isn't buried in stack traces.
$container->set(AuthService::class, fn (Container $c) => new AuthService(
    $c->get(CredentialService::class),
    $c->get(TokenService::class),
    LoggerFactory::get($config, 'auth'),
));
$container->set(JsonErrorMiddleware::class, fn (Container $c) => new JsonErrorMiddleware(
    $c->get(Config::class),
    LoggerFactory::get($config, 'app'),
));
$container->set(StudentAccountService::class, fn (Container $c) => new StudentAccountService(
    $c->get(CredentialService::class),
    LoggerFactory::get($config, 'auth'),
));
$container->set(StaffAccountService::class, fn (Container $c) => new StaffAccountService(
    $c->get(CredentialService::class),
    LoggerFactory::get($config, 'auth'),
));
$container->set(FcmSender::class, fn () => new FcmSender(
    $config->get('FCM_SERVICE_ACCOUNT_PATH'),
    LoggerFactory::get($config, 'app'),
));
$container->set(NotificationService::class, fn (Container $c) => new NotificationService(
    $c->get(FcmSender::class),
));
// Question media lives outside the web root (STORAGE_PATH/media); the
// controller resolves relative paths against the api root.
$container->set(\Dana\Support\Media\MediaStorage::class, fn (Container $c) => \Dana\Support\Media\MediaStorage::fromConfig(
    $c->get(Config::class),
    $basePath,
));
$container->set(CourseClosureService::class, fn (Container $c) => new CourseClosureService(
    $c->get(StatsService::class),
    // Course closure destroys data permanently — it belongs in the
    // audit-facing channel, not buried among stack traces.
    LoggerFactory::get($config, 'auth'),
));

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add($container->get(JsonErrorMiddleware::class));

// Baseline hardening on every response. `no-store` matters most: replies
// carry per-student data and revealed credentials, and must never be
// served from a shared cache or proxy.
$app->add(function ($request, $handler) {
    return $handler->handle($request)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Referrer-Policy', 'no-referrer')
        ->withHeader('Cache-Control', 'no-store');
});

$auth = $container->get(AuthMiddleware::class);

$app->group('/api/v1', function (RouteCollectorProxy $group) use ($auth): void {
    $group->get('/health', function (ServerRequestInterface $request, ResponseInterface $response) {
        $config = Config::instance();
        $response->getBody()->write((string) json_encode([
            'ok'   => true,
            'env'  => $config->get('APP_ENV', 'local'),
            'time' => date('c'),
        ], JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    });

    $group->post('/auth/login', [AuthController::class, 'login']);
    $group->post('/auth/refresh', [AuthController::class, 'refresh']);
    $group->post('/auth/logout', [AuthController::class, 'logout']);

    $group->group('', function (RouteCollectorProxy $secure): void {
        $secure->get('/auth/me', [AuthController::class, 'me']);

        // ---- student -------------------------------------------------
        // Everything is unlocked (FR-13.3); the only filter is
        // `sections.status = published`, applied in ContentRepository,
        // never here.
        $secure->get('/me/outline', [StudentController::class, 'outline']);
        $secure->get('/me/leaderboard', [StudentController::class, 'leaderboard']);
        $secure->get('/me/stats', [StudentController::class, 'stats']);
        $secure->post('/me/heartbeat', [StudentController::class, 'heartbeat']);
        // The grammar guide routes were removed with the guide
        // (FR-13.26). Vocabulary reads flat across the whole level.
        $secure->get('/me/dictionary', [StudentController::class, 'dictionary']);

        $secure->get('/sections/{id}/vocabulary', [StudentController::class, 'vocabulary']);
        $secure->get('/units/{id}/vocabulary', [StudentController::class, 'unitVocabulary']);
        $secure->get('/sections/{id}/grammar', [StudentController::class, 'grammar']);
        $secure->post('/vocabulary/{id}/bookmark', [StudentController::class, 'toggleBookmark']);

        // ---- section attempts (§13) ----------------------------------
        // check() stores nothing; POST /attempts is the only write
        // (FR-13.5), and sections repeat without limit (FR-13.6).
        $secure->get('/sections/{id}', [SectionController::class, 'show']);
        $secure->post('/sections/{id}/check', [SectionController::class, 'check']);
        $secure->post('/sections/{id}/attempts', [SectionController::class, 'submit']);
        $secure->get('/sections/{id}/attempts', [SectionController::class, 'history']);

        // ---- notifications (FR-10) -----------------------------------
        // The inbox works regardless of whether push was delivered.
        $secure->get('/me/notifications', [NotificationController::class, 'inbox']);
        $secure->post('/me/notifications/{id}/read', [NotificationController::class, 'markRead']);
        $secure->post('/notifications', [NotificationController::class, 'send']);
        $secure->post('/me/device', [NotificationController::class, 'registerDevice']);

        // ---- teacher (read-only, FR-13.10) ---------------------------
        // No unlocking, no student creation, no credential routes — the
        // teacher observes; results derive from section_attempts/stats.
        $secure->get('/teacher/classrooms', [TeacherController::class, 'classrooms']);
        $secure->get('/teacher/classrooms/{id}/students', [TeacherController::class, 'students']);
        $secure->get('/teacher/students/{id}/attempts', [TeacherController::class, 'studentAttempts']);
        $secure->get('/teacher/students/{id}/overview', [TeacherController::class, 'studentOverview']);
        $secure->get('/teacher/students/{id}/units/{childUnitId}/progress', [TeacherController::class, 'studentUnitProgress']);
        $secure->get('/teacher/units/{id}/vocabulary', [TeacherController::class, 'unitVocabulary']);

        // ---- admin / superadmin panels --------------------------------
        // Scoped in the repository layer: an admin sees only their centre.
        $secure->get('/manage/centers', [ManagementController::class, 'centers']);
        $secure->post('/manage/centers', [ManagementController::class, 'createCenter']);
        // Superadmin edits anything (client, 2026-08-13).
        $secure->post('/manage/centers/{id}', [ManagementController::class, 'updateCenter']);
        $secure->delete('/manage/centers/{id}', [ManagementController::class, 'deleteCenter']);
        $secure->delete('/manage/admins/{id}', [ManagementController::class, 'deleteAdmin']);
        // "I want to be able to delete everything" (client, 2026-08).
        // Teachers deactivate like admins; students HARD-delete with all
        // their progress; classrooms refuse while active students remain.
        $secure->delete('/manage/teachers/{id}', [ManagementController::class, 'deleteTeacher']);
        $secure->delete('/manage/students/{id}', [ManagementController::class, 'deleteStudent']);
        $secure->delete('/manage/classrooms/{id}', [ManagementController::class, 'deleteClassroom']);
        $secure->get('/manage/staff', [ManagementController::class, 'staffList']);
        $secure->post('/manage/admins', [ManagementController::class, 'createAdmin']);
        $secure->post('/manage/teachers', [ManagementController::class, 'createTeacher']);
        $secure->post('/manage/staff/{id}/password', [ManagementController::class, 'resetStaffPassword']);
        $secure->get('/manage/options', [ManagementController::class, 'options']);
        $secure->post('/manage/classrooms', [ManagementController::class, 'createClassroom']);
        // Student accounts are created by the CENTRE ADMIN, not the
        // teacher (FR-1.4, FR-13.10).
        $secure->post('/manage/classrooms/{id}/students', [ManagementController::class, 'createStudent']);
        // FR-13.17: credential reveal/reset are admin-only now. Audited
        // on every call (FR-1.12) and rate-limited, exactly as before —
        // only the caller changed.
        $secure->get('/manage/students/{id}/credential', [ManagementController::class, 'revealStudentPassword']);
        $secure->post('/manage/students/{id}/password', [ManagementController::class, 'resetStudentPassword']);
        // The class register behind the admin's credentials UI.
        $secure->get('/manage/classrooms/{id}/students', [ManagementController::class, 'classroomStudents']);
        // FR-13.18: the admin VIEWS teacher passwords too. Same audit
        // and throttle discipline as the student path; admins of any
        // rank stay reset-only.
        $secure->get('/manage/staff/{id}/credential', [ManagementController::class, 'revealStaffPassword']);
        $secure->get('/manage/progress', [ManagementController::class, 'progress']);
        $secure->get('/manage/content', [ManagementController::class, 'content']);
        // FR-13.20 review gate: draft -> published, on sections and sets.
        $secure->post('/manage/content/{kind}/{id}/status', [ManagementController::class, 'setContentStatus']);

        // ---- content authoring, superadmin only (FR-4.12, FR-4.13) ----
        // ---- curriculum structure -------------------------------------
        // Books are out of the product (FR-14.5): no book-set, source or
        // upload routes. Content arrives only via manual authoring or xlsx.
        $secure->get('/manage/curriculum', [CurriculumController::class, 'tree']);
        $secure->post('/manage/levels', [CurriculumController::class, 'createLevel']);
        $secure->post('/manage/units', [CurriculumController::class, 'createUnit']);
        $secure->post('/manage/units/{id}', [CurriculumController::class, 'updateUnit']);
        $secure->post('/manage/sections', [CurriculumController::class, 'createSection']);
        $secure->post('/manage/sections/{id}', [CurriculumController::class, 'updateChildUnit']);
        // Structure deletes, superadmin only. Attempted content answers
        // 409 attempts_exist until repeated with ?force=1 — the same
        // contract as DELETE /manage/typed-sections/{id}. A level with
        // classrooms refuses outright: closing courses is FR-1.14's job.
        $secure->delete('/manage/levels/{id}', [CurriculumController::class, 'deleteLevel']);
        $secure->delete('/manage/units/{id}', [CurriculumController::class, 'deleteUnit']);
        $secure->delete('/manage/sections/{id}', [CurriculumController::class, 'deleteChildUnit']);

        // Content is authored manually, 1:1 from the workbook (client
        // decision 2026-08-07). No generation routes exist.
        //
        // §13 shape: a CHILD UNIT (curriculum row, /manage/sections
        // above) holds TYPED sections; content attaches to a typed
        // section, addressed as /manage/typed-sections to keep the two
        // meanings of "section" from colliding in one namespace.
        $secure->get('/manage/child-units/{id}/sections', [ContentAdminController::class, 'childUnitSections']);
        $secure->post('/manage/child-units/{id}/sections', [ContentAdminController::class, 'createSection']);
        $secure->post('/manage/typed-sections/{id}', [ContentAdminController::class, 'updateSection']);
        $secure->delete('/manage/typed-sections/{id}', [ContentAdminController::class, 'deleteSection']);
        // FR-13.20: the section IS the visibility gate.
        $secure->post('/manage/typed-sections/{id}/status', [ContentAdminController::class, 'setSectionStatus']);

        $secure->post('/manage/typed-sections/{section}/vocabulary', [ContentAdminController::class, 'saveVocabulary']);
        $secure->post('/manage/vocabulary/{id}', [ContentAdminController::class, 'saveVocabulary']);
        $secure->delete('/manage/vocabulary/{id}', [ContentAdminController::class, 'deleteVocabulary']);

        $secure->post('/manage/typed-sections/{section}/grammar', [ContentAdminController::class, 'saveGrammar']);
        $secure->delete('/manage/typed-sections/{section}/grammar', [ContentAdminController::class, 'deleteGrammar']);

        $secure->post('/manage/typed-sections/{section}/sets', [ContentAdminController::class, 'saveSet']);
        $secure->post('/manage/sets/{id}', [ContentAdminController::class, 'saveSet']);
        $secure->delete('/manage/sets/{id}', [ContentAdminController::class, 'deleteSet']);
        // FR-15.11: flip «в квизе» on every question of a set at once.
        $secure->post('/manage/sets/{id}/quiz-eligible', [ContentAdminController::class, 'setQuizEligibleBulk']);

        $secure->post('/manage/sets/{set}/questions', [ContentAdminController::class, 'saveQuestion']);
        $secure->post('/manage/questions/{id}', [ContentAdminController::class, 'saveQuestion']);
        $secure->delete('/manage/questions/{id}', [ContentAdminController::class, 'deleteQuestion']);
        // FR-13.15: content in and out as one workbook — whole database,
        // one level, or one parent unit. Superadmin only; imports land
        // as draft behind the publish gate (FR-13.20).
        // No ".xlsx" in the path — Apache treats a dotted final segment as
        // a static-file lookup and 404s before routing. The download name
        // is set via Content-Disposition instead.
        $secure->get('/manage/export-xlsx', [\Dana\Http\Controllers\XlsxController::class, 'export']);
        $secure->post('/manage/import-xlsx', [\Dana\Http\Controllers\XlsxController::class, 'import']);
        $secure->get('/manage/data-summary', [\Dana\Http\Controllers\XlsxController::class, 'summary']);

        // ---- question media (§2/§3) -----------------------------------
        // GET is any signed-in user (a student needs the clip/image);
        // attach/clear is superadmin only. Files stream from outside the
        // web root through MediaStorage's traversal guard.
        $secure->get('/media/{name}', [\Dana\Http\Controllers\MediaController::class, 'serve']);
        $secure->post('/manage/media/{questionId}/{part}', [\Dana\Http\Controllers\MediaController::class, 'upload']);
        $secure->delete('/manage/media/{questionId}/{part}', [\Dana\Http\Controllers\MediaController::class, 'delete']);

        // FR-14.3: the superadmin forces a fresh fixed quiz draw.
        $secure->post('/manage/quiz/{childUnitId}/redraw', [\Dana\Http\Controllers\QuizController::class, 'redraw']);
        $secure->get('/manage/classrooms/{id}/export', [ManagementController::class, 'exportClassroom']);
        // FR-1.14 — irreversible purge, guarded by a name confirmation.
        $secure->post('/manage/classrooms/{id}/close', [ManagementController::class, 'closeClassroom']);
    })->add($auth);
});

$app->run();
