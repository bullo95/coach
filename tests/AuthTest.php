<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;

final class AuthTest extends DatabaseTestCase
{
    public function testPasswordCheck(): void
    {
        $id = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');

        self::assertSame($id, Auth::attempt($this->db, ' A@B.fr ', 'motdepasse-long'));
        self::assertNull(Auth::attempt($this->db, 'a@b.fr', 'mauvais-mot-de-passe'));
        self::assertNull(Auth::attempt($this->db, 'autre@b.fr', 'motdepasse-long'));
    }

    public function testSingleAccountOnly(): void
    {
        Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
        $this->expectException(\RuntimeException::class);
        Auth::createUser($this->db, 'c@d.fr', 'motdepasse-long', 'Europe/Paris');
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Auth::createUser($this->db, 'a@b.fr', 'court', 'Europe/Paris');
    }

    public function testCreatesDefaultSettings(): void
    {
        $id = Auth::createUser($this->db, 'a@b.fr', 'motdepasse-long', 'Europe/Paris');
        $settings = $this->db->query("SELECT * FROM settings WHERE user_id = $id")->fetch();

        self::assertSame(1.5, (float) $settings['protein_coef']);
        self::assertSame(1, (int) $settings['pushups_light_day_enabled']);
        self::assertSame(3, (int) $settings['pushups_jokers_per_month']);
    }
}
