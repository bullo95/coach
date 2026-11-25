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
