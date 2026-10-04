<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reminder harian 08:00 Asia/Jakarta.
Schedule::command('logbook:send-reminders')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Pengingat mahasiswa tidak aktif bimbingan > 3 minggu (email, CC pembimbing 1).
Schedule::command('ta:notify-inactive')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Notifikasi langganan direktori yang akan berakhir (H-7 & H-1) atau baru berakhir.
Schedule::command('directory:notify-expiring-subscriptions')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Notifikasi proaktif kuota penyimpanan mendekati limit (80% & 95%).
Schedule::command('storage:notify-near-limit')
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Weekly digest (setiap Senin 07:00).
Schedule::command('ta:weekly-digest')
    ->weeklyOn(1, '07:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Bersihkan file lampiran orphan (mingguan, buffer 30 hari).
Schedule::command('files:prune-orphans')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Bersihkan personal access token yang kedaluwarsa.
Schedule::command('sanctum:prune-expired')
    ->daily()
    ->timezone('Asia/Jakarta');

// Hapus mahasiswa active yang tidak verified dalam 1 bulan (harian 03:30).
Schedule::command('students:delete-inactive')
    ->dailyAt('03:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Bersihkan akun yang tidak pernah verifikasi email (>7 hari) siang (harian 04:00).
Schedule::command('users:delete-unverified')
    ->dailyAt('04:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Ingatkan dosen mengisi nilai seminar/sidang yang sudah berlangsung (harian 10:00).
Schedule::command('sidang:remind-grading')
    ->dailyAt('10:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

// Digest pesan chat belum dibaca (H+2) + eskalasi (H+7), per jam.
Schedule::command('chat:send-pending-digests')
    ->hourly()
    ->withoutOverlapping();
