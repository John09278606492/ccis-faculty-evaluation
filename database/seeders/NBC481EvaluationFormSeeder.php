<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\FormField;
use Illuminate\Database\Seeder;

class NBC481EvaluationFormSeeder extends Seeder
{
    /**
     * NBC No. 481 - Instrument for Instruction/Teaching Effectiveness
     * Creates a sample faculty evaluation form following the QCE format.
     */
    public function run(): void
    {
        // Note: You need to set the campus_id based on your setup
        $campusId = 1; // Change this to your campus ID

        $form = Form::create([
            'campus_id' => $campusId,
            'name' => 'QCE NBC No. 481 - Instrument for Instruction/Teaching Effectiveness',
            'slug' => 'qce-nbc-481-faculty-evaluation',
            'description' => 'Please evaluate the faculty using the scale below. Encircle your rating.',
            'is_active' => true,
            'requires_login' => true,
            'evaluator_roles' => ['student'], // Only students can use this form
        ]);

        $order = 0;

        // Section A: Commitment
        $sectionA = 'A. Commitment';
        $this->createField($form, $order++, $sectionA, 'Demonstrates sensitivity to students\' ability to attend and absorb content information.');
        $this->createField($form, $order++, $sectionA, 'Integrates sensitively his/her learning objectives with those of the students in a collaborative process.');
        $this->createField($form, $order++, $sectionA, 'Makes self available to students beyond official time.');
        $this->createField($form, $order++, $sectionA, 'Regularly comes to class on time, well-groomed and well-prepared to complete assigned responsibilities.');
        $this->createField($form, $order++, $sectionA, 'Keeps accurate records of students\' performance and prompt submission of the same.');

        // Section B: Knowledge of Subject
        $sectionB = 'B. Knowledge of Subject';
        $this->createField($form, $order++, $sectionB, 'Demonstrates mastery of the subject matter (explain the subject matter without relying solely on the prescribed textbook).');
        $this->createField($form, $order++, $sectionB, 'Draws and share information on the state of the art of theory and practice his/her discipline.');
        $this->createField($form, $order++, $sectionB, 'Integrates subject to practical circumstances and learning intents/purposes of students.');
        $this->createField($form, $order++, $sectionB, 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.');
        $this->createField($form, $order++, $sectionB, 'Demonstrate up-to-date knowledge and/or awareness on current trends and issues of the subject.');

        // Section C: Teaching for Independent Learning
        $sectionC = 'C. Teaching for Independent Learning';
        $this->createField($form, $order++, $sectionC, 'Creates teaching strategies that allows students to practice using concepts they need to understand (interactive discussion).');
        $this->createField($form, $order++, $sectionC, 'Enhances student self-esteem and/or gives dues recognition to students\' performance/potentials.');
        $this->createField($form, $order++, $sectionC, 'Allows students to create their own course with objectives and realistically defined student-professor rules and make them accountable for their performance.');
        $this->createField($form, $order++, $sectionC, 'Allows students to think independently and make their own decisions and holding them accountable for their performance based largely on their success in executing decisions.');
        $this->createField($form, $order++, $sectionC, 'Encourages students to learn beyond what is required and help/guide the students how to apply the concepts learned.');

        // Section D: Management of Learning
        $sectionD = 'D. Management of Learning';
        $this->createField($form, $order++, $sectionD, 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).');
        $this->createField($form, $order++, $sectionD, 'Assumes roles as facilitator, resource person, coach, inquisitor, integrator, referee in drawing students to contribute to knowledge and understanding of the concepts at hands.');
        $this->createField($form, $order++, $sectionD, 'Designs and implements learning conditions and experience that promotes health exchange and/or confrontations.');
        $this->createField($form, $order++, $sectionD, 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.');
        $this->createField($form, $order++, $sectionD, 'Use of Instructional Materials (audio/video materials: fieldtrips, film showing, computer aided instruction, etc) to reinforce learning processes.');

        // Additional Comments (Optional)
        FormField::create([
            'form_id' => $form->id,
            'section' => 'Comments',
            'name' => 'additional_comments',
            'label' => 'Additional Comments or Suggestions (Optional)',
            'type' => 'textarea',
            'placeholder' => 'Please share any additional feedback about the instructor...',
            'is_required' => false,
            'order' => $order,
        ]);

        $this->command->info('NBC 481 Evaluation Form created successfully!');
    }

    /**
     * Helper to create a likert_scale field.
     */
    private function createField(Form $form, int $order, string $section, string $label): void
    {
        FormField::create([
            'form_id' => $form->id,
            'section' => $section,
            'name' => 'q' . ($order + 1),
            'label' => $label,
            'type' => 'likert_scale',
            'is_required' => true,
            'order' => $order,
        ]);
    }
}
