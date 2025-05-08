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
            // Core Positions with Specific Categories
            ['name' => 'P',     'category' => 'PITCHER', 'is_editable' => false],
            ['name' => 'C',     'category' => 'CATCHER', 'is_editable' => false],

            // Infield Positions (Category: INF)
            ['name' => '1B',    'category' => 'INF',     'is_editable' => false],
            ['name' => '2B',    'category' => 'INF',     'is_editable' => false],
            ['name' => '3B',    'category' => 'INF',     'is_editable' => false],
            ['name' => 'SS',    'category' => 'INF',     'is_editable' => false],

            // Outfield Positions (Category: OF)
            ['name' => 'LF',    'category' => 'OF',      'is_editable' => false],
            ['name' => 'CF',    'category' => 'OF',      'is_editable' => false],
            ['name' => 'RF',    'category' => 'OF',      'is_editable' => false],

            // Potential Softball/Custom Positions (Assign INF or OF as appropriate)
            // Assigning BF & SF to OF as per common softball usage, but admin can change later if needed
            ['name' => 'BF',    'category' => 'OF',      'is_editable' => true], // Buck Short / Rover often plays shallow OF
            ['name' => 'SF',    'category' => 'OF',      'is_editable' => true], // Short Fielder plays shallow OF

            // Special / Non-Playing Designations
            ['name' => 'OUT',   'category' => 'SPECIAL', 'is_editable' => false], // Cannot be edited/deleted
            ['name' => 'BENCH', 'category' => 'SPECIAL', 'is_editable' => true],  // If needed for explicit bench assignment
            ['name' => 'DH',    'category' => 'SPECIAL', 'is_editable' => true],  // Designated Hitter
            ['name' => 'EH',    'category' => 'SPECIAL', 'is_editable' => true],  // Extra Hitter
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
