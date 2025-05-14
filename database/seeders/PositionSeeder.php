<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Position; // Use the model

class PositionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Using updateOrCreate to find by name and update/create attributes.
        // This makes the seeder safe to re-run.
        $positions = [
            // Core Positions
            ['name' => 'P',  'display_name' => 'Pitcher',        'category' => 'PITCHER', 'is_editable' => false],
            ['name' => 'C',  'display_name' => 'Catcher',        'category' => 'CATCHER', 'is_editable' => false],
            ['name' => '1B', 'display_name' => 'First Base',     'category' => 'INF',     'is_editable' => false],
            ['name' => '2B', 'display_name' => 'Second Base',    'category' => 'INF',     'is_editable' => false],
            ['name' => '3B', 'display_name' => 'Third Base',     'category' => 'INF',     'is_editable' => false],
            ['name' => 'SS', 'display_name' => 'Shortstop',      'category' => 'INF',     'is_editable' => false],
            ['name' => 'LF', 'display_name' => 'Left Field',     'category' => 'OF',      'is_editable' => false],
            ['name' => 'CF', 'display_name' => 'Center Field',   'category' => 'OF',      'is_editable' => false],
            ['name' => 'RF', 'display_name' => 'Right Field',    'category' => 'OF',      'is_editable' => false],

            // Softball/Custom Positions
            ['name' => 'BF', 'display_name' => 'Buck Short',     'category' => 'OF',      'is_editable' => true],
            ['name' => 'SF', 'display_name' => 'Short Fielder',  'category' => 'OF',      'is_editable' => true],

            // Special Designations
            ['name' => 'OUT',   'display_name' => 'Out',            'category' => 'SPECIAL', 'is_editable' => false],
            ['name' => 'BENCH', 'display_name' => 'Bench',          'category' => 'SPECIAL', 'is_editable' => true],
            ['name' => 'DH',    'display_name' => 'Des. Hitter',    'category' => 'SPECIAL', 'is_editable' => true],
            ['name' => 'EH',    'display_name' => 'Extra Hitter',   'category' => 'SPECIAL', 'is_editable' => true],
        ];

        $this->command->info('Seeding/Updating Positions...');
        $count = 0;
        foreach ($positions as $position) {
             Position::updateOrCreate(
                ['name' => $position['name']], // Find by unique name
                $position                     // Update or create with these attributes
            );
            $count++;
        }
         $this->command->info("Processed {$count} positions.");
    }
}
