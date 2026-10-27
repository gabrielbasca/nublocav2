<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['type' => 'contact', 'body' => 'Va rog sa mutati vehiculul.'],
            ['type' => 'contact', 'body' => 'Vehiculul dumneavoastra blocheaza accesul.'],
            ['type' => 'contact', 'body' => 'Ati uitat luminile aprinse.'],
            ['type' => 'reply', 'body' => 'Multumesc pentru anunt. Ma ocup imediat.'],
            ['type' => 'reply', 'body' => 'Multumesc, ajung in cateva minute.'],
        ] as $template) {
            MessageTemplate::firstOrCreate(
                ['type' => $template['type'], 'body' => $template['body']],
                ['is_active' => true],
            );
        }
    }
}
