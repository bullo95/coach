<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PDO;

/**
 * Déclaration des routes de l'API.
 */
final class App
{
    public static function router(PDO $db): Router
    {
        $r = new Router();
        $program = new Program($db);

        $r->get('/api/health', static fn () => Response::json(['ok' => true]), public: true);

        $r->post('/api/login', static function (Request $req) use ($db): Response {
            if (!$req->isJson()) {
                throw new HttpError(415, 'Requête JSON attendue');
            }
            $data = $req->json();
            $id = Auth::attempt($db, (string) ($data['email'] ?? ''), (string) ($data['password'] ?? ''));
            if ($id === null) {
                usleep(800_000); // ralentit les essais répétés
                throw new HttpError(401, 'E-mail ou mot de passe incorrect');
            }
            Auth::login($id);

            return Response::json(self::me($db, $id));
        }, public: true);

        $r->post('/api/logout', static function (): Response {
            Auth::logout();

            return Response::noContent();
        }, public: true);

        $r->get('/api/me', static fn () => Response::json(self::me($db, (int) Auth::userId())));

        $r->get('/api/program', static fn () => Response::json($program->templates()));

        $r->get('/api/exercises', static fn () => Response::json($program->exercises()));

        $r->get('/api/exercises/{slug}', static function (Request $req) use ($program): Response {
            $ex = $program->exercise($req->params['slug']);
            if ($ex === null) {
                throw new HttpError(404, 'Exercice introuvable');
            }

            return Response::json($ex);
        });

        // Lot 2 : séances.
        $uid = static fn (): int => (int) Auth::userId();

        $r->get('/api/today', static fn () => Response::json((new Planner($db, $uid()))->today()));

        $r->get('/api/loads', static fn () => Response::json((object) (new Workouts($db, $uid()))->loads()));

        $r->post('/api/sync', static function (Request $req) use ($db, $uid): Response {
            $events = $req->json()['events'] ?? null;
            if (!\is_array($events) || !array_is_list($events) || \count($events) > 500) {
                throw new HttpError(400, 'Liste d\'événements attendue (500 au plus)');
            }

            return Response::json((new Workouts($db, $uid()))->sync($events));
        });

        $r->get('/api/sessions', static fn () => Response::json((new Workouts($db, $uid()))->history()));

        $r->get('/api/sessions/{id}', static function (Request $req) use ($db, $uid): Response {
            $s = (new Workouts($db, $uid()))->session((int) $req->params['id']);
            if ($s === null) {
                throw new HttpError(404, 'Séance introuvable');
            }

            return Response::json($s);
        });

        // Joker sur la séance du jour (quota commun avec les pompes).
        $r->post('/api/today/joker', static function () use ($db, $uid): Response {
            $p = new Planner($db, $uid());
            $p->skipToday();

            return Response::json($p->today());
        });

        $r->post('/api/today/joker/cancel', static function () use ($db, $uid): Response {
            $p = new Planner($db, $uid());
            $p->cancelSkipToday();

            return Response::json($p->today());
        });

        // Pompes quotidiennes.
        $r->get('/api/encouragements', static fn () => new Response(
            200,
            (string) file_get_contents(Config::rootDir() . '/data/encouragements.json'),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        ));

        $r->get('/api/pushups', static fn () => Response::json((new Pushups($db, $uid()))->state()));

        $r->get('/api/pushups/history', static fn () => Response::json((new Pushups($db, $uid()))->history()));

        $r->post('/api/pushups/joker', static function (Request $req) use ($db, $uid): Response {
            $p = new Pushups($db, $uid());
            $p->useJoker(($req->json()['day'] ?? 'today') === 'yesterday' ? 'yesterday' : 'today');

            return Response::json($p->state());
        });

        // Coach des pompes : programme, ressenti, conseil.
        $r->post('/api/pushups/plan', static function (Request $req) use ($db, $uid): Response {
            $p = new Pushups($db, $uid());
            $p->setPlan($req->json());

            return Response::json($p->state());
        });

        $r->get('/api/pushups/plan/history', static fn () => Response::json((new Pushups($db, $uid()))->planHistory()));

        $r->post('/api/pushups/feedback', static function (Request $req) use ($db, $uid): Response {
            $data = $req->json();
            $p = new Pushups($db, $uid());
            $p->saveFeedback($data['effort'] ?? null, isset($data['pain_area']) && $data['pain_area'] !== '' ? (string) $data['pain_area'] : null);

            return Response::json($p->state());
        });

        $r->post('/api/pushups/coach/{decision}', static function (Request $req) use ($db, $uid): Response {
            $p = new Pushups($db, $uid());
            match ($req->params['decision']) {
                'accept' => $p->acceptAdvice(),
                'decline' => $p->declineAdvice(),
                default => throw new HttpError(404, 'Introuvable'),
            };

            return Response::json($p->state());
        });

        $r->post('/api/pushups/undo', static function () use ($db, $uid): Response {
            $p = new Pushups($db, $uid());
            $p->undoLast();

            return Response::json($p->state());
        });

        return $r;
    }

    public static function handle(Request $req, PDO $db): Response
    {
        try {
            return self::router($db)->dispatch($req, static function (Request $r): void {
                Auth::requireUser($r);
            });
        } catch (HttpError $e) {
            return Response::json(['error' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            error_log((string) $e);

            return Response::json(['error' => Config::isDev() ? $e->getMessage() : 'Erreur serveur'], 500);
        }
    }

    /** @return array<string, mixed> */
    private static function me(PDO $db, int $userId): array
    {
        $stmt = $db->prepare('SELECT id, email, timezone FROM user WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Auth::logout();
            throw new HttpError(401, 'Connexion requise');
        }
        $stmt = $db->prepare('SELECT * FROM settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $settings = $stmt->fetch() ?: [];
        if (isset($settings['session_days'])) {
            $settings['session_days'] = json_decode($settings['session_days'], true);
        }
        unset($settings['user_id']);

        return ['user' => $user, 'settings' => $settings];
    }
}
