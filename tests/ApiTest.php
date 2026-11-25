<?php

declare(strict_types=1);

namespace App\Tests;

use App\App;
use App\Auth;
use App\Http\Request;

final class ApiTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testHealthIsPublic(): void
    {
        $res = App::handle(new Request('GET', '/api/health'), $this->db);
        self::assertSame(200, $res->status);
    }

    public function testProgramRequiresLogin(): void
    {
        $res = App::handle(new Request('GET', '/api/program'), $this->db);
        self::assertSame(401, $res->status);
    }

    public function testLoggedInUserReadsProgramAndSettings(): void
    {
        $id = Auth::createUser($this->db, 'Bruno@Example.fr', 'motdepasse-long', 'Europe/Paris');
        $_SESSION['user_id'] = $id;

        $program = json_decode(App::handle(new Request('GET', '/api/program'), $this->db)->body, true);
        self::assertSame(['A', 'B', 'C'], array_column($program, 'code'));

        $me = json_decode(App::handle(new Request('GET', '/api/me'), $this->db)->body, true);
        self::assertSame('bruno@example.fr', $me['user']['email']);
        self::assertSame(23, $me['settings']['pushups_reps_per_set']);
        self::assertSame(10, $me['settings']['pushups_sets']);
        self::assertSame([1, 3, 5], $me['settings']['session_days']);
    }

    public function testExerciseCard(): void
    {
        $_SESSION['user_id'] = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');

        $res = App::handle(new Request('GET', '/api/exercises/squat-gobelet'), $this->db);
        $ex = json_decode($res->body, true);
        self::assertSame('Squat gobelet', $ex['name_fr']);
        self::assertSame('squat-banc', $ex['easier_variant']['slug']);

        self::assertSame(404, App::handle(new Request('GET', '/api/exercises/inconnu'), $this->db)->status);
    }

    public function testWritesMustBeJson(): void
    {
        $_SESSION['user_id'] = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
        $res = App::handle(new Request('POST', '/api/login', [], 'email=a', ['content-type' => 'application/x-www-form-urlencoded']), $this->db);
        self::assertSame(415, $res->status);
    }

    public function testUnknownRouteAndWrongMethod(): void
    {
        self::assertSame(404, App::handle(new Request('GET', '/api/nope'), $this->db)->status);
        self::assertSame(405, App::handle(new Request('POST', '/api/health', [], '{}', ['content-type' => 'application/json']), $this->db)->status);
    }
}
