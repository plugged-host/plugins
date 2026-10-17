<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sponsored banners (PluggedHost\Ads\Models\AdBanner): banners the owner sells himself and
 * uploads in Admin › Sponsored banners. The images live on the public disk under
 * plugged-host-ads/banners/; this holds where each goes, its window and its clicks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugged_host_ad_banners', function (Blueprint $table) {
            // bigint primary key: the database is a Galera cluster, every table needs one.
            $table->id();
            // On the public disk, e.g. plugged-host-ads/banners/<random>.png.
            $table->string('image_path');
            // The image's own pixels, read from the file on save.
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            // Where a click goes (https only); reached through /ads/b/{id}, which counts it.
            $table->string('url', 2048);
            // Who bought it. Public: the image's alt text reads "Sponsored: <advertiser>".
            $table->string('advertiser', 100);
            // slots (in place of the Adsterra unit of its size) or top (above every page heading).
            $table->string('placement', 16)->default('slots');
            // Optional window; outside it the banner is not shown. dateTime, not timestamp, so an
            // end date past 2038 is storable.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugged_host_ad_banners');
    }
};
