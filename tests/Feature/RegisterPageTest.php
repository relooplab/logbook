<?php

namespace Tests\Feature;

use Tests\TestCase;

class RegisterPageTest extends TestCase
{
    public function test_register_page_shows_role_cards_with_concise_descriptions(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('data-role="mahasiswa"', false)
            ->assertSee('data-role="dosen"', false)
            ->assertSee('Catat logbook bimbingan TA/KP', false)
            ->assertSee('Bimbing &amp; nilai mahasiswa', false);
    }

    public function test_register_page_keeps_hidden_role_field_and_plain_submit_button(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('id="role-input" value="mahasiswa"', false)
            ->assertSee('>Daftar</button>', false);
    }

    public function test_register_page_hints_identity_filled_after_verification(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk()
            ->assertSee('Setelah verifikasi email, isi NIM dan pilih dosen pembimbing Anda.', false);
    }
}