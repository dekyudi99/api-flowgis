<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class CctvStation extends Model
{
    use HasFactory;

    protected $table = 'cctv_stations';

    protected $fillable = [
        'station_code',
        'name',
        'location',
        'stream_url',
        'snapshot_url',
        'username',
        'password',
        'lat',
        'lng',
        'is_active',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Memastikan tabel cctv_stations terbuat dan diisi default jika belum ada di database
     */
    public static function ensureTableAndDefaults(): void
    {
        if (!Schema::hasTable('cctv_stations')) {
            Schema::create('cctv_stations', function (Blueprint $table) {
                $table->id();
                $table->string('station_code', 50)->unique();
                $table->string('name', 255);
                $table->text('location')->nullable();
                $table->text('stream_url');
                $table->text('snapshot_url')->nullable();
                $table->string('username')->default('live');
                $table->string('password')->default('Live2025!');
                $table->decimal('lat', 10, 6)->nullable();
                $table->decimal('lng', 10, 6)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Seed data awal jika tabel masih kosong
        if (self::count() === 0) {
            $defaultStations = [
                [
                    'station_code' => 'TA130204',
                    'name'         => 'แม่น้ำท่าจีนที่สะพานบุญรัตน์ประชานุวัฒน์ (Bunyarat Prachanuwat Bridge)',
                    'location'     => 'จ.นครปฐม อ.สามพราน ต.สามพราน (Sam Phran, Nakhon Pathom)',
                    'stream_url'   => env('CCTV_URL_STATION_1', 'http://ta130204.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
                    'username'     => env('CCTV_USERNAME', 'live'),
                    'password'     => env('CCTV_PASSWORD', 'Live2025!'),
                    'lng'          => 100.2222,
                    'lat'          => 13.7889,
                    'is_active'    => true,
                ],
                [
                    'station_code' => 'TA130205',
                    'name'         => 'แม่น้ำท่าจีนที่สะพานหลวงพ่อเปิ่น (Luang Pho Poen Bridge)',
                    'location'     => 'จ.นครปฐม อ.นครชัยศรี ต.บางแก้วฟ้า (Nakhon Chai Si, Nakhon Pathom)',
                    'stream_url'   => env('CCTV_URL_STATION_2', 'http://ta130205.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
                    'username'     => env('CCTV_USERNAME', 'live'),
                    'password'     => env('CCTV_PASSWORD', 'Live2025!'),
                    'lng'          => 100.1833,
                    'lat'          => 13.8167,
                    'is_active'    => true,
                ],
                [
                    'station_code' => 'TA130206',
                    'name'         => 'แม่น้ำท่าจีนที่สะพานข้ามแม่น้ำท่าจีน (Highway 346 Bridge)',
                    'location'     => 'จ.นครปฐม อ.บางเลน ต.บางเลน (Bang Len, Nakhon Pathom)',
                    'stream_url'   => env('CCTV_URL_STATION_3', 'http://ta130206.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
                    'username'     => env('CCTV_USERNAME', 'live'),
                    'password'     => env('CCTV_PASSWORD', 'Live2025!'),
                    'lng'          => 100.0500,
                    'lat'          => 13.9833,
                    'is_active'    => true,
                ],
                [
                    'station_code' => 'TA100220',
                    'name'         => 'โรงเรียนกำแพงแสนวิทยา (Kamphaeng Saen Witthaya School)',
                    'location'     => 'จ.นครปฐม อ.กำแพงแสน ต.ทุ่งกระพังโหม (Kamphaeng Saen, Nakhon Pathom)',
                    'stream_url'   => env('CCTV_URL_STATION_4', 'http://ta100220.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
                    'username'     => env('CCTV_USERNAME', 'live'),
                    'password'     => env('CCTV_PASSWORD', 'Live2025!'),
                    'lng'          => 99.9973,
                    'lat'          => 13.9831,
                    'is_active'    => true,
                ],
            ];

            foreach ($defaultStations as $s) {
                self::create($s);
            }
        }
    }
}
