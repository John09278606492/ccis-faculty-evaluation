<?php

// The controlled letterhead of F-AQA-INS-003. Forms only change the lines below the letterhead.
return [
    'letterhead' => [
        'republic' => 'Republic of the Philippines',
        'school' => 'BOHOL ISLAND STATE UNIVERSITY',
        'address' => 'Magsija, Balilihan, 6342, Bohol, Philippines',
        'office' => 'Office of the College of Computing and Information Sciences',
        'core_values' => 'Balance I Integrity I Stewardship I Uprightness',
        // Paths under public/. A logo whose file is missing is left out of the printout.
        'logo' => 'images/bisu-logo.png',
        'bagong_pilipinas_logo' => 'images/acknowledgements/bagong-pilipinas.png',
        'certification_logo' => 'images/acknowledgements/tuv-certification.jpg',
    ],

    'footer' => 'F-AQA-INS-003  |  Rev. 2  |  07/01/24',

    'defaults' => [
        'document_title' => "Learner's Acknowledgement Receipt",
        'college_name' => 'College of Computing and Information Sciences',
        'department_name' => 'Computing and Information Sciences Department',
        'instructor_designation' => 'Instructor',
    ],
];
