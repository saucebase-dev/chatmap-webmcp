<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.site_name')) {
            return;
        }

        $this->migrator->update(
            'general.site_name',
            fn (mixed $siteName): mixed => $siteName === 'Whatsthere' ? 'Wayfinder' : $siteName,
        );
    }
};
